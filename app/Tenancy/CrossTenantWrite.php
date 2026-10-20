<?php

namespace App\Tenancy;

use LogicException;

class CrossTenantWrite extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Refusing to write {$model} outside the current tenant.");
    }
}
