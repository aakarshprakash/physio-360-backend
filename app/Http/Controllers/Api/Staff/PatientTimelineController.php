<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\Clinical\PatientTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Staff · Patients
 */
class PatientTimelineController extends Controller
{
    public const TYPES = ['registered', 'appointment', 'assessment', 'note', 'plan', 'prescription', 'progress', 'document', 'consent', 'invoice', 'payment', 'message'];

    /** Everything that happened to the patient, newest first, filtered to what the viewer may see. */
    public function __invoke(Request $request, Patient $patient, PatientTimeline $timeline): JsonResponse
    {
        Gate::authorize('view', $patient);
        $request->validate([
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(PatientTimelineController::TYPES)],
        ]);

        $events = $timeline->for($patient, $request->user(), $request->input('types'));

        return response()->json([
            'data' => $events,
            'counts' => collect($events)->countBy('type'),
        ]);
    }
}
