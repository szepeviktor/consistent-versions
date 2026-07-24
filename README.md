<meta name="requires-php" content="8.1">

# Consistent Versions

Consistent Versions checks that version-related values stored in different
project files agree.

It is designed for projects where the same information appears in several
places, for example:

- a WordPress plugin header;
- a WordPress.org `readme.txt`;
- `composer.json`;
- a GitHub Actions workflow;
- a PHPStan NEON configuration;
- a PHPCS ruleset;
- an INI or simple `.env` file;
- a PHP class constant;
- Markdown documentation;
- a plain `VERSION` file.

The tool only reads and compares configured sources. It never rewrites files
or changes environment variables.

## Table of contents

- [Why use it?](#why-use-it)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start for a WordPress plugin](#quick-start-for-a-wordpress-plugin)
- [Running the checker](#running-the-checker)
- [Configuration reference](#configuration-reference)
- [JSONPath selectors](#jsonpath-selectors)
- [Readers](#readers)
- [Normalizers](#normalizers)
- [Complete WordPress example](#complete-wordpress-example)
- [GitHub Actions integration](#github-actions-integration)
- [Using the library API](#using-the-library-api)
- [Extending the package](#extending-the-package)
- [Errors and exit codes](#errors-and-exit-codes)
- [Security and limitations](#security-and-limitations)
- [Development](#development)
- [License](#license)

## Why use it?

A WordPress plugin release may contain the same plugin version in all of these
files:

```text
VERSION
plugin.php
readme.txt
composer.json
src/Plugin.php
```

The minimum supported PHP version may also appear in:

```text
composer.json
plugin.php
readme.txt
.github/workflows/tests.yml
phpcs.xml
```

It is easy to update four places and forget the fifth. Consistent Versions
makes that mistake fail locally or in CI before a release is published.

Typical mistakes it detects include:

- `VERSION` contains `1.4.0`, but the plugin header still contains `1.3.0`;
- the WordPress readme has the wrong `Stable tag`;
- Composer requires PHP 8.1, but CI starts its test matrix at PHP 8.2;
- the plugin header and WordPress readme declare different minimum PHP versions;
- a PHP class constant was not updated for a release.

## How it works

Each supported source is read by a format-aware **reader**. The reader converts
the source into a virtual JSON-like document.

For example, this WordPress plugin header:

```php
<?php
/**
 * Plugin Name: Example Plugin
 * Version: 1.2.3
 * Requires PHP: 8.1
 */
```

is exposed as data similar to:

```json
{
    "Plugin Name": "Example Plugin",
    "Version": "1.2.3",
    "Requires PHP": "8.1"
}
```

A [JSONPath](https://www.rfc-editor.org/rfc/rfc9535.html) selector such as `$.Version` selects the required scalar value.

The checking process is:

1. Read the `expected` value.
2. Read every configured value under `values`.
3. Apply source-specific normalizers.
4. Apply check-level normalizers.
5. Compare the normalized values using strict equality.
6. Report every value that differs.

Selectors must return exactly one scalar value: a string, number, boolean, or
`null`. Selecting nothing, several values, an array, or an object is an error.

## Requirements

- PHP 8.1 or newer
- Composer

The runtime package has no `ext-*` Composer dependency.

XML, HTML, PHP constants, and WordPress headers are parsed without loading or
executing the inspected files. The package can run without optional PHP
extensions.

PHPStan is a development-only dependency and uses the Phar support included in
normal PHP CLI installations.

## Installation

Install the package as a development dependency:

```bash
composer require --dev szepeviktor/consistent-versions
```

The executable becomes available at:

```text
vendor/bin/consistent-versions
```

## Quick start for a WordPress plugin

Assume the project contains the following files.

### `VERSION`

```text
1.2.3
```

### `plugin.php`

```php
<?php
/**
 * Plugin Name: Example Plugin
 * Version: 1.2.3
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */
```

### `readme.txt`

```text
=== Example Plugin ===
Contributors: example
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.2.3
License: MIT

== Description ==

Example plugin.
```

### `src/Plugin.php`

```php
<?php

declare(strict_types=1);

namespace Vendor;

final class Plugin
{
    public const VERSION = '1.2.3';
}
```

Create `consistent-versions.yaml` in the project root:

```yaml
checks:
  plugin-version:
    normalize: version

    expected:
      reader: text
      file: VERSION

    values:
      plugin-header:
        reader: wordpress-plugin
        file: plugin.php
        path: $.Version

      wordpress-readme:
        reader: wordpress-readme
        file: readme.txt
        path: $['Stable tag']

      php-constant:
        reader: php
        file: src/Plugin.php
        path: $.classes['Vendor\\Plugin'].constants.VERSION
```

Run:

```bash
vendor/bin/consistent-versions check
```

Successful output:

```text
PASS  plugin-version

OK (1 checks)
```

If `readme.txt` still contains `1.2.2`, the output is:

```text
FAIL  plugin-version
      expected: 1.2.3
      wordpress-readme: 1.2.2

1 inconsistent value(s)
```

## Running the checker

The default command reads `consistent-versions.yaml` from the current working
directory:

```bash
vendor/bin/consistent-versions check
```

Use a different YAML configuration:

```bash
vendor/bin/consistent-versions check config/versions.yaml
```

JSON configuration is also supported when the filename ends in `.json`:

```bash
vendor/bin/consistent-versions check config/versions.json
```

Display help:

```bash
vendor/bin/consistent-versions --help
```

All source file paths are resolved relative to the configuration file, not
relative to the current working directory. Absolute paths are accepted too.

## Configuration reference

The configuration root must contain a non-empty `checks` map:

```yaml
checks:
  check-name:
    expected: ...
    values:
      value-name: ...
```

### Check definition

Each check supports these fields:

| Field | Required | Description |
| --- | --- | --- |
| `expected` | yes | The canonical source or a literal scalar value |
| `values` | yes | A non-empty named map of values to compare |
| `normalize` | no | One check-level normalizer or a list of normalizers |

The names under `checks` and `values` are report labels. They do not affect
selection or comparison.

### Expected value

`expected` may be a source:

```yaml
expected:
  reader: text
  file: VERSION
```

or a literal scalar:

```yaml
expected: "8.1"
```

Quoting version-like YAML values is recommended. Without quotes, YAML may
interpret values such as `8.1` as numbers.

### Source definition

A source supports:

| Field | Required | Default | Description |
| --- | --- | --- | --- |
| `reader` | yes | — | Reader name |
| `file` | for file readers | — | File path relative to the configuration |
| `variable` | for `env` | — | Environment variable name |
| `path` | no | `$` | JSONPath selecting exactly one scalar |
| `normalize` | no | none | Source-specific normalizer or list |

Example:

```yaml
reader: composer
file: composer.json
path: $.require.php
normalize: composer-minimum
```

Normalizers may be written as one string:

```yaml
normalize: version
```

or as an ordered list:

```yaml
normalize:
  - trim
  - trim-v-prefix
  - version
```

Source-specific normalizers run first. Check-level normalizers run afterwards.

### Comparison

After normalization, values are compared using strict equality.

This means the string `"8.1"` and the number `8.1` are different unless a
normalizer converts them to the same representation.

Use `string` or `version` when values may be represented with different scalar
types or version formats.

## JSONPath selectors

Consistent Versions implements a dependency-free, read-only subset of
[RFC 9535 JSONPath](https://www.rfc-editor.org/rfc/rfc9535.html).

### Supported syntax

| Syntax | Meaning | Example |
| --- | --- | --- |
| `$` | Document root | `$` |
| `@` | Current filter item | `@.name` |
| `.name` | Object property | `$.require.php` |
| `['name']` | Quoted property | `$['Stable tag']` |
| `[0]` | Array index | `$.matrix.php[0]` |
| `[-1]` | Index from the end | `$.versions[-1]` |
| `.*` or `[*]` | Wildcard | `$.items[*].version` |
| `..name` | Recursive descent | `$..version` |
| `[?expression]` | Filter | `$.children[?@.name == "version"]` |

Supported filter operators:

```text
==
!=
<
>
<=
>=
&&
||
!
```

Example XML selector:

```yaml
path: $.children[?@.name == "version"].text
```

Example filter with two conditions:

```yaml
path: $.children[?@.name == "config" && @.attributes.name == "testVersion"].attributes.value
```

String ordering comparisons only compare strings with strings. Numeric ordering
comparisons only compare numbers with numbers. The tool does not perform loose
PHP comparison inside filters.

### Property names containing spaces or punctuation

Use bracket notation:

```yaml
path: $['Requires PHP']
```

### Backslashes in class names

Inside a YAML single-quoted scalar, use two backslashes in the JSONPath string:

```yaml
path: $.classes['Vendor\\Plugin'].constants.VERSION
```

This selects the PHP class `Vendor\Plugin`.

### Unsupported JSONPath features

The following features are intentionally not implemented:

- unions;
- array slices;
- functions;
- regular-expression filters;
- update or mutation expressions.

Stable project configuration should normally use direct, deterministic paths.

## Readers

Every reader returns a virtual JSON-like document. Use JSONPath to select a
value from that document.

### `env`

Reads one explicitly named environment variable. It does not expose the rest
of the process environment.

```yaml
reader: env
variable: GITHUB_REF_NAME
path: $
```

The virtual document is the variable's string value. The default `$` path is
therefore normally sufficient. An unset variable is a parsing error; a set but
empty variable is a valid empty string.

For a release tag such as `v1.2.3`, normalizers can remove the prefix and
validate the version:

```yaml
reader: env
variable: GITHUB_REF_NAME
normalize:
  - trim-v-prefix
  - version
```

### `ini`

Parses PHP INI syntax using raw value scanning. It works with `.ini` files and
simple `.env` files that contain `KEY=value` assignments.

Input:

```ini
PHP_VERSION="8.1"
PLUGIN_VERSION="1.2.3"

[tool]
version="2.0.0"
```

Selectors:

```yaml
# Top-level value
path: $.PHP_VERSION

# Value inside an INI section
path: $.tool.version
```

A complete source definition for `.env` is:

```yaml
reader: ini
file: .env
path: $.PHP_VERSION
```

`INI_SCANNER_RAW` keeps every value as a string, so `8.1` remains `"8.1"`.
The reader does not copy parsed values into the process environment.

This is an INI reader, not a complete Dotenv implementation. Dotenv-specific
features such as `export KEY=value`, shell expansion, and complex multiline
values are not supported.

### `text`

Reads the entire file and applies `trim()`.

Input:

```text
1.2.3
```

Virtual document:

```json
"1.2.3"
```

Selector:

```yaml
path: $
```

This reader is suitable for `VERSION` and similar one-value files.

### `json`

Parses arbitrary JSON into objects, arrays, and scalar values.

Input:

```json
{
    "package": {
        "version": "1.2.3"
    }
}
```

Selector:

```yaml
path: $.package.version
```

Invalid JSON is a parsing error.

### `composer`

Parses `composer.json`. Its virtual document is the same as the `json` reader,
but the root must be a JSON object.

Project version:

```yaml
reader: composer
file: composer.json
path: $.version
```

PHP constraint:

```yaml
reader: composer
file: composer.json
path: $.require.php
normalize: composer-minimum
```

### `yaml`

Parses YAML into objects, arrays, and scalar values.

GitHub Actions input:

```yaml
jobs:
  tests:
    strategy:
      matrix:
        php:
          - "8.1"
          - "8.2"
```

Selector for the lowest tested PHP version:

```yaml
path: $.jobs.tests.strategy.matrix.php[0]
```

### `neon`

Parses a NEON file into objects, arrays, and scalar values. It uses the native
NEON parser rather than treating NEON as YAML.

For example, PHPStan may declare its target PHP version as a
[`PHP_VERSION_ID`](https://www.php.net/manual/en/reserved.constants.php):

```neon
parameters:
    phpVersion: 80100
    level: max
```

The raw integer is available at:

```yaml
reader: neon
file: phpstan.neon
path: $.parameters.phpVersion
```

To compare it with values such as `8.1`, apply the `php-version-id`
normalizer:

```yaml
reader: neon
file: phpstan.neon
path: $.parameters.phpVersion
normalize: php-version-id
```

This reader parses the contents of the selected file only. It does not merge
files referenced by PHPStan's `includes` setting or apply PHPStan defaults.

### `xml`

Parses XML into a uniform node tree. Every node exposes:

```json
{
    "name": "element-name",
    "attributes": {
        "attribute-name": "attribute-value"
    },
    "children": [],
    "text": "element text"
}
```

Children are always a list, even if there is only one child. This avoids the
common ambiguity where one XML element becomes an object but repeated elements
become an array.

Input:

```xml
<project active="true">
    <version channel="stable">1.2.3</version>
</project>
```

Selectors:

```yaml
# Root attribute
path: $.attributes.active

# Child text
path: $.children[?@.name == "version"].text

# Child attribute
path: $.children[?@.name == "version"].attributes.channel
```

External entities are never loaded.

### `phpcs`

Parses a PHPCS XML ruleset and presents the commonly needed information in a
simpler semantic document:

```json
{
    "name": "Project",
    "description": "Project coding standard",
    "config": {
        "testVersion": "8.1-"
    },
    "arguments": [],
    "rules": []
}
```

Input:

```xml
<ruleset name="Project">
    <description>Project coding standard</description>
    <config name="testVersion" value="8.1-"/>
    <rule ref="PSR12"/>
</ruleset>
```

Selector:

```yaml
path: $.config.testVersion
```

### `wordpress-plugin`

Reads the first 8192 bytes of a WordPress plugin file and exposes these header
fields:

| Field |
| --- |
| `Plugin Name` |
| `Plugin URI` |
| `Description` |
| `Version` |
| `Requires at least` |
| `Requires PHP` |
| `Author` |
| `Author URI` |
| `License` |
| `License URI` |
| `Text Domain` |
| `Domain Path` |
| `Network` |
| `Update URI` |
| `Requires Plugins` |

Example selectors:

```yaml
path: $.Version
```

```yaml
path: $['Requires PHP']
```

Missing known headers are exposed as `null`.

### `wordpress-theme`

Reads the first 8192 bytes of a WordPress theme `style.css` and exposes:

| Field |
| --- |
| `Theme Name` |
| `Theme URI` |
| `Author` |
| `Author URI` |
| `Description` |
| `Version` |
| `Requires at least` |
| `Tested up to` |
| `Requires PHP` |
| `License` |
| `License URI` |
| `Text Domain` |
| `Tags` |
| `Template` |
| `Update URI` |

Example:

```yaml
reader: wordpress-theme
file: style.css
path: $.Version
```

### `wordpress-readme`

Parses the header of a WordPress.org `readme.txt` and exposes:

| Field |
| --- |
| `Name` |
| `Contributors` |
| `Donate link` |
| `Tags` |
| `Requires at least` |
| `Tested up to` |
| `Requires PHP` |
| `Stable tag` |
| `License` |
| `License URI` |

Example:

```yaml
reader: wordpress-readme
file: readme.txt
path: $['Stable tag']
```

Parsing stops when the first section such as `== Description ==` begins.

### `php`

Reads global constants and class constants without including or executing the
PHP file.

Input:

```php
<?php

namespace Vendor;

const PACKAGE_VERSION = '1.2.3';

final class Plugin
{
    public const VERSION = '1.' . '2.3';
}
```

Virtual document:

```json
{
    "constants": {
        "Vendor\\PACKAGE_VERSION": "1.2.3"
    },
    "classes": {
        "Vendor\\Plugin": {
            "constants": {
                "VERSION": "1.2.3"
            }
        }
    }
}
```

Selectors:

```yaml
path: $.constants['Vendor\\PACKAGE_VERSION']
```

```yaml
path: $.classes['Vendor\\Plugin'].constants.VERSION
```

The PHP reader understands scalar literal constants and concatenated scalar
literals. It intentionally does not evaluate function calls, variables, class
constant references, or arbitrary PHP expressions.

Calls to `define()` with literal names and literal scalar values are also read.

### `html`

Parses HTML and exposes:

```json
{
    "meta": {},
    "headings": [],
    "links": [],
    "tree": {}
}
```

Input:

```html
<meta name="version" content="1.2.3">
<h1>Example Plugin</h1>
<a href="/releases/1.2.3">Release</a>
```

Example selectors:

```yaml
path: $.meta.version
```

```yaml
path: $.headings[0].text
```

```yaml
path: $.links[0].url
```

The full parsed markup is available under `$.tree` using the same node model as
the `xml` reader.

### `markdown`

Parses useful structural information from Markdown:

```json
{
    "frontMatter": {},
    "headings": [],
    "links": [],
    "images": [],
    "html": {},
    "text": "original Markdown"
}
```

Markdown front matter:

```markdown
---
version: 1.2.3
requires_php: "8.1"
---

# Example Plugin
```

Selectors:

```yaml
path: $.frontMatter.version
```

```yaml
path: $.frontMatter.requires_php
```

Embedded HTML metadata is parsed through the HTML reader:

```markdown
<meta name="version" content="1.2.3">
```

```yaml
path: $.html.meta.version
```

Links and images expose `text` or `alt`, `url`, and optional `title` fields.
Fenced code blocks are ignored while collecting Markdown headings, links, and
images.

## Normalizers

Normalizers convert values before comparison.

### `identity`

Returns the value unchanged.

This is the default behavior when no normalizer is configured.

### `string`

Converts scalar values to strings:

| Input | Output |
| --- | --- |
| `8.1` | `"8.1"` |
| `true` | `"true"` |
| `false` | `"false"` |
| `null` | `""` |

### `trim`

Removes whitespace from the beginning and end of strings. Other scalar types
are unchanged.

### `trim-v-prefix`

Removes one lowercase `v` prefix:

```text
v1.2.3 → 1.2.3
```

An uppercase `V` is not removed.

### `version`

Parses and normalizes version strings using Composer's version parser.

This makes common equivalent representations comparable, for example:

```text
8.1
8.1.0
```

Invalid versions produce a configuration error.

### `php-version-id`

Converts PHP's integer version representation to a dotted version:

| Input | Result |
| --- | --- |
| `80100` | `8.1` |
| `80102` | `8.1.2` |

The input must be an integer or a string containing only decimal digits. This
is useful for PHPStan's `parameters.phpVersion` setting.

### `composer-minimum`

Extracts the inclusive numeric lower bound from a Composer constraint:

| Constraint | Result |
| --- | --- |
| `^8.1` | `8.1` |
| `>=8.1` | `8.1` |
| `8.1.*` | `8.1` |

It rejects:

- constraints with no numeric versions;
- constraints with an exclusive lower bound such as `>8.1`, because no single
  concrete minimum exists.

A common pattern is:

```yaml
checks:
  minimum-php:
    normalize: version
    expected:
      reader: composer
      file: composer.json
      path: $.require.php
      normalize: composer-minimum
```

The source-specific `composer-minimum` runs first. The check-level `version`
normalizer then normalizes both the expected and actual values.

## Complete WordPress example

This configuration checks the plugin release version and the minimum PHP
version:

```yaml
checks:
  release-version:
    normalize:
      - trim-v-prefix
      - version

    expected:
      reader: text
      file: VERSION

    values:
      composer:
        reader: composer
        file: composer.json
        path: $.version

      plugin-header:
        reader: wordpress-plugin
        file: plugin.php
        path: $.Version

      wordpress-readme:
        reader: wordpress-readme
        file: readme.txt
        path: $['Stable tag']

      php-constant:
        reader: php
        file: src/Plugin.php
        path: $.classes['Vendor\\Plugin'].constants.VERSION

  minimum-php:
    normalize: version

    expected:
      reader: composer
      file: composer.json
      path: $.require.php
      normalize: composer-minimum

    values:
      github-actions:
        reader: yaml
        file: .github/workflows/tests.yml
        path: $.jobs.tests.strategy.matrix.php[0]

      phpstan:
        reader: neon
        file: phpstan.neon
        path: $.parameters.phpVersion
        normalize: php-version-id

      plugin-header:
        reader: wordpress-plugin
        file: plugin.php
        path: $['Requires PHP']

      wordpress-readme:
        reader: wordpress-readme
        file: readme.txt
        path: $['Requires PHP']
```

The same example is available as
[`consistent-versions.example.yaml`](consistent-versions.example.yaml).

## GitHub Actions integration

Add a step after Composer dependencies are installed:

```yaml
name: Tests

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.1"
          coverage: none

      - run: composer install --no-interaction --prefer-dist

      - name: Check version consistency
        run: vendor/bin/consistent-versions check
```

An inconsistency exits with status `1`, so the workflow fails. Invalid
configuration, unreadable files, parse failures, and invalid selectors exit
with status `2`.

For a workflow triggered by a Git tag, the tag name is available in
`GITHUB_REF_NAME`. It can be compared directly without creating a temporary
file:

```yaml
checks:
  release-version:
    normalize:
      - trim-v-prefix
      - version
    expected:
      reader: env
      variable: GITHUB_REF_NAME
    values:
      version-file:
        reader: text
        file: VERSION
```

Run that check only for tag events:

```yaml
on:
  push:
    tags:
      - "v*"

jobs:
  release:
    if: github.ref_type == 'tag'
    # Checkout, install dependencies, then run the checker.
```

## Using the library API

The CLI is a thin wrapper around `ConfigurationLoader` and `Engine`.

```php
<?php

declare(strict_types=1);

use SzepeViktor\ConsistentVersions\ConfigurationLoader;
use SzepeViktor\ConsistentVersions\Engine;

require __DIR__ . '/vendor/autoload.php';

$configuration = (new ConfigurationLoader())->load(
    __DIR__ . '/consistent-versions.yaml'
);

$report = (new Engine())->run($configuration, __DIR__);

foreach ($report->results as $result) {
    if ($result->passed()) {
        continue;
    }

    echo $result->name, ': expected ', (string) $result->expected, PHP_EOL;

    foreach ($result->mismatches as $label => $actual) {
        echo '  ', $label, ': ', (string) $actual, PHP_EOL;
    }
}

exit($report->passed() ? 0 : 1);
```

Useful report methods:

```php
$report->passed();
$report->failureCount();
```

Each `CheckResult` exposes:

```php
$result->name;
$result->expected;
$result->mismatches;
$result->passed();
```

## Extending the package

Readers and normalizers are registries. Applications may register
domain-specific implementations without changing the JSONPath language.

### Custom reader

Implement `Reader`:

```php
<?php

declare(strict_types=1);

namespace Project;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Reader\Reader;

final class ManifestReader implements Reader
{
    public function read(string $path): Document
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read %s', $path));
        }

        return new Document(
            ['release' => trim($contents)],
            $path
        );
    }
}
```

Register it:

```php
use Project\ManifestReader;
use SzepeViktor\ConsistentVersions\Engine;
use SzepeViktor\ConsistentVersions\Normalizer\NormalizerRegistry;
use SzepeViktor\ConsistentVersions\Reader\ReaderRegistry;

$readers = ReaderRegistry::withDefaults();
$readers->register('manifest', new ManifestReader());

$engine = new Engine(
    $readers,
    NormalizerRegistry::withDefaults()
);
```

Configuration may then use:

```yaml
reader: manifest
file: MANIFEST
path: $.release
```

Readers that consume a named value rather than a file may additionally
implement `DirectInputReader`. Its `inputField()` method declares the
configuration field passed unchanged to `read()`. The built-in `env` reader,
for example, declares `variable`; file readers continue to receive an absolute
file path.

### Custom normalizer

Implement `Normalizer`:

```php
<?php

declare(strict_types=1);

namespace Project;

use SzepeViktor\ConsistentVersions\Normalizer\Normalizer;

final class LowercaseNormalizer implements Normalizer
{
    public function normalize(
        string|int|float|bool|null $value
    ): string|int|float|bool|null {
        return is_string($value) ? strtolower($value) : $value;
    }
}
```

Register it:

```php
use Project\LowercaseNormalizer;
use SzepeViktor\ConsistentVersions\Engine;
use SzepeViktor\ConsistentVersions\Normalizer\NormalizerRegistry;
use SzepeViktor\ConsistentVersions\Reader\ReaderRegistry;

$normalizers = NormalizerRegistry::withDefaults();
$normalizers->register('lowercase', new LowercaseNormalizer());

$engine = new Engine(
    ReaderRegistry::withDefaults(),
    $normalizers
);
```

## Errors and exit codes

| Exit code | Meaning |
| --- | --- |
| `0` | All configured values are consistent |
| `1` | One or more values differ from the expected value |
| `2` | Configuration, file reading, parsing, or selection error |

### Configuration and parsing errors

These are reported to standard error:

```text
ERROR: Configuration file is not readable: consistent-versions.yaml
```

Examples include:

- missing `checks`;
- missing `expected`;
- empty `values`;
- unknown reader;
- unknown normalizer;
- unreadable file;
- missing environment variable;
- invalid JSON, YAML, NEON, INI, or XML;
- invalid JSONPath;
- selector returning zero or several values;
- selector returning an array or object;
- invalid version or Composer constraint.

### Inconsistent values

Inconsistencies are normal check results and are printed to standard output:

```text
FAIL  release-version
      expected: 1.2.3
      plugin-header: 1.2.2
      wordpress-readme: 1.2.1

2 inconsistent value(s)
```

All checks are evaluated, so one run reports every discovered inconsistency.

## Security and limitations

### Read-only behavior

The package never updates source files. There is no search-and-replace or
automatic version bumping.

### Environment variables

The `env` reader only reads the variable explicitly named in the configuration.
Its value may be displayed in mismatch reports, so it should be used for
non-secret metadata such as CI tag names, not credentials or tokens.

### PHP files are not executed

The PHP reader uses its own limited lexer. It does not `include` or `require`
the inspected file.

Only literal scalar constant expressions are evaluated. Dynamic expressions
are skipped.

### XML entities

External XML entities are never loaded. Only predefined XML entities and
numeric character references are decoded.

### Markdown scope

The Markdown reader extracts front matter, ATX headings, inline links, images,
embedded HTML metadata, and the original text. It is not a complete CommonMark
renderer.

For a stable documented version, prefer explicit front matter:

```markdown
---
version: 1.2.3
---
```

or explicit HTML metadata:

```html
<meta name="version" content="1.2.3">
```

Do not depend on finding a version inside arbitrary prose.

### No automatic inference

The tool does not guess:

- which file is authoritative;
- whether two different constraints mean the same thing;
- which array element is the minimum version;
- which value inside prose represents the project version.

The configuration states these decisions explicitly.

## Development

Install dependencies:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Run PHP syntax checks:

```bash
composer lint
```

Run PHPStan:

```bash
composer phpstan
```

Run every quality check:

```bash
composer all
```

The repository checks its own minimum PHP version declarations with
[`tests/consistent-versions.yaml`](tests/consistent-versions.yaml). The
self-check compares Composer's package constraint and platform version,
PHPStan's target version, the oldest GitHub Actions matrix entry, and the
machine-readable metadata in this README:

```bash
composer versions
```

PHPStan analyses `src` and `tests` at level `max`. There is no baseline and no
ignored error category.

The test suite covers all built-in readers, JSONPath selection, normalizers,
successful checks, and mismatch reporting. CI runs the complete suite on PHP
8.1 and PHP 8.5.

## License

Consistent Versions is open-source software licensed under the
[MIT License](LICENSE).
