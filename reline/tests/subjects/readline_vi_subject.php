<?php

declare(strict_types=1);

/*
 * ScreenTest subject: switches Reline into vi mode programmatically (the inputrc
 * `set editing-mode vi` path is tier 7), then drives Reline::readline on the pty
 * slave. Prints the returned line so the harness can assert both the rendered
 * echo and the final buffer after a vi command sequence.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

Reline::core()->config()->set_editing_mode('vi_insert');

$line = Reline::readline('prompt> ');

if ($line === null) {
    fwrite(STDOUT, "EOF\r\n");
} else {
    fwrite(STDOUT, "GOT[{$line}]\r\n");
}
