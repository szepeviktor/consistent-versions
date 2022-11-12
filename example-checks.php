<?php

use SzepeViktor\CheckVersions\Engine;
use SzepeViktor\CheckVersions\GithubActions;

require __DIR__ . '/vendor/autoload.php';

$phpLowestVersion = '8.0';

Engine::boot();

$integrate = new GithubActions(__DIR__ . '/.github/workflows/integrate.yml');
// @TODO All steps
$integrate->assertEqual(
    $integrate->getJob('run-all')['steps'][0]['with']['php-version'] ?? '',
    $phpLowestVersion
);


function dot($array, $prepend = '')
{
    $results = [];

    foreach ($array as $key => $value) {
        if (is_array($value) && ! empty($value)) {
            $results = array_merge($results, dot($value, $prepend.$key.'.'));
        } else {
            $results[$prepend.$key] = $value;
        }
    }

    return $results;
}
