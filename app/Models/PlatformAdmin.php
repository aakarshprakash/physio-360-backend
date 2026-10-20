<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** SYSNAC console operator. Not tenant-owned. */
class PlatformAdmin extends Authenticatable
{
    use HasFactory;

    protected $guarded = ['id', 'password', 'remember_token'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime'];
    }
}
