<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Console;

use SzepeViktor\ConsistentVersions\ConfigurationLoader;
use SzepeViktor\ConsistentVersions\Engine;
use SzepeViktor\ConsistentVersions\Exception\ConsistentVersionsException;
use SzepeViktor\ConsistentVersions\Report;
use Throwable;

final class Application
{
    public function __construct(
        private readonly ConfigurationLoader $loader = new ConfigurationLoader(),
        private readonly Engine $engine = new Engine()
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $command = $arguments[1] ?? 'check';
        if ($command === '--help' || $command === '-h' || $command === 'help') {
            $this->help();
            return 0;
        }
        if ($command !== 'check') {
            fwrite(STDERR, sprintf("Unknown command: %s\n\n", $command));
            $this->help(STDERR);
            return 2;
        }

        $configurationPath = $arguments[2] ?? 'consistent-versions.yaml';
        try {
            $configuration = $this->loader->load($configurationPath);
            $baseDirectory = dirname($this->realOrOriginalPath($configurationPath));
            $report = $this->engine->run($configuration, $baseDirectory);
        } catch (ConsistentVersionsException $exception) {
            fwrite(STDERR, sprintf("ERROR: %s\n", $exception->getMessage()));
            return 2;
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("UNEXPECTED ERROR: %s\n", $exception->getMessage()));
            return 2;
        }

        $this->report($report);
        return $report->passed() ? 0 : 1;
    }

    /**
     * @param resource $stream
     */
    private function help($stream = STDOUT): void
    {
        fwrite($stream, <<<'HELP'
Consistent Versions

Usage:
  consistent-versions check [configuration.yaml]

Exit codes:
  0  all values are consistent
  1  one or more values differ
  2  configuration, parsing, or selection error

HELP);
    }

    private function report(Report $report): void
    {
        foreach ($report->results as $result) {
            if ($result->passed()) {
                fwrite(STDOUT, sprintf("PASS  %s\n", $result->name));
                continue;
            }

            fwrite(STDOUT, sprintf("FAIL  %s\n", $result->name));
            fwrite(STDOUT, sprintf("      expected: %s\n", $this->display($result->expected)));
            foreach ($result->mismatches as $label => $value) {
                fwrite(STDOUT, sprintf("      %s: %s\n", $label, $this->display($value)));
            }
        }

        fwrite(STDOUT, $report->passed()
            ? sprintf("\nOK (%d checks)\n", count($report->results))
            : sprintf("\n%d inconsistent value(s)\n", $report->failureCount()));
    }

    private function display(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }

    private function realOrOriginalPath(string $path): string
    {
        $realPath = realpath($path);
        return $realPath === false ? $path : $realPath;
    }
}
