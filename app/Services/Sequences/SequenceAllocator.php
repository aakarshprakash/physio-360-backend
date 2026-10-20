<?php

namespace App\Services\Sequences;

use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gapless, per-scope document numbers (invoice, credit note, MRN).
 *
 * The counter row is read with SELECT ... FOR UPDATE inside the caller's
 * transaction, so the increment commits or rolls back together with the
 * document that consumes it: no gaps, no duplicates, no MAX()+1 races.
 *
 * Counter rows are created up front (tenant provisioning, branch creation) so
 * the hot path always locks an existing row. Locking a *missing* row takes gap
 * locks under REPEATABLE READ, and concurrent first inserts then deadlock;
 * the insertOrIgnore fallback only exists for legacy data.
 */
class SequenceAllocator
{
    public function next(string $type, string $scopeKey, string $defaultPrefix, int $padding = 5): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Sequence numbers must be allocated inside the consuming transaction.');
        }

        $sequence = $this->locked($type, $scopeKey);

        if (! $sequence) {
            DocumentSequence::query()->insertOrIgnore([
                'tenant_id' => app(TenantContext::class)->require()->id,
                'type' => $type,
                'scope_key' => $scopeKey,
                'prefix' => $defaultPrefix,
                'padding' => $padding,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = $this->locked($type, $scopeKey);
        }

        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
    }

    /** Called when a branch is created so its counters exist before first use. */
    public static function ensureBranchSequences(Branch $branch): void
    {
        foreach (['invoice' => 'INV-', 'credit_note' => 'CN-', 'receipt' => 'RCT-', 'prescription' => 'RX-'] as $type => $prefix) {
            DocumentSequence::query()->firstOrCreate(
                ['type' => $type, 'scope_key' => 'branch:'.$branch->id],
                ['prefix' => $prefix.$branch->code.'-', 'padding' => 6, 'next_number' => 1],
            );
        }
    }

    private function locked(string $type, string $scopeKey): ?DocumentSequence
    {
        return DocumentSequence::query()
            ->where('type', $type)
            ->where('scope_key', $scopeKey)
            ->lockForUpdate()
            ->first();
    }
}
