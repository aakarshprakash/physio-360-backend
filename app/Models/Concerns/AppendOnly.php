<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

/** Rows can be inserted but never updated or deleted (mirrored by DB triggers). */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn (Model $m) => throw new ImmutableRecord(class_basename($m).' is append-only.'));
        static::deleting(fn (Model $m) => throw new ImmutableRecord(class_basename($m).' is append-only.'));
    }
}
