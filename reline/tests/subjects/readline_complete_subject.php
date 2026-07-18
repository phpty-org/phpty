<?php

declare(strict_types=1);

/*
 * ScreenTest subject: Tab (^I) completion with a fixed candidate set and
 * autocompletion off, so `complete` inserts the common prefix. Force a non-dumb
 * TERM so the ANSI gate renders (dialogs are only wired on a non-dumb gate).
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

Reline::set_completion_proc(static fn (string $word): array => ['foo_foo', 'foo_bar', 'foo_baz', 'qux']);

$line = Reline::readline('p> ');

fwrite(STDOUT, 'GOT[' . ($line ?? 'EOF') . "]\r\n");
