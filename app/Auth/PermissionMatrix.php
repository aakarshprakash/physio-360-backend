<?php

namespace App\Auth;

use App\Enums\Role;

/**
 * Module permission matrix. Roles grant coarse module abilities; record-level
 * rules (branch membership, note authorship, invoice state) live in Policies.
 *
 * Hiding UI by role is cosmetic: every endpoint authorizes on the server.
 */
final class PermissionMatrix
{
    public const PERMISSIONS = [
        'patients.view' => 'View patient demographics',
        'patients.manage' => 'Create and edit patients',
        'patients.delete' => 'Archive patients',
        'appointments.view' => 'View the schedule',
        'appointments.manage' => 'Book, reschedule and cancel appointments',
        'clinical.view' => 'Read assessments, notes and treatment plans',
        'clinical.write' => 'Write assessments, notes and treatment plans',
        'exercises.manage' => 'Maintain the exercise library',
        'enquiries.manage' => 'Capture and follow up enquiries (leads)',
        'billing.view' => 'View invoices and payments',
        'billing.manage' => 'Create invoices and record payments',
        'billing.void' => 'Void invoices, issue credit notes and refunds',
        'reports.view' => 'View reports and analytics',
        'staff.manage' => 'Manage staff, rooms and working hours',
        'settings.manage' => 'Manage clinic settings, services and templates',
        'audit.view' => 'View the audit log',
        'messages.send' => 'Message patients and send payment reminders',
        'attendance.manage' => 'Record and correct staff attendance',
        'leave.approve' => 'Approve or reject leave requests',
        'payroll.manage' => 'Manage salaries, run payroll and issue payslips',
        'branches.all' => 'Access every branch regardless of membership',
    ];

    /** @return array<string, list<string>> role => permissions */
    public static function grants(): array
    {
        $all = array_keys(self::PERMISSIONS);

        return [
            Role::Owner->value => $all,
            Role::Admin->value => array_values(array_diff($all, ['clinical.write', 'audit.view'])),
            Role::Therapist->value => [
                'patients.view', 'patients.manage',
                'appointments.view', 'appointments.manage',
                'clinical.view', 'clinical.write',
                'exercises.manage',
            ],
            Role::Receptionist->value => [
                'patients.view', 'patients.manage',
                'appointments.view', 'appointments.manage',
                'enquiries.manage',
                'billing.view', 'billing.manage',
                'messages.send',
            ],
            Role::Accountant->value => [
                'patients.view',
                'appointments.view',
                'billing.view', 'billing.manage', 'billing.void',
                'reports.view',
                'messages.send', 'payroll.manage',
                'branches.all',
            ],
        ];
    }
}
