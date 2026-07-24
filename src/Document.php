<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

final class Document
{
    public function __construct(
        private readonly mixed $value,
        private readonly string $origin
    ) {
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function origin(): string
    {
        return $this->origin;
    }
}
