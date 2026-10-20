<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSeries extends Model
{
    use BelongsToTenant;

    protected $table = 'appointment_series';

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['frequency' => 'weekly', 'interval' => 1];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'by_weekdays' => 'array',
            'starts_on' => 'date',
            'until_on' => 'date',
            'interval' => 'integer',
            'occurrences' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'series_id');
    }
}
