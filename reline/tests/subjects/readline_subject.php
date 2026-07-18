<?php

declare(strict_types=1);

/*
 * ScreenTest subject: drives Reline::readline on the pty slave it was spawned
 * onto, then prints the result so the harness can assert both what the user saw
 * (the rendered prompt/echo) and what readline returned. Force a non-dumb TERM so
 * the ANSI gate (real rendering + DSR probe) is exercised regardless of the CI
 * environment's TERM.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

$line = Reline::readline('prompt> ');

if ($line === null) {
    fwrite(STDOUT, "EOF\r\n");
} else {
    fwrite(STDOUT, "GOT[{$line}]\r\n");
}
