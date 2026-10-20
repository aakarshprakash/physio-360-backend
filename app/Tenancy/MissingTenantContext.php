<?php

namespace App\Tenancy;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is set; tenant-owned data cannot be accessed.');
    }
}
