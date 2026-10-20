<?php

use App\Http\Controllers\Api\Patient\PortalAuthController;
use App\Http\Controllers\Api\Patient\PortalController;
use App\Http\Controllers\Api\Platform\PlatformController;
use App\Http\Controllers\Api\Platform\PlatformSupportController;
use App\Http\Controllers\Api\PublicEnquiryController;
use App\Http\Controllers\Api\Staff\AppointmentController;
use App\Http\Controllers\Api\Staff\AttendanceController;
use App\Http\Controllers\Api\Staff\AuthController;
use App\Http\Controllers\Api\Staff\ClinicalController;
use App\Http\Controllers\Api\Staff\ConsultationController;
use App\Http\Controllers\Api\Staff\DocumentController;
use App\Http\Controllers\Api\Staff\EnquiryController;
use App\Http\Controllers\Api\Staff\ExerciseController;
use App\Http\Controllers\Api\Staff\InsurancePolicyController;
use App\Http\Controllers\Api\Staff\InvoiceController;
use App\Http\Controllers\Api\Staff\LeaveController;
use App\Http\Controllers\Api\Staff\MessageController;
use App\Http\Controllers\Api\Staff\PackageController;
use App\Http\Controllers\Api\Staff\PatientController;
use App\Http\Controllers\Api\Staff\PatientTimelineController;
use App\Http\Controllers\Api\Staff\PaymentReminderController;
use App\Http\Controllers\Api\Staff\PayrollController;
use App\Http\Controllers\Api\Staff\PrescriptionController;
use App\Http\Controllers\Api\Staff\ReceiptController;
use App\Http\Controllers\Api\Staff\ReportController;
use App\Http\Controllers\Api\Staff\SettingsController;
use App\Http\Controllers\Api\Staff\StaffController;
use App\Http\Controllers\Api\Staff\SupportController;
use App\Http\Controllers\Api\Staff\TreatmentPlanController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clinic staff (clinic portal SPA) — Sanctum cookie session, "web" guard
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');
});

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    Route::get('dashboard', [ReportController::class, 'dashboard']);

    // Patients
    Route::apiResource('patients', PatientController::class);
    Route::post('patients/{patient}/portal-invite', [PatientController::class, 'portalInvite']);
    Route::post('patients/{patient}/portal-disable', [PatientController::class, 'disablePortal']);
    Route::get('patients/{patient}/notes', [ClinicalController::class, 'notes']);
    Route::post('patients/{patient}/notes', [ClinicalController::class, 'storeNote']);
    Route::get('patients/{patient}/assessments', [ClinicalController::class, 'assessments']);
    Route::post('patients/{patient}/assessments', [ClinicalController::class, 'storeAssessment']);
    Route::get('patients/{patient}/progress', [ClinicalController::class, 'progress']);
    Route::post('patients/{patient}/progress', [ClinicalController::class, 'storeProgress']);
    Route::get('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'forPatient']);
    Route::post('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'store']);
    Route::get('patients/{patient}/documents', [DocumentController::class, 'index']);
    Route::post('patients/{patient}/documents', [DocumentController::class, 'store']);
    Route::get('patients/{patient}/consents', [DocumentController::class, 'consents']);
    Route::post('patients/{patient}/consents', [DocumentController::class, 'signConsent']);
    Route::get('patients/{patient}/packages', [InvoiceController::class, 'patientPackages']);
    Route::get('patients/{patient}/insurance-policies', [InsurancePolicyController::class, 'index']);
    Route::post('patients/{patient}/insurance-policies', [InsurancePolicyController::class, 'store']);
    Route::put('patients/{patient}/insurance-policies/{policy}', [InsurancePolicyController::class, 'update']);

    // Clinical records
    Route::put('notes/{note}', [ClinicalController::class, 'updateNote']);
    Route::delete('notes/{note}', [ClinicalController::class, 'destroyNote']);
    Route::post('notes/{note}/sign', [ClinicalController::class, 'signNote']);
    Route::post('notes/{note}/addenda', [ClinicalController::class, 'addAddendum']);
    Route::put('assessments/{assessment}', [ClinicalController::class, 'updateAssessment']);
    Route::post('assessments/{assessment}/sign', [ClinicalController::class, 'signAssessment']);
    Route::delete('progress/{entry}', [ClinicalController::class, 'destroyProgress']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
    Route::get('consent-signatures/{signature}/image', [DocumentController::class, 'consentSignature']);

    // Treatment plans & exercises
    Route::get('treatment-plans', [TreatmentPlanController::class, 'index']);
    Route::get('treatment-plans/{plan}', [TreatmentPlanController::class, 'show']);
    Route::put('treatment-plans/{plan}', [TreatmentPlanController::class, 'update']);
    Route::post('treatment-plans/{plan}/exercises', [TreatmentPlanController::class, 'addExercise']);
    Route::put('treatment-plans/{plan}/exercises/{row}', [TreatmentPlanController::class, 'updateExercise']);
    Route::delete('treatment-plans/{plan}/exercises/{row}', [TreatmentPlanController::class, 'removeExercise']);
    Route::get('treatment-plans/{plan}/adherence', [TreatmentPlanController::class, 'adherence']);
    Route::get('treatment-plans/{plan}/pdf', [TreatmentPlanController::class, 'pdf']);
    Route::get('exercises', [ExerciseController::class, 'index']);
    Route::post('exercises', [ExerciseController::class, 'store']);
    Route::put('exercises/{exercise}', [ExerciseController::class, 'update']);

    // Enquiries (leads)
    Route::get('enquiries', [EnquiryController::class, 'index']);
    Route::post('enquiries', [EnquiryController::class, 'store']);
    Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show']);
    Route::put('enquiries/{enquiry}', [EnquiryController::class, 'update']);
    Route::post('enquiries/{enquiry}/activities', [EnquiryController::class, 'logActivity']);
    Route::post('enquiries/{enquiry}/status', [EnquiryController::class, 'transition']);
    Route::post('enquiries/{enquiry}/convert', [EnquiryController::class, 'convert']);

    // Scheduling
    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::post('appointments', [AppointmentController::class, 'store']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::post('appointments/{appointment}/status', [AppointmentController::class, 'transition']);
    Route::post('appointments/{appointment}/invoice', [AppointmentController::class, 'invoice']);
    Route::get('availability', [AppointmentController::class, 'availability']);
    Route::get('calendar/resources', [AppointmentController::class, 'resources']);

    // Billing
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices', [InvoiceController::class, 'store']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
    Route::post('invoices/{invoice}/post', [InvoiceController::class, 'post']);
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
    Route::post('invoices/{invoice}/credit-notes', [InvoiceController::class, 'creditNote']);
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
    Route::post('invoices/{invoice}/refunds', [InvoiceController::class, 'refund']);
    Route::put('invoices/{invoice}/claim', [InvoiceController::class, 'updateClaim']);
    Route::post('invoices/{invoice}/payment-link', [InvoiceController::class, 'paymentLink']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::get('payments', [InvoiceController::class, 'payments']);
    Route::get('billing/uninvoiced-appointments', [InvoiceController::class, 'uninvoiced']);
    Route::post('packages/{package}/adjust', [InvoiceController::class, 'adjustPackage']);

    Route::get('payments/{payment}/receipt', ReceiptController::class);
    Route::get('packages', [PackageController::class, 'index']);

    // Consultations, prescriptions & timeline
    Route::get('consultations', [ConsultationController::class, 'index']);
    Route::get('prescriptions', [PrescriptionController::class, 'index']);
    Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show']);
    Route::put('prescriptions/{prescription}', [PrescriptionController::class, 'update']);
    Route::get('prescriptions/{prescription}/pdf', [PrescriptionController::class, 'pdf']);
    Route::get('patients/{patient}/prescriptions', [PrescriptionController::class, 'forPatient']);
    Route::post('patients/{patient}/prescriptions', [PrescriptionController::class, 'store']);
    Route::get('patients/{patient}/timeline', PatientTimelineController::class);

    // Messaging & payment reminders
    Route::get('messages', [MessageController::class, 'index']);
    Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:messaging');
    Route::post('messages/preview', [MessageController::class, 'preview']);
    Route::get('message-templates', [MessageController::class, 'templates']);
    Route::post('message-templates', [MessageController::class, 'storeTemplate']);
    Route::put('message-templates/{template}', [MessageController::class, 'updateTemplate']);
    Route::get('payment-reminders', [PaymentReminderController::class, 'index']);
    Route::post('payment-reminders', [PaymentReminderController::class, 'send'])->middleware('throttle:messaging');

    // Staff self-service & HR
    Route::get('attendance/today', [AttendanceController::class, 'today']);
    Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('attendance', [AttendanceController::class, 'register']);
    Route::post('attendance', [AttendanceController::class, 'store']);
    Route::get('leave-requests', [LeaveController::class, 'index']);
    Route::post('leave-requests', [LeaveController::class, 'store']);
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve']);
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject']);
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);
    Route::get('leave-balances', [LeaveController::class, 'balances']);
    Route::get('leave-types', [LeaveController::class, 'types']);
    Route::post('leave-types', [LeaveController::class, 'storeType']);
    Route::put('leave-types/{leaveType}', [LeaveController::class, 'updateType']);
    Route::get('payroll/runs', [PayrollController::class, 'runs']);
    Route::post('payroll/runs', [PayrollController::class, 'storeRun']);
    Route::get('payroll/runs/{run}', [PayrollController::class, 'showRun']);
    Route::delete('payroll/runs/{run}', [PayrollController::class, 'destroyRun']);
    Route::post('payroll/runs/{run}/recalculate', [PayrollController::class, 'recalculate']);
    Route::post('payroll/runs/{run}/finalize', [PayrollController::class, 'finalize']);
    Route::post('payroll/runs/{run}/mark-paid', [PayrollController::class, 'markPaid']);
    Route::put('payslips/{payslip}', [PayrollController::class, 'updatePayslip']);
    Route::get('payslips/{payslip}/pdf', [PayrollController::class, 'payslipPdf']);
    Route::get('payroll/my-payslips', [PayrollController::class, 'myPayslips']);
    Route::get('payroll/salaries', [PayrollController::class, 'salaries']);
    Route::put('payroll/salaries/{user}', [PayrollController::class, 'updateSalary']);

    // Help & support
    Route::get('support/tickets', [SupportController::class, 'index']);
    Route::post('support/tickets', [SupportController::class, 'store'])->middleware('throttle:support');
    Route::get('support/tickets/{ticket}', [SupportController::class, 'show']);
    Route::post('support/tickets/{ticket}/messages', [SupportController::class, 'reply'])->middleware('throttle:support');
    Route::post('support/tickets/{ticket}/status', [SupportController::class, 'status']);

    // Reports
    Route::get('reports/summary', [ReportController::class, 'summary']);
    Route::get('reports/export', [ReportController::class, 'exportSummary']);
    Route::get('exports/patients', [ReportController::class, 'exportPatients']);
    Route::get('exports/invoices', [ReportController::class, 'exportInvoices']);
    Route::get('feedback', [ReportController::class, 'feedback']);
    Route::get('audit-logs', [ReportController::class, 'audit']);

    // Team & settings
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);
    Route::put('staff/{user}', [StaffController::class, 'update']);
    Route::get('staff/{user}/working-hours', [StaffController::class, 'workingHours']);
    Route::put('staff/{user}/working-hours', [StaffController::class, 'updateWorkingHours']);

    Route::prefix('settings')->group(function () {
        Route::get('clinic', [SettingsController::class, 'clinic']);
        Route::put('clinic', [SettingsController::class, 'updateClinic']);
        Route::get('branches', [SettingsController::class, 'branches']);
        Route::post('branches', [SettingsController::class, 'storeBranch']);
        Route::put('branches/{branch}', [SettingsController::class, 'updateBranch']);
        Route::get('rooms', [SettingsController::class, 'rooms']);
        Route::post('rooms', [SettingsController::class, 'storeRoom']);
        Route::put('rooms/{room}', [SettingsController::class, 'updateRoom']);
        Route::get('services', [SettingsController::class, 'services']);
        Route::post('services', [SettingsController::class, 'storeService']);
        Route::put('services/{service}', [SettingsController::class, 'updateService']);
        Route::get('package-plans', [SettingsController::class, 'packagePlans']);
        Route::post('package-plans', [SettingsController::class, 'storePackagePlan']);
        Route::put('package-plans/{plan}', [SettingsController::class, 'updatePackagePlan']);
        Route::get('insurance-payers', [SettingsController::class, 'payers']);
        Route::post('insurance-payers', [SettingsController::class, 'storePayer']);
        Route::put('insurance-payers/{payer}', [SettingsController::class, 'updatePayer']);
        Route::get('consent-templates', [SettingsController::class, 'consentTemplates']);
        Route::post('consent-templates', [SettingsController::class, 'storeConsentTemplate']);
        Route::put('consent-templates/{template}', [SettingsController::class, 'updateConsentTemplate']);
    });
});

/*
|--------------------------------------------------------------------------
| Patient app (PWA) — "patient" guard
|--------------------------------------------------------------------------
*/
Route::prefix('patient')->group(function () {
    Route::get('clinics/{slug}', [PortalAuthController::class, 'clinic'])->middleware('throttle:login');
    Route::post('auth/login', [PortalAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/activate', [PortalAuthController::class, 'activate'])->middleware('throttle:login');

    Route::middleware(['auth:patient', 'tenant:patient', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [PortalAuthController::class, 'logout']);
        Route::get('me', [PortalController::class, 'me']);
        Route::get('catalog', [PortalController::class, 'catalog']);
        Route::get('availability', [PortalController::class, 'availability']);
        Route::get('appointments', [PortalController::class, 'appointments']);
        Route::post('appointments', [PortalController::class, 'book'])->middleware('throttle:portal-booking');
        Route::post('appointments/{appointment}/cancel', [PortalController::class, 'cancel']);
        Route::get('treatment-plans', [PortalController::class, 'treatmentPlans']);
        Route::get('treatment-plans/{plan}/pdf', [PortalController::class, 'exerciseProgramPdf']);
        Route::post('exercise-logs', [PortalController::class, 'logExercise']);
        Route::get('progress', [PortalController::class, 'progress']);
        Route::post('progress', [PortalController::class, 'reportPain']);
        Route::get('invoices', [PortalController::class, 'invoices']);
        Route::get('invoices/{invoice}/pdf', [PortalController::class, 'invoicePdf']);
        Route::post('invoices/{invoice}/pay', [PortalController::class, 'payInvoice']);
        Route::get('consents', [PortalController::class, 'consents']);
        Route::post('consents/{template}/sign', [PortalController::class, 'signConsent']);
        Route::post('feedback', [PortalController::class, 'feedback']);
    });
});

/*
|--------------------------------------------------------------------------
| SYSNAC console — "platform" guard, no tenant context
|--------------------------------------------------------------------------
*/
Route::prefix('platform')->group(function () {
    Route::post('auth/login', [PlatformController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:platform', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [PlatformController::class, 'logout']);
        Route::get('auth/me', [PlatformController::class, 'me']);
        Route::get('metrics', [PlatformController::class, 'metrics']);
        Route::get('currencies', [PlatformController::class, 'currencies']);
        Route::get('tenants', [PlatformController::class, 'tenants']);
        Route::post('tenants', [PlatformController::class, 'createTenant']);
        Route::get('tenants/{tenant}', [PlatformController::class, 'showTenant']);
        Route::put('tenants/{tenant}', [PlatformController::class, 'updateTenant']);
        Route::get('audit-logs', [PlatformController::class, 'audit']);
        Route::get('support/tickets', [PlatformSupportController::class, 'index']);
        Route::get('support/tickets/{ticket}', [PlatformSupportController::class, 'show'])->whereNumber('ticket');
        Route::post('support/tickets/{ticket}/messages', [PlatformSupportController::class, 'reply'])->whereNumber('ticket');
        Route::post('support/tickets/{ticket}/status', [PlatformSupportController::class, 'status'])->whereNumber('ticket');
    });
});

// Website "request a callback" form for each clinic.
Route::post('public/clinics/{slug}/enquiries', [PublicEnquiryController::class, 'store'])->middleware('throttle:public-enquiry');

Route::post('webhooks/razorpay/{slug}', [WebhookController::class, 'razorpay'])->middleware('throttle:webhooks');
