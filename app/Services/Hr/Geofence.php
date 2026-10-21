<?php

namespace App\Services\Hr;

use App\Exceptions\DomainRuleViolation;
use App\Models\Branch;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Geofenced clock-in: when the clinic turns it on, staff can only clock in or
 * out within a radius of one of their branches. GPS accuracy (capped) is
 * added to the radius so a weak fix just inside the door is not rejected.
 * Staff marked "geofence exempt" (home visits, field work) are never blocked,
 * but their location is still recorded.
 */
class Geofence
{
    /** Never trust a reported accuracy beyond this when widening the fence. */
    public const MAX_ACCURACY_ALLOWANCE_M = 100;

    public function __construct(private readonly TenantContext $context) {}

    public function enabled(): bool
    {
        return (bool) $this->context->require()->setting('attendance_geofence', false);
    }

    public function requiredFor(User $user): bool
    {
        return $this->enabled() && ! $user->geofence_exempt;
    }

    /** Branches with a pinned location the user may clock in at. */
    public function sitesFor(User $user): Collection
    {
        $mine = Branch::query()->where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereIn('id', $user->branches()->pluck('branches.id'))->get();

        return $mine->isNotEmpty() ? $mine
            : Branch::query()->where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')->get();
    }

    /**
     * Checks a location against the user's sites. Throws when the fence is
     * enforced and the user is outside it.
     *
     * @param  array{latitude: float, longitude: float, accuracy?: ?float}|null  $location
     * @return array{branch: ?Branch, distance: ?int, inside: ?bool}
     */
    public function check(User $user, ?array $location): array
    {
        $required = $this->requiredFor($user);
        $sites = $this->sitesFor($user);

        if ($location === null) {
            if ($required && $sites->isNotEmpty()) {
                throw new DomainRuleViolation('Your clinic requires location to clock in. Allow location access and try again.', 'location_required');
            }

            return ['branch' => null, 'distance' => null, 'inside' => null];
        }

        if ($sites->isEmpty()) {
            return ['branch' => null, 'distance' => null, 'inside' => null];
        }

        /** @var Branch $nearest */
        $nearest = $sites->sortBy(fn (Branch $b) => self::distance($location['latitude'], $location['longitude'], $b->latitude, $b->longitude))->first();
        $distance = self::distance($location['latitude'], $location['longitude'], $nearest->latitude, $nearest->longitude);
        $allowance = min((float) ($location['accuracy'] ?? 0), self::MAX_ACCURACY_ALLOWANCE_M);
        $inside = $distance <= $nearest->geofence_radius_m + $allowance;

        if ($required && ! $inside) {
            throw new DomainRuleViolation(
                "You are about {$this->human($distance)} from {$nearest->name}. Clock-in is allowed within {$nearest->geofence_radius_m} m of the clinic.",
                'outside_geofence',
                ['distance_m' => $distance, 'radius_m' => $nearest->geofence_radius_m, 'branch' => $nearest->name],
            );
        }

        return ['branch' => $nearest, 'distance' => $distance, 'inside' => $inside];
    }

    /** Great-circle distance in whole metres (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function human(int $metres): string
    {
        return $metres >= 1000 ? round($metres / 1000, 1).' km' : $metres.' m';
    }
}
