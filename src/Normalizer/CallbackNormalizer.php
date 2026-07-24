<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use Closure;

final class CallbackNormalizer implements Normalizer
{
    /**
     * @param Closure(string|int|float|bool|null): (string|int|float|bool|null) $callback
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    public function normalize(string|int|float|bool|null $value): string|int|float|bool|null
    {
        return ($this->callback)($value);
    }
}
