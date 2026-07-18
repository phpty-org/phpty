# Testing spans PHP 7.4–8.5 with one test source, via PHPUnit-Polyfills

Every module is developed on modern PHP and shipped as 7.4
([ADR-0003](./0003-php-version-uniform-dev-modern-ship-74.md)), and the 7.4
artifact is tested on a real 7.4 ([ADR-0009](./0009-downgrade-on-release-with-rector.md)).
That forces one test suite to run under two PHPUnit worlds: PHPUnit 9 is the only
release that supports PHP 7.4, while modern PHP wants 11 or 12. `yoast/phpunit-polyfills`
bridges the assertion and lifecycle API differences so the same test code runs on
both. Tests extend `Yoast\PHPUnitPolyfills\TestCases\TestCase` rather than
PHPUnit's own.

## The version window is narrow, and measured

`yoast/phpunit-polyfills` 4.0 supports PHPUnit `^7.5 || ^8 || ^9 || ^11 || ^12` —
it **skips 10 and, so far, 13**. So the test PHPUnit is constrained to
`^9.6 || ^11.0 || ^12.0`, deliberately excluding 10 and 13 even though both
exist (13.2 needs PHP ≥ 8.4.1). Composer then picks per platform: 7.4 → 9 (the
release leg), 8.2 → 11, 8.3–8.5 → 12. Verified by running the suite in the dev
shell —
PHP 8.4 resolved PHPUnit 12.5.31 with polyfills 4.0.0 and the harness went green.

## PHPUnit lives in an isolated bin package, not in any `composer.json` require

PHPUnit and the polyfills are installed through `vendor-bin/phpunit`
(bamarni bin-plugin), and appear in **no** module's `composer.json` and not in the
root `require-dev`. The reason is `composer update --prefer-lowest`: that command
is how each library's real dependency floor is tested, and PHPUnit's own
dependency tree has no business skewing it. Isolating PHPUnit keeps every
`--prefer-lowest` resolution — per module, and at the root — a clean measurement
of the library, not the test tool.

The cost is autoloading. When PHPUnit runs from its bin package, its autoloader
(PHPUnit + polyfills) is active, but the monorepo's classes are not. A bootstrap
(`tests/bootstrap.php`) adds the root autoloader, so module and test classes
resolve while PHPUnit and the polyfill `TestCase` resolve from the bin package.
Two autoloaders, cooperating — verified green, not assumed.

## screen-test does not depend on PHPUnit at all

The same reasoning shapes the one module that is itself a test tool. ScreenTest's
core is framework-agnostic — it drives a Subject and returns a Screen, throwing
its own exception on mismatch — so it carries **no** PHPUnit dependency. A thin
`AssertScreen` trait is offered for PHPUnit users; it calls assertions it does not
own, so PHPUnit stays a `suggest`, never a `require`. A consumer on PHP 7.4 with
PHPUnit 9, or on 8.5 with PHPUnit 12, or on Pest, can all use ScreenTest, because
ScreenTest commits to none of them.

## Consequences

- The `phpunit.xml.dist` is kept minimal (bootstrap, testsuites, `colors`,
  `failOnWarning`) so one config validates across PHPUnit 9, 11, and 12. Coverage
  and logging config, whose schema moved between 9 and 10, is left out.
- When polyfills gains PHPUnit 13 support, the constraint can widen to include it;
  until then, 13 is deliberately excluded and the dev shell's newest PHP tests on
  12.
- A module could use another module's class without declaring the dependency,
  because the root autoloader maps all of them during development. That gap is a
  release-time check (a standalone install of each split package), not a
  development-time one.
