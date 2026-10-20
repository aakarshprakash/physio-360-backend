<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level backstops for records that must never change.
 *
 * Application code already refuses these writes (model guards, no update routes);
 * the triggers make the guarantee hold for raw SQL, console sessions and future code.
 *
 * Managed MySQL (RDS / Cloud SQL) with binary logging needs
 * log_bin_trust_function_creators = 1 for the migration user to create triggers.
 */
return new class extends Migration
{
    /** Tables that are strictly append-only. */
    private array $appendOnly = [
        'audit_logs',
        'clinical_note_addenda',
        'consent_signatures',
        'payments',
        'package_ledger_entries',
    ];

    /** Tables whose rows freeze once signed_at is set. */
    private array $signable = ['clinical_notes', 'assessments'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->appendOnly as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only';
            SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only';
            SQL);
        }

        foreach ($this->signable as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_signed_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                BEGIN
                    IF OLD.signed_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signed {$table} rows are immutable';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_signed_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                BEGIN
                    IF OLD.signed_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signed {$table} rows are immutable';
                    END IF;
                END
            SQL);
        }

        // Posted invoices: the financial content and identity are frozen; only settlement
        // columns (paid/credited/status/claim tracking) may move.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoices_posted_frozen BEFORE UPDATE ON invoices FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' AND (
                    NOT (NEW.number <=> OLD.number)
                    OR NEW.patient_id <> OLD.patient_id
                    OR NEW.branch_id <> OLD.branch_id
                    OR NEW.currency_code <> OLD.currency_code
                    OR NEW.subtotal_minor <> OLD.subtotal_minor
                    OR NEW.discount_minor <> OLD.discount_minor
                    OR NEW.tax_minor <> OLD.tax_minor
                    OR NEW.total_minor <> OLD.total_minor
                    OR NOT (NEW.issue_date <=> OLD.issue_date)
                    OR (NEW.status = 'draft')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted invoices are immutable';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoices_posted_no_delete BEFORE DELETE ON invoices FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted invoices cannot be deleted';
                END IF;
            END
        SQL);

        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            $name = 'invoice_items_frozen_'.strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$event} ON invoice_items FOR EACH ROW
                BEGIN
                    IF (SELECT status FROM invoices WHERE id = {$row}.invoice_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Items of a posted invoice are immutable';
                    END IF;
                END
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $names = [];
        foreach ($this->appendOnly as $table) {
            $names[] = "{$table}_no_update";
            $names[] = "{$table}_no_delete";
        }
        foreach ($this->signable as $table) {
            $names[] = "{$table}_signed_no_update";
            $names[] = "{$table}_signed_no_delete";
        }
        array_push($names, 'invoices_posted_frozen', 'invoices_posted_no_delete',
            'invoice_items_frozen_insert', 'invoice_items_frozen_update', 'invoice_items_frozen_delete');

        foreach ($names as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }
};
