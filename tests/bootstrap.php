<?php

declare(strict_types=1);

/*
 * PHPUnit is installed as an isolated bin package (vendor-bin/phpunit) so that it
 * never enters the monorepo's or any module's dependency resolution — keeping
 * `composer update --prefer-lowest` a clean test of each library's real floor.
 * See docs/adr/0010-testing-spans-74-to-85-via-polyfills.md.
 *
 * When PHPUnit runs from that bin package, its own autoloader (PHPUnit and the
 * Yoast polyfills) is already active. This bootstrap adds the monorepo
 * autoloader, which maps every module's PhPty\* namespace and test namespace.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
