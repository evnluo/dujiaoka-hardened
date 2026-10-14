<?php

namespace App\Mcp;

final class OperationException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly array $details = [])
    {
        parent::__construct($reasonCode);
    }
}
