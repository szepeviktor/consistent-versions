<?php

declare(strict_types=1);

use SzepeViktor\ConsistentVersions\Engine;
use SzepeViktor\ConsistentVersions\Exception\ParseException;
use SzepeViktor\ConsistentVersions\Exception\SelectionException;
use SzepeViktor\ConsistentVersions\Normalizer\NormalizerRegistry;
use SzepeViktor\ConsistentVersions\Reader\ReaderRegistry;
use SzepeViktor\ConsistentVersions\Selector;

require __DIR__ . '/../vendor/autoload.php';

$fixtures = __DIR__ . '/fixtures';
$readers = ReaderRegistry::withDefaults();
$selector = new Selector();
$tests = 0;

$same = static function (mixed $expected, mixed $actual, string $message) use (&$tests): void {
    ++$tests;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual:   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

$value = static function (string $reader, string $file, string $path) use ($readers, $selector, $fixtures): mixed {
    return $selector->select($readers->get($reader)->read($fixtures . '/' . $file), $path);
};

$same('1.2.3', $value('json', 'data.json', '$.package.version'), 'JSON reader');
$same('8.1', $value('ini', 'settings.ini', '$.PHP_VERSION'), 'INI reader');
$same('2.0.0', $value('ini', 'settings.ini', '$.tool.version'), 'INI section');
$same('^8.1', $value('composer', 'composer.json', '$.require.php'), 'Composer reader');
$same('8.1', $value('yaml', 'data.yaml', '$.jobs.tests.strategy.matrix.php[0]'), 'YAML reader');
$same(80100, $value('neon', 'phpstan.neon', '$.parameters.phpVersion'), 'NEON reader');
$same('8.1-', $value('phpcs', 'phpcs.xml', '$.config.testVersion'), 'PHPCS reader');
$same('true', $value('xml', 'document.xml', '$.attributes.active'), 'XML reader attributes');
$same('1.2.3', $value('xml', 'document.xml', '$.children[?@.name == "version"].text'), 'XML JSONPath filter');
$same(
    'two',
    $value('xml', 'document.xml', '$.children[?@.name == "component" && @.text == "two"].text'),
    'JSONPath boolean filter'
);
$same('1.2.3', $value('html', 'document.html', '$.meta.version'), 'HTML metadata');
$same('1.2.3', $value('wordpress-plugin', 'plugin.php', '$.Version'), 'WordPress plugin header');
$same('1.2.3', $value('wordpress-theme', 'style.css', '$.Version'), 'WordPress theme header');
$same('1.2.3', $value('wordpress-readme', 'readme.txt', "$['Stable tag']"), 'WordPress readme');
$same('1.2.3', $value('markdown', 'README.md', '$.frontMatter.version'), 'Markdown front matter');
$same('1.2.3', $value('markdown', 'README.md', '$.html.meta.release'), 'HTML metadata in Markdown');
$same(
    '1.2.3',
    $value('php', 'constants.php', "$.classes['Example\\\\Plugin'].constants.VERSION"),
    'PHP class constant'
);
$same('1.2.3', $value('php', 'constants.php', "$.constants['Example\\\\PACKAGE_VERSION']"), 'PHP global constant');
$same('1.2.3', $value('text', 'VERSION', '$'), 'Text reader');
$same('1.2.3', $value('json', 'data.json', '$..version'), 'JSONPath recursive descent');

$tagVariable = 'CONSISTENT_VERSIONS_TEST_TAG';
putenv($tagVariable . '=v1.2.3');
$same('v1.2.3', $selector->select($readers->get('env')->read($tagVariable), '$'), 'Environment reader');

$normalizers = NormalizerRegistry::withDefaults();
$same('8.1', $normalizers->normalize('^8.1', ['composer-minimum']), 'Composer lower bound');
$same('8.1', $normalizers->normalize(80100, ['php-version-id']), 'PHP version ID');
$same('8.1.2', $normalizers->normalize('80102', ['php-version-id']), 'PHP version ID with patch');
$same('1.2.3', $normalizers->normalize('v1.2.3', ['trim-v-prefix', 'version']), 'Version normalization');

$configuration = [
    'checks' => [
        'release-version' => [
            'normalize' => 'version',
            'expected' => ['reader' => 'text', 'file' => 'VERSION'],
            'values' => [
                'plugin header' => ['reader' => 'wordpress-plugin', 'file' => 'plugin.php', 'path' => '$.Version'],
                'readme stable tag' => [
                    'reader' => 'wordpress-readme',
                    'file' => 'readme.txt',
                    'path' => "$['Stable tag']",
                ],
                'PHP constant' => [
                    'reader' => 'php',
                    'file' => 'constants.php',
                    'path' => "$.classes['Example\\\\Plugin'].constants.VERSION",
                ],
                'CI tag' => [
                    'reader' => 'env',
                    'variable' => $tagVariable,
                    'normalize' => 'trim-v-prefix',
                ],
            ],
        ],
        'minimum-php' => [
            'normalize' => 'version',
            'expected' => '8.1',
            'values' => [
                'CI' => [
                    'reader' => 'yaml',
                    'file' => 'data.yaml',
                    'path' => '$.jobs.tests.strategy.matrix.php[0]',
                ],
                'PHPStan' => [
                    'reader' => 'neon',
                    'file' => 'phpstan.neon',
                    'path' => '$.parameters.phpVersion',
                    'normalize' => 'php-version-id',
                ],
                'plugin header' => [
                    'reader' => 'wordpress-plugin',
                    'file' => 'plugin.php',
                    'path' => "$['Requires PHP']",
                ],
                'Composer constraint' => [
                    'reader' => 'composer',
                    'file' => 'composer.json',
                    'path' => '$.require.php',
                    'normalize' => 'composer-minimum',
                ],
            ],
        ],
    ],
];

$report = (new Engine())->run($configuration, $fixtures);
$same(true, $report->passed(), 'Passing consistency report');

$configuration['checks']['release-version']['values']['literal mismatch'] = '2.0.0';
$report = (new Engine())->run($configuration, $fixtures);
$same(false, $report->passed(), 'Failing consistency report');
$same(1, $report->failureCount(), 'Mismatch count');

try {
    $selector->select($readers->get('xml')->read($fixtures . '/document.xml'), '$.children[*].name');
    throw new RuntimeException('Selector should reject multiple values');
} catch (SelectionException) {
    ++$tests;
}

putenv('CONSISTENT_VERSIONS_TEST_MISSING');
try {
    $readers->get('env')->read('CONSISTENT_VERSIONS_TEST_MISSING');
    throw new RuntimeException('Environment reader should reject a missing variable');
} catch (ParseException) {
    ++$tests;
}
putenv($tagVariable);

fwrite(STDOUT, sprintf("OK (%d assertions)\n", $tests));
