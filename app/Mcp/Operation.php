<?php

namespace App\Mcp;

use Closure;

final readonly class Operation
{
    public function __construct(
        public string $name, public string $description, public string $scope,
        public array $schema, public array $rules, public ?Closure $read = null,
        public ?Closure $snapshot = null, public ?Closure $preview = null,
        public ?Closure $apply = null, public bool $external = false, public bool $destructive = false,
    ) {}
}
