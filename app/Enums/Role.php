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
}
