<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\EnquiryStatus;
use App\Enums\MessageChannel;
use App\Enums\NoteStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\ClinicalNote;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\InsurancePayer;
use App\Models\LeaveType;
use App\Models\PackageLedgerEntry;
use App\Models\PackagePlan;
use App\Models\Patient;
use App\Models\PlatformAdmin;
use App\Models\Prescription;
use App\Models\ProgressEntry;
use App\Models\Room;
use App\Models\SalaryStructure;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Billing\InvoiceService;
use App\Services\Clinical\ClinicalRecordService;
use App\Services\EnquiryService;
use App\Services\Hr\LeaveCalendar;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollService;
use App\Services\Hr\WorkCalendar;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\PaymentReminderService;
use App\Services\PatientService;
use App\Services\Scheduling\BookingRequest;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\SeriesRule;
use App\Services\Scheduling\SlotConflict;
use App\Services\Sequences\SequenceAllocator;
use App\Services\SupportService;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic demo data created through the real domain services (booking
 * locks, invoice posting, package ledger) so the demo exercises the same
 * rules as production. NEVER runs in production.
 *
 * Logins (password for all: Physio@2026):
 *   SYSNAC console  admin@sysnac.test
 *   Clinic (owner)  sarah@physio360.test        clinic code: demo
 *   Front desk      reena@physio360.test
 *   Accountant      joseph@physio360.test
 *   Patient app     anil.kumar@example.com      clinic code: demo
 *   GCC clinic      omar@gulfphysio.test        clinic code: gulf-physio (KWD, 3 decimals)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Physio@2026';

    private Generator $faker;

    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantContext $context,
        private readonly PatientService $patients,
        private readonly BookingService $booking,
        private readonly InvoiceService $invoices,
        private readonly ClinicalRecordService $records,
    ) {
        $this->faker = Faker::create('en_IN');
        $this->faker->seed(360);
        mt_srand(360);
    }

    public function run(): void
    {
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        $admin = PlatformAdmin::query()->firstOrNew(['email' => 'admin@sysnac.test']);
        $admin->name = 'SYSNAC Admin';
        $admin->password = self::PASSWORD;
        $admin->save();

        if (Tenant::query()->where('slug', 'demo')->exists()) {
            $this->command?->warn('Demo data already present; skipping.');

            return;
        }

        $this->seedIndianClinic();
        $this->seedGulfClinic();
    }

    private function seedIndianClinic(): void
    {
        $tenant = $this->provisioner->provision(
            ['name' => 'Physio360 Demo Clinic', 'slug' => 'demo', 'country_code' => 'IN', 'currency_code' => 'INR',
                'timezone' => 'Asia/Kolkata', 'email' => 'hello@physio360.test', 'phone' => '+91 484 400 3600',
                'branch_name' => 'Kochi Central', 'branch_code' => 'KOC', 'city' => 'Kochi'],
            ['name' => 'Dr. Sarah Mathew', 'email' => 'sarah@physio360.test', 'password' => self::PASSWORD, 'title' => 'Physiotherapist'],
        );

        $this->context->run($tenant, function (Tenant $tenant) {
            $tenant->update(['legal_name' => 'Physio360 Demo Clinic Pvt Ltd', 'tax_number' => '32ABCDE1234F1Z5', 'address' => 'MG Road, Ernakulam, Kochi, Kerala 682016']);

            $kochi = Branch::query()->where('code', 'KOC')->first();
            $kochi->update(['address' => 'MG Road, Ernakulam', 'phone' => '+91 484 400 3600']);
            $tvm = Branch::query()->create(['name' => 'Trivandrum', 'code' => 'TVM', 'city' => 'Thiruvananthapuram', 'phone' => '+91 471 400 3600']);
            Room::query()->create(['branch_id' => $tvm->id, 'name' => 'Treatment Room A']);
            Room::query()->create(['branch_id' => $tvm->id, 'name' => 'Treatment Room B']);

            $sarah = User::query()->where('email', 'sarah@physio360.test')->first();
            $sarah->update(['specialization' => 'Orthopaedic & Sports Rehab', 'color' => '#3E8E76', 'phone' => '+91 98470 12345']);

            $arjun = $this->staff('Dr. Arjun Menon', 'arjun@physio360.test', Role::Therapist, [$kochi], true, '#4C7BD9', 'Neuro Rehabilitation');
            $fatima = $this->staff('Dr. Fatima Khan', 'fatima@physio360.test', Role::Therapist, [$kochi, $tvm], true, '#8A63D2', 'Women\'s Health & Spine');
            $this->staff('Reena Thomas', 'reena@physio360.test', Role::Receptionist, [$kochi, $tvm], false, '#E0A030');
            $this->staff('Joseph Varghese', 'joseph@physio360.test', Role::Accountant, [$kochi, $tvm], false, '#2B9AA8');
            $this->staff('Kiran Das', 'kiran@physio360.test', Role::Admin, [$kochi, $tvm], false, '#D9534F');

            foreach ([[$arjun, $kochi], [$fatima, $kochi], [$fatima, $tvm]] as [$user, $branch]) {
                if ($user === $fatima && $branch === $tvm) {
                    // Fatima works in Trivandrum on Saturdays only.
                    WorkingHour::query()->create(['user_id' => $user->id, 'branch_id' => $branch->id, 'weekday' => 6, 'start_time' => '09:00', 'end_time' => '14:00']);

                    continue;
                }
                $this->provisioner->defaultWorkingHours($user, $branch);
            }
            // Fatima is in Kochi Monday-Friday only.
            WorkingHour::query()->where('user_id', $fatima->id)->where('branch_id', $kochi->id)->where('weekday', 6)->delete();

            InsurancePayer::query()->create(['name' => 'Star Health Insurance', 'type' => 'insurer', 'code' => 'STAR']);
            InsurancePayer::query()->create(['name' => 'Medi Assist TPA', 'type' => 'tpa', 'code' => 'MEDI']);

            $practitioners = [$sarah, $arjun, $fatima];
            $patients = $this->createPatients($kochi, $tvm, $practitioners);
            $this->createSchedule($tenant, $kochi, $practitioners, $patients);
            $this->createClinicalRecords($tenant, $sarah, $patients);
            $this->createPackages($tenant, $kochi, $sarah, $patients['Priya Nair']);
            $this->createEnquiries($kochi, $tvm, $sarah);
            $this->createOperations($tenant, $kochi, $sarah, $patients);
        });
    }

    private function createEnquiries(Branch $kochi, Branch $tvm, User $owner): void
    {
        $service = app(EnquiryService::class);
        $reena = User::query()->where('email', 'reena@physio360.test')->first();
        $rows = [
            ['Rahul Varma', '+91 98470 22110', 'website', 'Lower back pain for 2 months', $kochi, null],
            ['Divya Krishnan', '+91 99460 33221', 'whatsapp', 'Post-pregnancy back and pelvic pain', $kochi, 'call'],
            ['Thomas Mathew', '+91 94950 44332', 'phone', 'Frozen shoulder, referred by GP', $kochi, 'follow'],
            ['Asha Pillai', '+91 81390 55443', 'walk_in', 'Knee osteoarthritis, wants home visits', $tvm, 'follow'],
            ['Faisal Ahmed', '+91 70120 66554', 'referral', 'ACL rehab after surgery', $kochi, 'convert'],
            ['Meera Iyer', '+91 90480 77665', 'social', 'Neck pain from desk work', $kochi, 'lost'],
            ['Joseph K', '+91 97450 88776', 'website', 'Sports injury - ankle', $kochi, null],
        ];

        foreach ($rows as $i => [$name, $phone, $source, $interest, $branch, $stage]) {
            $enquiry = $service->create([
                'branch_id' => $branch->id, 'name' => $name, 'phone' => $phone, 'source' => $source,
                'interest' => $interest, 'assigned_to' => $reena?->id,
                'email' => $i % 2 === 0 ? strtolower(str_replace(' ', '.', $name)).'@example.com' : null,
            ], $source === 'website' ? null : $reena?->id);

            match ($stage) {
                'call' => $service->logActivity($enquiry, 'call', 'Discussed symptoms; will call back after checking schedule.', $reena?->id),
                'follow' => $service->logActivity($enquiry, 'whatsapp', 'Shared clinic location and fees.', $reena?->id, now()->addDays($i % 2 === 0 ? 0 : 2)->setTime(11, 0)),
                'convert' => $service->convert($service->logActivity($enquiry, 'call', 'Booked initial assessment.', $reena?->id), [], null, $reena?->id),
                'lost' => $service->transition($service->logActivity($enquiry, 'call', 'Prefers a clinic closer to home.', $reena?->id), EnquiryStatus::Lost, 'Location too far', $reena?->id),
                default => null,
            };
        }
    }

    private function seedGulfClinic(): void
    {
        $tenant = $this->provisioner->provision(
            ['name' => 'Gulf Physio Kuwait', 'slug' => 'gulf-physio', 'country_code' => 'KW', 'currency_code' => 'KWD',
                'timezone' => 'Asia/Kuwait', 'locale' => 'ar', 'branch_name' => 'Salmiya', 'branch_code' => 'SAL', 'city' => 'Salmiya'],
            ['name' => 'Dr. Omar Al-Sabah', 'email' => 'omar@gulfphysio.test', 'password' => self::PASSWORD, 'title' => 'Senior Physiotherapist'],
        );

        $this->context->run($tenant, function () {
            $branch = Branch::query()->first();
            $omar = User::query()->where('email', 'omar@gulfphysio.test')->first();
            $service = Service::query()->where('code', 'ASSESS')->first();

            $patient = $this->patients->create([
                'first_name' => 'Layla', 'last_name' => 'Al-Mutairi', 'gender' => 'female', 'date_of_birth' => '1988-04-12',
                'phone' => '+965 5555 1234', 'email' => 'layla@example.com', 'city' => 'Salmiya', 'primary_branch_id' => $branch->id,
                'primary_practitioner_id' => $omar->id, 'preferred_language' => 'ar',
            ]);

            $invoice = $this->invoices->createDraft([
                'patient_id' => $patient->id, 'branch_id' => $branch->id,
                'items' => [['service_id' => $service->id, 'unit_price_minor' => 32_500], ['description' => 'Kinesio taping', 'unit_price_minor' => 4_750]],
            ], $omar->id);
            $this->invoices->post($invoice, $omar->id);
            $this->invoices->recordPayment($invoice->refresh(), 20_000, PaymentMethod::Card, ['received_by' => $omar->id]);
        });
    }

    private function staff(string $name, string $email, Role $role, array $branches, bool $practitioner, string $color, ?string $specialization = null): User
    {
        $user = new User([
            'name' => $name, 'email' => $email, 'is_practitioner' => $practitioner, 'color' => $color,
            'title' => $practitioner ? 'Physiotherapist' : $role->label(), 'specialization' => $specialization,
            'phone' => '+91 9'.$this->faker->numerify('#### #####'),
        ]);
        $user->password = self::PASSWORD;
        $user->save();
        $user->branches()->sync(collect($branches)->mapWithKeys(fn (Branch $b) => [$b->id => ['tenant_id' => $user->tenant_id]])->all());
        $user->assignRole($role->value);

        return $user;
    }

    /** @return array<string, Patient> */
    private function createPatients(Branch $kochi, Branch $tvm, array $practitioners): array
    {
        $named = [
            ['Anil', 'Kumar', 'male', '1994-03-12', '+91 98765 43210', 'anil.kumar@example.com', 'Kochi', 'IT Professional', 'Dr. Joseph', 'Right knee ACL reconstruction (Aug 2026). No other significant history.'],
            ['Priya', 'Nair', 'female', '1987-07-21', '+91 98950 11223', 'priya.nair@example.com', 'Kochi', 'Teacher', 'Self', 'Chronic low back pain, 3 years. Desk work.'],
            ['Mohamed', 'Rafi', 'male', '1979-11-02', '+91 97440 55667', 'rafi.m@example.com', 'Aluva', 'Business Owner', 'Dr. Ameer', 'Right shoulder impingement. Type 2 diabetes (controlled).'],
            ['Sneha', 'James', 'female', '1991-01-30', '+91 94470 88990', 'sneha.james@example.com', 'Kochi', 'Nurse', 'Lakeshore Hospital', 'Left total knee replacement Sept 2026.'],
            ['Vishal', 'Menon', 'male', '1985-05-17', '+91 99610 22334', null, 'Kakkanad', 'Software Engineer', null, 'Cervical spondylosis, neck pain radiating to left arm.'],
            ['Vikram', 'S', 'male', '1998-09-09', '+91 90370 66778', null, 'Kochi', 'Footballer', 'Kerala Blasters Academy', 'Grade 2 hamstring strain.'],
            ['Neha', 'Peter', 'female', '1996-12-05', '+91 81290 44556', 'neha.peter@example.com', 'Thrissur', 'Dancer', null, 'Ankle sprain, recurrent.'],
        ];

        $patients = [];
        foreach ($named as $i => [$first, $last, $gender, $dob, $phone, $email, $city, $occupation, $referred, $history]) {
            $patients["{$first} {$last}"] = $this->patients->create([
                'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => $dob,
                'phone' => $phone, 'email' => $email, 'city' => $city, 'occupation' => $occupation, 'referred_by' => $referred,
                'address' => rtrim($this->faker->streetAddress(), ', ').', '.$city.', Kerala', 'medical_history' => $history,
                'emergency_contact_name' => $this->faker->name(), 'emergency_contact_phone' => '+91 9'.$this->faker->numerify('#### #####'),
                'primary_branch_id' => $kochi->id, 'primary_practitioner_id' => $practitioners[$i % 2 === 0 ? 0 : 1]->id,
            ]);
        }

        $patients['Anil Kumar']->forceFill(['portal_enabled' => true, 'portal_password' => self::PASSWORD])->save();
        $patients['Priya Nair']->forceFill(['portal_enabled' => true, 'portal_password' => self::PASSWORD])->save();

        for ($i = 0; $i < 38; $i++) {
            $gender = $this->faker->randomElement(['male', 'female']);
            $branch = $i % 6 === 0 ? $tvm : $kochi;
            $patients[] = $this->patients->create([
                'first_name' => $this->faker->firstName($gender), 'last_name' => $this->faker->lastName(),
                'gender' => $gender, 'date_of_birth' => $this->faker->dateTimeBetween('-75 years', '-16 years')->format('Y-m-d'),
                'phone' => '+91 9'.$this->faker->numerify('#### #####'),
                'email' => $i % 3 === 0 ? $this->faker->unique()->safeEmail() : null,
                'city' => $this->faker->randomElement(['Kochi', 'Aluva', 'Kakkanad', 'Thrissur', 'Thiruvananthapuram', 'Kottayam']),
                'occupation' => $this->faker->jobTitle(),
                'medical_history' => $this->faker->randomElement(['Lumbar disc prolapse L4-L5.', 'Frozen shoulder (left).', 'Plantar fasciitis.', 'Post-fracture stiffness, right wrist.', 'Osteoarthritis both knees.', 'Tennis elbow.', 'Post-stroke hemiparesis (right).']),
                'primary_branch_id' => $branch->id,
                'primary_practitioner_id' => $branch->is($tvm) ? $practitioners[2]->id : $this->faker->randomElement($practitioners)->id,
            ]);
        }

        return $patients;
    }

    private function createSchedule(Tenant $tenant, Branch $kochi, array $practitioners, array $patients): void
    {
        $tz = $tenant->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $services = Service::query()->get()->keyBy('code');
        $rooms = Room::query()->where('branch_id', $kochi->id)->get();
        $all = array_values($patients);
        [$sarah, $arjun] = $practitioners;

        // Today's schedule for Dr. Sarah, as on the dashboard.
        $todays = [
            ['09:00', 'Anil Kumar', 'POSTOP', AppointmentStatus::Completed],
            ['10:00', 'Priya Nair', 'FOLLOW', AppointmentStatus::Confirmed],
            ['11:30', 'Mohamed Rafi', 'FOLLOW', AppointmentStatus::Confirmed],
            ['14:00', 'Sneha James', 'POSTOP', AppointmentStatus::Scheduled],
            ['15:30', 'Vishal Menon', 'ASSESS', AppointmentStatus::Scheduled],
        ];
        if ($today->dayOfWeek !== 0) {
            foreach ($todays as $i => [$time, $name, $code, $status]) {
                $appointment = $this->book($patients[$name], $sarah, $kochi, $today->setTimeFromTimeString($time), $services[$code], $rooms[$i % 2]);
                $appointment && $this->advance($appointment, $status, $sarah);
            }
        }

        // Six weeks of history and two weeks ahead across practitioners.
        for ($d = -42; $d <= 14; $d++) {
            $day = $today->addDays($d);
            if ($day->dayOfWeek === 0 || $d === 0) {
                continue;
            }

            foreach ($practitioners as $p => $practitioner) {
                if ($p === 2 && $day->dayOfWeek === 6) {
                    continue;
                }
                $slots = $this->faker->randomElements(['09:00', '10:00', '11:00', '12:00', '14:00', '15:00', '16:00', '17:00', '18:00'], mt_rand(3, 6));
                foreach ($slots as $time) {
                    $patient = $all[mt_rand(0, count($all) - 1)];
                    $service = $services[$this->faker->randomElement(['FOLLOW', 'FOLLOW', 'FOLLOW', 'SPORTS', 'ASSESS', 'NEEDLE', 'POSTOP'])];
                    $isHome = mt_rand(1, 18) === 1;
                    $appointment = $this->book($patient, $practitioner, $kochi, $day->setTimeFromTimeString($time),
                        $isHome ? $services['HOME'] : $service, $isHome ? null : $rooms[mt_rand(0, $rooms->count() - 1)], $isHome);

                    if (! $appointment) {
                        continue;
                    }

                    if ($d < 0) {
                        $roll = mt_rand(1, 100);
                        $status = $roll <= 84 ? AppointmentStatus::Completed : ($roll <= 92 ? AppointmentStatus::Cancelled : AppointmentStatus::NoShow);
                        $this->advance($appointment, $status, $practitioner);

                        if ($status === AppointmentStatus::Completed && $d >= -28) {
                            $this->bill($appointment, $practitioner, $d);
                        }
                    } elseif (mt_rand(1, 2) === 1) {
                        $this->advance($appointment, AppointmentStatus::Confirmed, $practitioner);
                    }
                }
            }
        }

        // A weekly series for Vikram's hamstring rehab.
        $next = $today->addDay();
        while ($next->dayOfWeek !== 2) {
            $next = $next->addDay();
        }
        try {
            $this->booking->bookSeries(
                new BookingRequest(patientId: $patients['Vikram S']->id, practitionerId: $arjun->id, branchId: $kochi->id,
                    localStart: CarbonImmutable::parse($next->toDateString().' 13:00', $tz),
                    durationMinutes: 60, timezone: $tz, serviceId: $services['SPORTS']->id),
                new SeriesRule(weekdays: [2, 4], count: 6),
                'skip', $arjun->id,
            );
        } catch (SlotConflict) {
            // fine for demo data
        }
    }

    private function book(Patient $patient, User $practitioner, Branch $branch, CarbonImmutable $localStart, Service $service, ?Room $room, bool $home = false): ?Appointment
    {
        try {
            return $this->booking->book(new BookingRequest(
                patientId: $patient->id,
                practitionerId: $practitioner->id,
                branchId: $branch->id,
                localStart: $localStart,
                durationMinutes: $service->duration_minutes,
                timezone: $branch->effectiveTimezone(),
                roomId: $room?->id,
                serviceId: $service->id,
                type: $home ? AppointmentType::HomeVisit : AppointmentType::Clinic,
                travelBufferMinutes: $home ? 30 : 0,
                homeVisitAddress: $home ? rtrim($this->faker->streetAddress(), ', ').', Kochi' : null,
            ), $practitioner->id);
        } catch (SlotConflict|DomainRuleViolation) {
            return null;
        }
    }

    private function advance(Appointment $appointment, AppointmentStatus $target, User $actor): void
    {
        $path = match ($target) {
            AppointmentStatus::Confirmed => [AppointmentStatus::Confirmed],
            AppointmentStatus::Completed => [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress, AppointmentStatus::Completed],
            AppointmentStatus::Cancelled => [AppointmentStatus::Cancelled],
            AppointmentStatus::NoShow => [AppointmentStatus::NoShow],
            default => [],
        };

        foreach ($path as $status) {
            $appointment = $this->booking->transition($appointment, $status, $status === AppointmentStatus::Cancelled ? 'Patient unwell' : null, $actor->id);
        }
    }

    private function bill(Appointment $appointment, User $actor, int $dayOffset): void
    {
        if (PackageLedgerEntry::query()->where('appointment_id', $appointment->id)->exists()) {
            return; // covered by a package
        }

        $service = Service::query()->find($appointment->service_id);
        $invoice = $this->invoices->createDraft([
            'patient_id' => $appointment->patient_id,
            'branch_id' => $appointment->branch_id,
            'items' => [[
                'service_id' => $service->id,
                'appointment_id' => $appointment->id,
                'description' => $service->name.' · '.$appointment->local_starts_at->format('d M Y'),
                'discount_minor' => mt_rand(1, 10) === 1 ? 10_000 : 0,
            ]],
        ], $actor->id);

        $invoice = $this->invoices->post($invoice, $actor->id, $appointment->local_starts_at->toDateString());

        $roll = mt_rand(1, 100);
        if ($roll <= 78 || $dayOffset < -21) {
            $this->invoices->recordPayment($invoice->refresh(), $invoice->total_minor,
                $this->faker->randomElement([PaymentMethod::Upi, PaymentMethod::Upi, PaymentMethod::Cash, PaymentMethod::Card]),
                ['received_by' => $actor->id, 'received_at' => $appointment->ends_at]);
        } elseif ($roll <= 88) {
            $this->invoices->recordPayment($invoice->refresh(), intdiv($invoice->total_minor, 2), PaymentMethod::Cash,
                ['received_by' => $actor->id, 'received_at' => $appointment->ends_at]);
        }
    }

    private function createClinicalRecords(Tenant $tenant, User $sarah, array $patients): void
    {
        $tz = $tenant->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $anil = $patients['Anil Kumar'];

        $assessment = new Assessment([
            'assessed_at' => $today->subDays(15)->setTime(9, 0)->utc(),
            'chief_complaint' => 'Right knee stiffness and weakness 7 weeks after ACL reconstruction',
            'history' => 'Hamstring graft ACL reconstruction on the right knee. Using one crutch outdoors. Pain on stairs.',
            'pain_score' => 7,
            'pain_location' => 'Right knee, anterior',
            'findings' => [
                'rom' => [['joint' => 'Knee (R)', 'movement' => 'Flexion', 'degrees' => 95], ['joint' => 'Knee (R)', 'movement' => 'Extension', 'degrees' => -5]],
                'strength' => [['muscle' => 'Quadriceps (R)', 'grade' => '3+/5'], ['muscle' => 'Hamstrings (R)', 'grade' => '4-/5']],
                'special_tests' => [['name' => 'Lachman', 'result' => 'negative']],
                'observations' => 'Mild effusion, quadriceps lag 10°. Antalgic gait.',
            ],
            'diagnosis' => 'Post-operative ACL reconstruction (R), phase 2 rehabilitation',
            'goals' => 'Full extension in 2 weeks; 120° flexion and normal gait in 6 weeks; return to jogging at 4 months.',
        ]);
        $assessment->patient_id = $anil->id;
        $assessment->practitioner_id = $sarah->id;
        $assessment->status = NoteStatus::Draft;
        $assessment->save();
        $this->records->signAssessment($assessment, $sarah);

        $plan = TreatmentPlan::query()->create([
            'patient_id' => $anil->id,
            'practitioner_id' => $sarah->id,
            'title' => 'Knee Rehabilitation Program',
            'diagnosis' => 'Post ACL reconstruction (R)',
            'goals' => 'Restore full ROM, quadriceps strength and normal gait; return to recreational football.',
            'start_date' => $today->subDays(15)->toDateString(),
            'duration_weeks' => 6,
            'frequency_per_week' => 3,
            'status' => 'active',
        ]);
        foreach (['Quad Set' => [3, 15, 5], 'Straight Leg Raise' => [3, 12, null], 'Hamstring Stretch' => [3, null, 30], 'Step Up' => [3, 15, null], 'Heel Slide' => [3, 15, null]] as $name => [$sets, $reps, $hold]) {
            $row = $plan->exercises()->create(['exercise_id' => Exercise::query()->where('name', $name)->value('id'), 'sets' => $sets, 'reps' => $reps, 'hold_seconds' => $hold, 'frequency' => '2x daily']);

            // About 80% home-exercise adherence since the plan started.
            for ($d = 14; $d >= 1; $d--) {
                if (mt_rand(1, 100) <= 80) {
                    ExerciseLog::query()->create([
                        'patient_id' => $anil->id,
                        'treatment_plan_exercise_id' => $row->id,
                        'performed_on' => $today->subDays($d)->toDateString(),
                        'pain_score' => max(2, 7 - intdiv(14 - $d, 3)),
                    ]);
                }
            }
        }

        foreach ([[-15, 7], [-12, 6], [-8, 5], [-5, 4], [-1, 3]] as $i => [$offset, $pain]) {
            $note = new ClinicalNote([
                'subjective' => $i === 0 ? 'Pain 7/10 on stairs, stiffness in the morning.' : "Reports improvement. Pain {$pain}/10 on stairs.",
                'objective' => 'Knee flexion '.(95 + $i * 6).'°, extension '.($i < 3 ? '-3°' : '0°').'. Quadriceps lag reducing.',
                'assessment' => 'Progressing as expected for phase 2.',
                'plan' => 'Continue HEP. Progress closed-chain strengthening. Review in 3 days.',
                'pain_score' => $pain,
            ]);
            $note->patient_id = $anil->id;
            $note->author_id = $sarah->id;
            $note->status = NoteStatus::Draft;
            $note->created_at = $today->addDays($offset)->setTime(9, 45)->utc();
            $note->save();
            if ($offset < -1) {
                $this->records->signNote($note, $sarah);
            }

            ProgressEntry::query()->create(['patient_id' => $anil->id, 'treatment_plan_id' => $plan->id, 'recorded_on' => $today->addDays($offset)->toDateString(),
                'pain_score' => $pain, 'function_score' => 40 + $i * 9, 'measure' => 'Knee flexion ROM', 'value' => 95 + $i * 6, 'unit' => '°', 'recorded_by' => $sarah->id]);
        }

        // Outcome trends for many patients so the dashboard progress chart is meaningful.
        foreach (array_slice(array_values($patients), 1, 30) as $k => $patient) {
            $start = mt_rand(5, 9);
            $improvement = $k % 4 === 0 ? 1 : mt_rand(2, 5);
            for ($w = 0; $w < 4; $w++) {
                ProgressEntry::query()->create([
                    'patient_id' => $patient->id,
                    'recorded_on' => $today->startOfMonth()->subDays(10)->addDays($w * 7 + $k % 5)->min($today)->toDateString(),
                    'pain_score' => max(0, $start - (int) round($improvement * $w / 3)),
                    'measure' => 'NPRS',
                    'recorded_by' => $sarah->id,
                ]);
            }
        }
    }

    private function createPackages(Tenant $tenant, Branch $branch, User $sarah, Patient $priya): void
    {
        $plan = PackagePlan::query()->where('sessions_count', 10)->first();
        $invoice = $this->invoices->createDraft(['patient_id' => $priya->id, 'branch_id' => $branch->id, 'items' => [['package_plan_id' => $plan->id]]], $sarah->id);
        $invoice = $this->invoices->post($invoice, $sarah->id);
        $this->invoices->recordPayment($invoice->refresh(), $invoice->total_minor, PaymentMethod::Upi, ['received_by' => $sarah->id, 'reference' => 'UPI 4410029383']);
    }

    /** Prescriptions, messaging, attendance, leave, payroll and a support ticket. */
    private function createOperations(Tenant $tenant, Branch $kochi, User $sarah, array $patients): void
    {
        $tz = $tenant->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $year = $today->year;
        $staff = User::query()->orderBy('id')->get()->keyBy('email');

        $tenant->update(['settings' => array_merge($tenant->settings ?? [], [
            'weekly_off_days' => [0],
            'holidays' => [
                ['date' => "{$year}-01-26", 'name' => 'Republic Day'],
                ['date' => "{$year}-08-15", 'name' => 'Independence Day'],
                ['date' => "{$year}-08-26", 'name' => 'Thiruvonam'],
                ['date' => "{$year}-10-02", 'name' => 'Gandhi Jayanti'],
                ['date' => "{$year}-12-25", 'name' => 'Christmas'],
            ],
            'attendance_grace_minutes' => 10,
            'payment_reminders' => ['enabled' => true, 'channel' => 'email', 'days_after_due' => [1, 7, 14], 'include_payment_link' => false],
        ])]);
        $calendar = app(WorkCalendar::class);

        // Prescription issued from Anil's signed assessment and active plan.
        $anil = $patients['Anil Kumar'];
        $assessment = Assessment::query()->where('patient_id', $anil->id)->latest('id')->first();
        $plan = TreatmentPlan::query()->where('patient_id', $anil->id)->with('exercises.exercise')->first();
        $rx = new Prescription([
            'branch_id' => $kochi->id,
            'patient_id' => $anil->id,
            'assessment_id' => $assessment?->id,
            'treatment_plan_id' => $plan?->id,
            'issued_on' => $today->subDays(15)->toDateString(),
            'diagnosis' => $assessment?->diagnosis,
            'modalities' => [
                ['name' => 'Interferential therapy (IFT)', 'parameters' => '4 kHz carrier, 80–120 Hz sweep', 'duration_minutes' => 15, 'sessions' => 10],
                ['name' => 'Cryotherapy', 'parameters' => 'Ice pack after exercise', 'duration_minutes' => 10, 'sessions' => 10],
                ['name' => 'Patellar mobilisation', 'parameters' => 'Grade II–III, all directions', 'duration_minutes' => 5, 'sessions' => 6],
            ],
            'exercises' => $plan?->exercises->map(fn ($row) => ['name' => $row->exercise->name, 'dosage' => "{$row->sets} × ".($row->reps ?? ($row->hold_seconds.'s hold')).' · '.$row->frequency, 'notes' => null])->all(),
            'advice' => "Use one crutch outdoors until gait is pain-free.\nIce 10 minutes after exercise if swollen.\nAvoid squatting below 90° and pivoting for 6 weeks.",
            'precautions' => 'Stop exercise and call the clinic if the knee becomes hot, very swollen or gives way.',
            'review_on' => $today->addDays(5)->toDateString(),
        ]);
        $rx->practitioner_id = $sarah->id;
        DB::transaction(function () use ($rx, $kochi) {
            $rx->number = app(SequenceAllocator::class)->next('prescription', 'branch:'.$kochi->id, 'RX-KOC-', 6);
            $rx->save();
        });

        // Communication log: an automatic email and a manual WhatsApp reminder.
        $messenger = app(Messenger::class);
        $reena = $staff['reena@physio360.test'];
        $reminders = app(PaymentReminderService::class);
        $owing = $reminders->owing()->with('patient')->limit(2)->get();
        foreach ($owing as $i => $invoice) {
            try {
                $reminders->remind($invoice, $i === 0 ? MessageChannel::Email : MessageChannel::WhatsApp, $i === 0 ? null : $reena->id);
            } catch (DomainRuleViolation) {
                // patient without a contact for that channel
            }
        }
        $messenger->send([
            'channel' => MessageChannel::WhatsApp, 'purpose' => 'appointment_reminder', 'patient' => $anil, 'sent_by' => $reena->id,
            'body' => "Hello {$anil->first_name}, this is a reminder of your physiotherapy appointment at {$tenant->name} tomorrow at 10:00 AM with Dr. Sarah Mathew.",
        ]);

        // Salaries (INR, paise).
        $salaries = [
            'sarah@physio360.test' => [75_000_00, [['HRA', 22_500_00], ['Clinical allowance', 7_500_00]], [['Professional tax', 200_00]]],
            'arjun@physio360.test' => [45_000_00, [['HRA', 13_500_00], ['Conveyance', 2_000_00]], [['PF (employee)', 1_800_00], ['Professional tax', 200_00]]],
            'fatima@physio360.test' => [48_000_00, [['HRA', 14_400_00], ['Conveyance', 2_000_00]], [['PF (employee)', 1_800_00], ['Professional tax', 200_00]]],
            'reena@physio360.test' => [18_000_00, [['HRA', 5_400_00], ['Conveyance', 1_600_00]], [['PF (employee)', 1_800_00]]],
            'joseph@physio360.test' => [28_000_00, [['HRA', 8_400_00]], [['PF (employee)', 1_800_00], ['Professional tax', 200_00]]],
            'kiran@physio360.test' => [35_000_00, [['HRA', 10_500_00]], [['PF (employee)', 1_800_00], ['Professional tax', 200_00]]],
        ];
        foreach ($salaries as $email => [$basic, $allowances, $deductions]) {
            SalaryStructure::query()->create([
                'user_id' => $staff[$email]->id,
                'basic_minor' => $basic,
                'allowances' => array_map(fn ($a) => ['name' => $a[0], 'amount_minor' => $a[1]], $allowances),
                'deductions' => array_map(fn ($d) => ['name' => $d[0], 'amount_minor' => $d[1]], $deductions),
                'effective_from' => $today->subYear()->startOfYear()->toDateString(),
                'bank_account' => 'XXXX'.mt_rand(1000, 9999).' · Federal Bank',
            ]);
        }

        // Leave: approved last month (feeds payroll), approved this month, and pending requests.
        $leave = app(LeaveService::class);
        $types = LeaveType::query()->pluck('id', 'code');
        $lastMonth = $today->subMonthNoOverflow()->startOfMonth();
        $firstWorking = fn (CarbonImmutable $from) => collect($calendar->workingDates($from, $from->addDays(10)))->first();
        $grant = function (User $user, string $code, CarbonImmutable $from, CarbonImmutable $to, ?string $half = null, string $reason = '') use ($leave, $types, $sarah) {
            $request = $leave->request($user, ['leave_type_id' => $types[$code], 'start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'half_day' => $half, 'reason' => $reason], $user->id);

            return $leave->approve($request, $sarah);
        };
        $lopDay = $firstWorking($lastMonth->addDays(9));
        $grant($staff['reena@physio360.test'], 'LOP', $lopDay, $lopDay, null, 'Family function');
        $sick = $firstWorking($lastMonth->addDays(16));
        $grant($staff['arjun@physio360.test'], 'SL', $sick, $sick, null, 'Fever');
        if ($today->day > 3) {
            $half = $firstWorking($today->startOfMonth());
            if ($half && $half->lessThan($today)) {
                $grant($staff['joseph@physio360.test'], 'CL', $half, $half, 'pm', 'Bank work');
            }
        }
        $future = $firstWorking($today->addDays(24));
        $leave->request($staff['arjun@physio360.test'], ['leave_type_id' => $types['CL'], 'start_date' => $future->toDateString(), 'end_date' => $future->addDays(1)->toDateString(), 'reason' => 'Attending a neuro-rehab workshop in Bengaluru'], $staff['arjun@physio360.test']->id);
        $leave->request($staff['reena@physio360.test'], ['leave_type_id' => $types['EL'], 'start_date' => $firstWorking($today->addDays(35))->toDateString(), 'end_date' => $firstWorking($today->addDays(35))->addDays(3)->toDateString(), 'reason' => 'Family trip'], $staff['reena@physio360.test']->id);

        // Attendance: last month and this month up to yesterday, plus clock-ins today.
        $leaveDays = app(LeaveCalendar::class)->between($lastMonth, $today, $staff->pluck('id')->all());
        foreach ($staff as $user) {
            for ($day = $lastMonth; $day->lessThan($today); $day = $day->addDay()) {
                if (! $calendar->isWorkingDay($day) || ($leaveDays[$user->id][$day->toDateString()] ?? null)) {
                    continue;
                }
                $late = mt_rand(1, 100) <= 12;
                $in = $day->setTime(8, 0)->addMinutes(50 + ($late ? mt_rand(22, 40) : mt_rand(0, 15)));
                $out = $day->setTime(18, 0)->addMinutes(mt_rand(30, 75));
                AttendanceRecord::query()->create([
                    'user_id' => $user->id, 'branch_id' => $kochi->id, 'work_date' => $day->toDateString(),
                    'check_in_at' => $in->utc(), 'check_out_at' => $out->utc(), 'status' => 'present',
                    'minutes_worked' => (int) $in->diffInMinutes($out), 'is_late' => $late && $user->is_practitioner, 'source' => 'self',
                ]);
            }
        }
        $absent = $firstWorking($lastMonth->addDays(20));
        AttendanceRecord::query()->where('user_id', $staff['kiran@physio360.test']->id)->whereDate('work_date', $absent->toDateString())->delete();
        AttendanceRecord::query()->create(['user_id' => $staff['kiran@physio360.test']->id, 'work_date' => $absent->toDateString(), 'status' => 'absent', 'source' => 'manual', 'notes' => 'No show, not informed', 'recorded_by' => $sarah->id]);
        if ($calendar->isWorkingDay($today)) {
            foreach (['reena@physio360.test' => '08:52', 'arjun@physio360.test' => '09:04'] as $email => $time) {
                $in = $today->setTimeFromTimeString($time);
                if ($in->lessThan(CarbonImmutable::now($tz))) {
                    AttendanceRecord::query()->create(['user_id' => $staff[$email]->id, 'branch_id' => $kochi->id, 'work_date' => $today->toDateString(), 'check_in_at' => $in->utc(), 'status' => 'present', 'source' => 'self']);
                }
            }
        }

        // Last month's payroll, finalized and paid.
        $payroll = app(PayrollService::class);
        $run = $payroll->createRun($lastMonth->format('Y-m'), $staff['joseph@physio360.test']->id);
        $sarahSlip = $run->payslips()->where('user_id', $sarah->id)->first();
        $payroll->adjust($sarahSlip, [...$sarahSlip->earnings, ['name' => 'Session incentive', 'amount_minor' => 6_000_00]], $sarahSlip->deductions, '120 sessions above target');
        $payroll->finalize($run, $staff['joseph@physio360.test']->id);
        $payroll->markPaid($run, $staff['joseph@physio360.test']->id);

        // Support ticket with a SYSNAC reply.
        $support = app(SupportService::class);
        $ticket = $support->open($sarah, [
            'subject' => 'How do we add a new branch to the patient app?',
            'category' => 'question', 'priority' => 'normal',
            'body' => 'We opened the Trivandrum branch. Patients there cannot choose it while booking in the patient app. What do we need to configure?',
        ]);
        $support->replyAsSysnac($ticket, PlatformAdmin::query()->firstOrFail(), "Hello Dr. Sarah,\n\nPatients can book at a branch once at least one physiotherapist has working hours there. Go to Staff & Resources → Working hours, choose the physiotherapist and add hours for Trivandrum.\n\nRegards,\nSYSNAC Support");
    }
}
