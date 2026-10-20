<?php

namespace App\Services;

use App\Models\Patient;
use App\Services\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PatientService
{
    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function create(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $patient = new Patient($data);
            $patient->mrn = $this->sequences->next('patient_mrn', 'tenant', 'P-', 4);
            $patient->save();

            return $patient->refresh();
        });
    }

    /**
     * Enables the patient portal and returns a one-time activation token
     * (only its hash is stored). The caller delivers the link to the patient.
     */
    public function issuePortalInvite(Patient $patient, int $validHours = 72): string
    {
        $token = Str::random(48);

        $patient->forceFill([
            'portal_enabled' => true,
            'portal_invite_token_hash' => hash('sha256', $token),
            'portal_invite_expires_at' => now()->addHours($validHours),
        ])->save();

        return $token;
    }
}
