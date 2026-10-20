<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Enums\PackageStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\PackageLedgerEntry;
use App\Models\PatientPackage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Session-package ledger. The ledger is append-only; a package's balance is
 * SUM(sessions) over its entries and is never allowed below zero. Every
 * mutation locks the package row first.
 */
class PackageService
{
    public function onInvoicePosted(Invoice $invoice): void
    {
        $invoice->loadMissing('items.packagePlan');

        foreach ($invoice->items as $item) {
            if (! $item->package_plan_id) {
                continue;
            }

            $plan = $item->packagePlan;
            for ($i = 0; $i < $item->quantity; $i++) {
                $package = PatientPackage::query()->create([
                    'patient_id' => $invoice->patient_id,
                    'package_plan_id' => $plan->id,
                    'service_id' => $plan->service_id,
                    'invoice_id' => $invoice->id,
                    'name' => $plan->name,
                    'sessions_total' => $plan->sessions_count,
                    'purchased_on' => $invoice->issue_date,
                    'expires_on' => CarbonImmutable::parse($invoice->issue_date)->addDays($plan->validity_days),
                    'status' => PackageStatus::Active,
                ]);

                $this->entry($package, LedgerEntryType::PurchaseCredit, $plan->sessions_count, null, 'Purchased on invoice '.$invoice->number, $invoice->posted_by);
            }
        }
    }

    /**
     * Debit one session for a completed appointment from the patient's
     * earliest-expiring eligible package (FEFO). Idempotent per appointment.
     */
    public function consumeForAppointment(Appointment $appointment, ?int $actorId = null): ?PackageLedgerEntry
    {
        $alreadyDebited = PackageLedgerEntry::query()
            ->where('appointment_id', $appointment->id)
            ->where('entry_type', LedgerEntryType::SessionDebit)
            ->exists();

        if ($alreadyDebited) {
            return null;
        }

        $localDate = CarbonImmutable::parse($appointment->local_starts_at)->toDateString();

        $candidates = PatientPackage::query()
            ->where('patient_id', $appointment->patient_id)
            ->where('status', PackageStatus::Active)
            ->where('expires_on', '>=', $localDate)
            ->where(fn ($q) => $q->whereNull('service_id')->orWhere('service_id', $appointment->service_id))
            ->orderBy('expires_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $package) {
            $balance = $package->balance();
            if ($balance <= 0) {
                continue;
            }

            $entry = $this->entry($package, LedgerEntryType::SessionDebit, -1, $appointment->id, 'Session on '.$localDate, $actorId);

            if ($balance - 1 === 0) {
                $package->update(['status' => PackageStatus::Exhausted]);
            }

            return $entry;
        }

        return null;
    }

    /** Called when the purchasing invoice is voided or fully credited. */
    public function cancelForInvoice(Invoice $invoice, ?int $actorId = null): void
    {
        $packages = PatientPackage::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();

        foreach ($packages as $package) {
            if ($package->status === PackageStatus::Cancelled) {
                continue;
            }

            $balance = $package->balance();
            if ($balance < $package->sessions_total) {
                throw new DomainRuleViolation(
                    "Package \"{$package->name}\" already has used sessions; issue a partial credit note instead.",
                    'package_in_use',
                );
            }

            $this->entry($package, LedgerEntryType::CancelDebit, -$balance, null, 'Invoice '.$invoice->number.' reversed', $actorId);
            $package->update(['status' => PackageStatus::Cancelled]);
        }
    }

    public function adjust(PatientPackage $package, int $sessions, string $note, int $actorId): PackageLedgerEntry
    {
        return DB::transaction(function () use ($package, $sessions, $note, $actorId) {
            $locked = PatientPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $newBalance = $locked->balance() + $sessions;

            if ($newBalance < 0 || $newBalance > $locked->sessions_total) {
                throw new DomainRuleViolation('Adjustment would put the package balance out of range.', 'balance_out_of_range');
            }

            $entry = $this->entry($locked, LedgerEntryType::Adjustment, $sessions, null, $note, $actorId);
            $locked->update(['status' => $newBalance === 0 ? PackageStatus::Exhausted : PackageStatus::Active]);

            return $entry;
        });
    }

    /** Expire packages past their validity (run daily per tenant). Returns count expired. */
    public function expireDue(CarbonImmutable $today): int
    {
        $expired = 0;

        PatientPackage::query()
            ->whereIn('status', [PackageStatus::Active, PackageStatus::Exhausted])
            ->where('expires_on', '<', $today->toDateString())
            ->pluck('id')
            ->each(function (int $id) use (&$expired) {
                DB::transaction(function () use ($id, &$expired) {
                    $package = PatientPackage::query()->whereKey($id)->lockForUpdate()->first();
                    $balance = $package->balance();
                    if ($balance > 0) {
                        $this->entry($package, LedgerEntryType::ExpiryDebit, -$balance, null, 'Expired on '.$package->expires_on->toDateString(), null);
                    }
                    $package->update(['status' => PackageStatus::Expired]);
                    $expired++;
                });
            });

        return $expired;
    }

    private function entry(PatientPackage $package, LedgerEntryType $type, int $sessions, ?int $appointmentId, ?string $note, ?int $actorId): PackageLedgerEntry
    {
        return PackageLedgerEntry::query()->create([
            'patient_package_id' => $package->id,
            'appointment_id' => $appointmentId,
            'entry_type' => $type,
            'sessions' => $sessions,
            'note' => $note,
            'created_by' => $actorId,
        ]);
    }
}
