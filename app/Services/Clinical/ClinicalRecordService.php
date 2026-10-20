<?php

namespace App\Services\Clinical;

use App\Enums\NoteStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAddendum;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signing makes a note or assessment permanent: the model refuses further
 * writes and MySQL triggers reject UPDATE/DELETE. Corrections are addenda.
 */
class ClinicalRecordService
{
    public function signNote(ClinicalNote $note, User $signer): ClinicalNote
    {
        return DB::transaction(function () use ($note, $signer) {
            /** @var ClinicalNote $locked */
            $locked = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSigned()) {
                throw new DomainRuleViolation('This note is already signed.', 'already_signed');
            }
            if ($locked->author_id !== $signer->id) {
                throw new DomainRuleViolation('Only the author can sign a note.', 'not_author');
            }
            if (blank($locked->subjective) && blank($locked->objective) && blank($locked->assessment) && blank($locked->plan)) {
                throw new DomainRuleViolation('An empty note cannot be signed.', 'empty_note');
            }

            $locked->forceFill([
                'status' => NoteStatus::Signed,
                'signed_at' => now(),
                'signed_by' => $signer->id,
                'content_hash' => $locked->computeHash(),
            ])->save();

            return $locked;
        });
    }

    public function addAddendum(ClinicalNote $note, User $author, string $body): ClinicalNoteAddendum
    {
        if (! $note->isSigned()) {
            throw new DomainRuleViolation('Edit the draft directly; addenda are for signed notes.', 'not_signed');
        }

        return ClinicalNoteAddendum::query()->create([
            'clinical_note_id' => $note->id,
            'author_id' => $author->id,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    public function signAssessment(Assessment $assessment, User $signer): Assessment
    {
        return DB::transaction(function () use ($assessment, $signer) {
            /** @var Assessment $locked */
            $locked = Assessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSigned()) {
                throw new DomainRuleViolation('This assessment is already signed.', 'already_signed');
            }
            if ($locked->practitioner_id !== $signer->id) {
                throw new DomainRuleViolation('Only the assessing practitioner can sign.', 'not_author');
            }

            $locked->forceFill([
                'status' => NoteStatus::Signed,
                'signed_at' => now(),
                'signed_by' => $signer->id,
            ])->save();

            return $locked;
        });
    }
}
