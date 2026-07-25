<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A provider call failed in a way worth showing the user.
 *
 * Messages are written for a designer, not an SRE, and never echo the
 * API key back.
 */
class AiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 502)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
