<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Staff · Exercise library
 */
class ExerciseController extends Controller
{
    public const REGIONS = ['knee', 'shoulder', 'neck', 'back', 'hip', 'ankle', 'wrist', 'elbow', 'core', 'general'];

    public const CATEGORIES = ['strengthening', 'flexibility', 'mobility', 'stability', 'balance', 'posture', 'cardio', 'functional'];

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(['clinical.view', 'exercises.manage']), 403);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'body_region' => ['nullable', Rule::in(ExerciseController::REGIONS)],
            'category' => ['nullable', Rule::in(ExerciseController::CATEGORIES)],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        return ExerciseResource::collection(
            Exercise::query()
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
                ->when($request->filled('body_region'), fn ($q) => $q->where('body_region', $request->input('body_region')))
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
                ->orderBy('body_region')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('exercises.manage');
        $exercise = Exercise::query()->create($this->validated($request));

        return (new ExerciseResource($exercise))->response()->setStatusCode(201);
    }

    public function update(Request $request, Exercise $exercise): ExerciseResource
    {
        Gate::authorize('exercises.manage');
        $exercise->update($this->validated($request, $exercise));

        return new ExerciseResource($exercise);
    }

    private function validated(Request $request, ?Exercise $exercise = null): array
    {
        $required = $exercise ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255', Rule::unique('exercises', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($exercise?->id)],
            'body_region' => [$required, Rule::in(ExerciseController::REGIONS)],
            'category' => [$required, Rule::in(ExerciseController::CATEGORIES)],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'description' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'default_sets' => ['sometimes', 'integer', 'between:1,20'],
            'default_reps' => ['nullable', 'integer', 'between:1,200'],
            'default_hold_seconds' => ['nullable', 'integer', 'between:1,600'],
            'video_url' => ['nullable', 'url:https', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
