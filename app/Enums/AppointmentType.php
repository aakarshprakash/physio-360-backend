<?php

namespace App\Enums;

enum AppointmentType: string
{
    case Clinic = 'clinic';
    case HomeVisit = 'home_visit';
    case Telehealth = 'telehealth';
}
