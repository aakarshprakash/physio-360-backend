<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageTemplateResource;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Services\Messaging\MessageComposer;
use App\Services\Messaging\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Patient messaging (SMS, WhatsApp, email): log, compose and templates.
 *
 * @tags Staff · Messaging
 */
class MessageController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,mrn', 'invoice:id,number', 'sender:id,name'];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly MessageComposer $composer,
    ) {}

    /** Communication log with 30-day delivery figures and channel readiness. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('messages.send');
        $request->validate([
            'channel' => ['nullable', Rule::enum(MessageChannel::class)],
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            'purpose' => ['nullable', Rule::in(MessageTemplate::PURPOSES)],
            'patient_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $branchIds = $user->accessibleBranchIds();
        $base = fn () => Message::query()->when($branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $branchIds)->orWhereNull('branch_id')));

        $page = $base()
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->input('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->input('purpose')))
            ->when($request->filled('patient_id'), fn ($q) => $q->where('patient_id', $request->integer('patient_id')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('patient', fn ($p) => $p->search($request->input('search'))))
            ->with(self::RELATIONS)
            ->latest('created_at')->latest('id')
            ->paginate(25);

        $counts = $base()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return MessageResource::collection($page)->additional([
            'summary' => [
                'sent_30d' => (int) (($counts['sent'] ?? 0) + ($counts['delivered'] ?? 0)),
                'manual_30d' => (int) ($counts['manual'] ?? 0),
                'failed_30d' => (int) ($counts['failed'] ?? 0),
                'queued' => (int) ($counts['queued'] ?? 0),
            ],
            'channels' => $this->channels($user->tenant),
        ]);
    }

    /**
     * Send one message to one or more patients. The body (or template) may use
     * {{placeholders}}; it is rendered per patient. Patients without a contact
     * for the channel are returned in "skipped".
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('messages.send');
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'patient_ids' => ['required', 'array', 'min:1', 'max:200'],
            'patient_ids.*' => ['integer', 'distinct'],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('tenant_id', $tenantId)],
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:190'],
            'purpose' => ['nullable', Rule::in(MessageTemplate::PURPOSES)],
        ]);

        $user = $request->user();
        $channel = MessageChannel::from($data['channel']);
        $template = isset($data['template_id']) ? MessageTemplate::query()->find($data['template_id']) : null;
        $body = $data['body'] ?? $template->body;
        $subject = $data['subject'] ?? $template?->subject;

        $patients = Patient::query()->visibleTo($user)->whereIn('id', $data['patient_ids'])->get();
        $sent = [];
        $skipped = collect($data['patient_ids'])->diff($patients->pluck('id'))
            ->map(fn ($id) => ['patient_id' => $id, 'name' => null, 'reason' => 'not_found'])->values()->all();

        foreach ($patients as $patient) {
            $vars = $this->composer->variables($user->tenant, $patient);
            try {
                $sent[] = $this->messenger->send([
                    'channel' => $channel,
                    'purpose' => $data['purpose'] ?? $template?->purpose ?? 'general',
                    'patient' => $patient,
                    'template_id' => $template?->id,
                    'subject' => $subject ? $this->composer->render($subject, $vars) : null,
                    'body' => $this->composer->render($body, $vars),
                    'sent_by' => $user->id,
                ]);
            } catch (DomainRuleViolation $e) {
                $skipped[] = ['patient_id' => $patient->id, 'name' => $patient->full_name, 'reason' => $e->errorCode];
            }
        }

        return response()->json([
            'data' => MessageResource::collection(collect($sent)->map->load(self::RELATIONS)),
            'skipped' => $skipped,
        ], 201);
    }

    /** Render a template or text for one patient without sending. */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('messages.send');
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'patient_id' => ['nullable', 'integer'],
        ]);

        $patient = isset($data['patient_id']) ? Patient::query()->visibleTo($request->user())->find($data['patient_id']) : null;

        return response()->json(['data' => [
            'body' => $this->composer->render($data['body'], $this->composer->variables($request->user()->tenant, $patient)),
            'length' => mb_strlen($data['body']),
        ]]);
    }

    public function templates(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('messages.send');

        return MessageTemplateResource::collection(MessageTemplate::query()->orderBy('purpose')->orderBy('channel')->orderBy('name')->get())
            ->additional(['placeholders' => MessageComposer::PLACEHOLDERS, 'channels' => $this->channels($request->user()->tenant)]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');
        $template = MessageTemplate::query()->create($this->templateData($request));
        $this->keepSingleDefault($template);

        return (new MessageTemplateResource($template))->response()->setStatusCode(201);
    }

    public function updateTemplate(Request $request, MessageTemplate $template): MessageTemplateResource
    {
        Gate::authorize('settings.manage');
        $template->update($this->templateData($request, $template));
        $this->keepSingleDefault($template);

        return new MessageTemplateResource($template);
    }

    /** @return array{sms: bool, whatsapp: bool, email: bool} */
    private function channels($tenant): array
    {
        return collect(MessageChannel::cases())->mapWithKeys(fn ($c) => [$c->value => $this->messenger->canDeliver($tenant, $c)])->all();
    }

    private function templateData(Request $request, ?MessageTemplate $template = null): array
    {
        $required = $template ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'purpose' => [$required, Rule::in(MessageTemplate::PURPOSES)],
            'channel' => [$required, Rule::enum(MessageChannel::class)],
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => [$required, 'string', 'max:2000'],
            'locale' => ['sometimes', 'in:en,ar,ml'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** One default per purpose + channel + language. */
    private function keepSingleDefault(MessageTemplate $template): void
    {
        if ($template->is_default) {
            MessageTemplate::query()->whereKeyNot($template->id)
                ->where('purpose', $template->purpose)->where('channel', $template->channel)->where('locale', $template->locale)
                ->update(['is_default' => false]);
        }
    }
}
