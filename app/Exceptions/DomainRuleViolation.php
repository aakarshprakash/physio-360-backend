<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule (invalid state
 * transition, over-payment, ...). Rendered as HTTP 422 with a stable code.
 */
class DomainRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation', public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
