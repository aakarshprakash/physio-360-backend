<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Therapist = 'therapist';
    case Receptionist = 'receptionist';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Clinic Owner',
            self::Admin => 'Administrator',
            self::Therapist => 'Physiotherapist',
            self::Receptionist => 'Front Desk',
            self::Accountant => 'Accountant',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access to every module, branch, report and setting, including the audit log.',
            self::Admin => 'Runs the clinic day to day: team, schedule, billing, HR and settings. Cannot write clinical notes.',
            self::Therapist => 'Sees their patients and schedule, and writes assessments, notes, plans and exercise programmes.',
            self::Receptionist => 'Front desk: registers patients, books appointments, handles enquiries, billing and reminders.',
            self::Accountant => 'Finance across all branches: invoices, payments, refunds, reports and payroll.',
        };
    }
}
