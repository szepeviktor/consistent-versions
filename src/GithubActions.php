<?php

declare(strict_types=1);

namespace SzepeViktor\CheckVersions;

use function basename;

class GithubActions extends Yaml
{
    use Assert;

    public function __construct(string $path)
    {
        $this->id = 'GitHub Actions:' . basename($path);
        parent::__construct($path);
    }

    public function getJob(string $jobName): array
    {
        return $this->document['jobs'][$jobName] ?? [];
    }
}
