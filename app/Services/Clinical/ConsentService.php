<?php

namespace App\Services\Clinical;

use App\Exceptions\DomainRuleViolation;
use App\Models\ConsentSignature;
use App\Models\ConsentTemplate;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E-signed consent: stores the drawn signature image privately, snapshots the
 * exact text that was shown, and records a SHA-256 over text + signer + image
 * so tampering is detectable. Rows are append-only.
 */
class ConsentService
{
    private const MAX_SIGNATURE_BYTES = 512 * 1024;

    public function sign(
        Patient $patient,
        ConsentTemplate $template,
        string $signatureDataUrl,
        string $signerName,
        string $relationship,
        string $via,
        ?int $witnessId,
        Request $request,
    ): ConsentSignature {
        if (! $template->is_active) {
            throw new DomainRuleViolation('This consent form is no longer in use.', 'inactive_template');
        }

        $png = $this->decodePng($signatureDataUrl);
        $disk = Storage::disk(config('filesystems.private_disk'));
        $path = 'tenants/'.$patient->tenant_id.'/consents/'.$patient->id.'/'.Str::uuid().'.png';
        $disk->put($path, $png);

        return ConsentSignature::query()->create([
            'patient_id' => $patient->id,
            'consent_template_id' => $template->id,
            'template_version' => $template->version,
            'title' => $template->title,
            'body_snapshot' => $template->body,
            'content_hash' => hash('sha256', implode('|', [$template->body, $signerName, $relationship, hash('sha256', $png)])),
            'signer_name' => $signerName,
            'signer_relationship' => $relationship,
            'signature_path' => $path,
            'signed_via' => $via,
            'witnessed_by' => $witnessId,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'signed_at' => now(),
        ]);
    }

    private function decodePng(string $dataUrl): string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new DomainRuleViolation('Signature must be a PNG image.', 'invalid_signature');
        }

        $binary = base64_decode(substr($dataUrl, 22), true);

        if ($binary === false || strlen($binary) < 100 || strlen($binary) > self::MAX_SIGNATURE_BYTES
            || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new DomainRuleViolation('The signature image is invalid or too large.', 'invalid_signature');
        }

        return $binary;
    }
}
