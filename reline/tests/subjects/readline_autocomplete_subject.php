<?php

declare(strict_types=1);

/*
 * ScreenTest subject: the autocomplete dropdown. autocompletion is on, so typing
 * builds a completion journey and the DEFAULT_DIALOG_PROC_AUTOCOMPLETE dropdown
 * follows the cursor; Tab (^I) cycles the highlighted candidate. An optional argv
 * count prints N blank lines first, driving the cursor toward the bottom of a
 * short screen so the dropdown must flip above it.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

Reline::set_completion_proc(static fn (string $word): array => \array_values(\array_filter(
    ['Readline', 'Regexp', 'RegexpError'],
    static fn (string $s): bool => \strncmp($s, $word, \strlen($word)) === 0,
)));
Reline::set_autocompletion(true);

$prefill = (int) ($argv[1] ?? 0);
if ($prefill > 0) {
    fwrite(STDOUT, str_repeat("\r\n", $prefill));
}

$line = Reline::readline('> ');

fwrite(STDOUT, 'GOT[' . ($line ?? 'EOF') . "]\r\n");
