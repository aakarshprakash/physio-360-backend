<?php

namespace App\Http\Controllers\Api\Staff;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsentSignatureResource;
use App\Http\Resources\ConsentTemplateResource;
use App\Http\Resources\DocumentResource;
use App\Models\ConsentSignature;
use App\Models\ConsentTemplate;
use App\Models\Document;
use App\Models\Patient;
use App\Services\Clinical\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patient files (reports, imaging, referrals) and e-signed consent forms.
 * Files live on the private disk and are only streamed after authorisation.
 *
 * @tags Staff · Documents & consent
 */
class DocumentController extends Controller
{
    public const MAX_UPLOAD_KB = 20480; // 20 MB

    public const CATEGORIES = ['report', 'imaging', 'referral', 'prescription', 'insurance', 'consent', 'other'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return DocumentResource::collection(
            Document::query()->where('patient_id', $patient->id)->with('uploader:id,name')->latest()->get()
        );
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
            'category' => ['required', Rule::in(DocumentController::CATEGORIES)],
        ]);

        $file = $data['file'];
        $path = $file->storeAs(
            'tenants/'.$patient->tenant_id.'/patients/'.$patient->id,
            Str::uuid().'.'.$file->extension(),
            config('filesystems.private_disk'),
        );

        $document = Document::query()->create([
            'patient_id' => $patient->id,
            'uploaded_by' => $request->user()->id,
            'category' => $data['category'],
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => $path,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);

        return (new DocumentResource($document->load('uploader:id,name')))->response()->setStatusCode(201);
    }

    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('viewClinical', $document->patient);
        $this->audit->record('downloaded', $document);

        return Storage::disk(config('filesystems.private_disk'))->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroy(Document $document): JsonResponse
    {
        Gate::authorize('update', $document->patient);
        abort_if($document->category === 'consent', 422, 'Consent documents cannot be deleted.');

        Storage::disk(config('filesystems.private_disk'))->delete($document->path);
        $document->delete();

        return response()->json(null, 204);
    }

    /** Signed consents plus active templates the patient has not yet signed. */
    public function consents(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $signed = ConsentSignature::query()->where('patient_id', $patient->id)->with('witness:id,name')->latest('signed_at')->get();
        $signedVersions = $signed->map(fn ($s) => $s->consent_template_id.':'.$s->template_version)->all();

        $pending = ConsentTemplate::query()->where('is_active', true)->get()
            ->reject(fn ($t) => in_array($t->id.':'.$t->version, $signedVersions, true))
            ->values();

        return response()->json(['data' => [
            'signed' => ConsentSignatureResource::collection($signed),
            'pending' => ConsentTemplateResource::collection($pending),
        ]]);
    }

    /** Sign in clinic on a tablet; the staff member is recorded as witness. */
    public function signConsent(Request $request, Patient $patient, ConsentService $consents): JsonResponse
    {
        Gate::authorize('update', $patient);

        $data = $request->validate([
            'consent_template_id' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:800000'],
            'signer_name' => ['required', 'string', 'max:128'],
            'signer_relationship' => ['required', 'in:self,parent,guardian,spouse,other'],
        ]);

        $signature = $consents->sign(
            $patient,
            ConsentTemplate::query()->findOrFail($data['consent_template_id']),
            $data['signature'],
            $data['signer_name'],
            $data['signer_relationship'],
            'clinic',
            $request->user()->id,
            $request,
        );

        return (new ConsentSignatureResource($signature->load('witness:id,name')))->response()->setStatusCode(201);
    }

    public function consentSignature(ConsentSignature $signature): StreamedResponse
    {
        Gate::authorize('view', $signature->patient);

        return Storage::disk(config('filesystems.private_disk'))->response($signature->signature_path, 'signature.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
