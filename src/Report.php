<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

final class Report
{
    /**
     * @param list<CheckResult> $results
     */
    public function __construct(public readonly array $results)
    {
    }

    public function passed(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->passed()) {
                return false;
            }
        }

        return true;
    }

    public function failureCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            $count += count($result->mismatches);
        }

        return $count;
    }
}
