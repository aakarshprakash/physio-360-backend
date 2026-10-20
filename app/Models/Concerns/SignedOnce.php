<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

/** Once signed_at is set the row is frozen (mirrored by DB triggers). */
trait SignedOnce
{
    public static function bootSignedOnce(): void
    {
        $guard = function (Model $m) {
            if ($m->getOriginal('signed_at') !== null) {
                throw new ImmutableRecord('Signed '.class_basename($m).' records cannot be changed. Add an addendum instead.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
