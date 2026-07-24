<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

interface Normalizer
{
    public function normalize(string|int|float|bool|null $value): string|int|float|bool|null;
}
