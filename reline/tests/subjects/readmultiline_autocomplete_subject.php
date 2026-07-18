<?php

declare(strict_types=1);

/*
 * ScreenTest subject: the autocomplete dropdown in a multiline buffer. Several
 * lines drive the cursor down the screen so, near the bottom, the dropdown has to
 * flip above the cursor. The confirm proc accepts only when the buffer ends with
 * a semicolon, so the test can type freely without accepting.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

Reline::set_completion_proc(static fn (string $word): array => \array_values(\array_filter(
    ['Readline', 'Regexp', 'RegexpError'],
    static fn (string $s): bool => \strncmp($s, $word, \strlen($word)) === 0,
)));
Reline::set_autocompletion(true);

$line = Reline::readmultiline('> ', static fn (string $buffer): bool => \substr(\rtrim($buffer, "\n"), -1) === ';');

fwrite(STDOUT, 'GOT[' . ($line === null ? 'EOF' : str_replace("\n", '|', $line)) . "]\r\n");
