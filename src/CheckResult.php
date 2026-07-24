<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

final class CheckResult
{
    /**
     * @param array<string, string|int|float|bool|null> $mismatches
     */
    public function __construct(
        public readonly string $name,
        public readonly string|int|float|bool|null $expected,
        public readonly array $mismatches
    ) {
    }

    public function passed(): bool
    {
        return $this->mismatches === [];
    }
}
