<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\EnquiryService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "request a callback" endpoint for a clinic's own website form.
 * Rate-limited, honeypot-protected and write-only (never returns data).
 *
 * @tags Public
 */
class PublicEnquiryController extends Controller
{
    /** @unauthenticated */
    public function store(Request $request, string $slug, EnquiryService $enquiries, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        abort_unless($tenant?->isActive(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9 ()\-]{6,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'interest' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
            'preferred_contact_time' => ['nullable', 'string', 'max:64'],
            'branch_code' => ['nullable', 'string', 'max:12'],
            'website' => ['nullable', 'max:0'], // honeypot: real users never fill this hidden field
        ]);

        $context->run($tenant, function () use ($data, $enquiries) {
            $branch = (isset($data['branch_code']) ? Branch::query()->where('code', strtoupper($data['branch_code']))->first() : null)
                ?? Branch::query()->where('is_active', true)->orderBy('id')->firstOrFail();

            $enquiries->create([
                'branch_id' => $branch->id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'interest' => $data['interest'] ?? null,
                'message' => $data['message'] ?? null,
                'preferred_contact_time' => $data['preferred_contact_time'] ?? null,
                'source' => 'website',
            ], null);
        });

        return response()->json(['message' => 'Thank you. The clinic will contact you shortly.'], 201);
    }
}
