# PHPStan static analysis deliverable

## PHPStan configuration

Larastan is installed as a development dependency and PHPStan is configured in `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 8
    paths:
        - app
```

The analysis can be run locally with:

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
```

No baseline is used. The previous `phpstan-baseline.neon` contained no ignored errors, so it was removed rather than retaining an unused suppression mechanism.

## Initial error count

The required initial Level 5 analysis reported:

```text
PHPStan Level 5: 0 errors
```

The project was previously configured at Level 6, which also reported 0 errors. Testing the next levels produced the following results before fixes:

| Level | Initial result |
| --- | ---: |
| 5 | 0 errors |
| 6 | 0 errors |
| 7 | 8 errors |
| 8 | 22 errors |

The Level 8 errors included nullable model dereferences, nullable return values treated as guaranteed objects, mixed identifiers, inaccurate return assumptions, and Laravel dispatch return-type ambiguity.

After correcting the affected application paths, Level 8 reports:

```text
[OK] No errors
```

## Selected level and reason

**Selected PHPStan level:** 8

**Reason:** Level 8 is the highest level made clean in this change without suppressions or a baseline. It detects the nullable-object errors targeted by this task while remaining achievable with focused, behavior-preserving fixes. Levels 9 and 10 expose broader legacy `mixed`-type debt. That debt should be corrected in a separate change instead of being hidden in a baseline or mixed into this task.

## Separate CI job

Static analysis runs independently from tests and code style in `.github/workflows/ci.yml`:

```yaml
jobs:
  analyse:
    name: Static Analysis (PHPStan)
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"

      - name: Configure Composer authentication
        run: composer config --global github-oauth.github.com "${{ secrets.GITHUBTOKEN }}"

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Run PHPStan
        run: ./vendor/bin/phpstan analyse --memory-limit=1G
```

Because `analyse` is a separate job, tests can remain green while a type error makes the Static Analysis check fail.

For the check to block merging, GitHub Branch Protection for `main` must have **Static Analysis (PHPStan)** selected under **Require status checks to pass before merging**. This repository setting cannot be enabled by the workflow file itself.

## Type-error gate evidence

The gate was tested locally with this deliberate type lie:

```php
final class StaticAnalysisGateProbe
{
    public function getCount(): int
    {
        return 'five';
    }
}
```

PHPStan failed with exit code 1 and reported:

```text
Line 9  Method App\StaticAnalysisGateProbe::getCount() should return int but returns string.
        Identifier: return.type

[ERROR] Found 1 error
```

This demonstrates that the same command used by the independent CI job rejects the deliberate error. The temporary probe was removed after recording the failure.

After removal, the final local verification was:

```text
Tests                         PASS (85 tests, 248 assertions)
Static Analysis (PHPStan)     PASS (Level 8, 0 errors)
Code Style (Pint)             PASS
```

