<?php

namespace Osmianski\WorktreeManager\Exception;

use RuntimeException;
use Throwable;

class WorktreeException extends RuntimeException
{
    public function __construct(string $message = "", protected string $description = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
