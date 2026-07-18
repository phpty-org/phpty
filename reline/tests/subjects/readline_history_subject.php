<?php

declare(strict_types=1);

/*
 * ScreenTest subject for the history recall path: two Reline::readline calls in
 * one process (one persistent Core, so its HISTORY survives between them), both
 * with add_history=true. The first accepted line is appended to history; on the
 * second call arrow-up (C-p) recalls it, so the Screen shows the recalled text.
 * Both results are echoed as GOT[a|b] with newlines flattened.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

$first = Reline::readline('h> ', true);
$second = Reline::readline('h> ', true);

$flatten = static fn (?string $s): string => $s === null ? 'EOF' : str_replace("\n", '|', $s);

fwrite(STDOUT, 'GOT[' . $flatten($first) . '|' . $flatten($second) . "]\r\n");
