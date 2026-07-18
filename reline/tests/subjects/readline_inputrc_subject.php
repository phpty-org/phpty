<?php

declare(strict_types=1);

/*
 * ScreenTest subject: everything comes from the inputrc pointed at by $INPUTRC —
 * `set editing-mode vi` (so readline starts in vi-insert), `set show-mode-in-prompt
 * on`, and the custom vi-ins / vi-cmd mode strings ([I] / [C]). Nothing is set
 * programmatically: this exercises the tier-7 file resolution + parser + the
 * show-mode-in-prompt wiring into the prompt pipeline end-to-end. ESC switches
 * vi-insert -> vi-command, and the rendered mode indicator flips [I] -> [C].
 */

putenv('TERM=xterm-256color');
putenv('INPUTRC=' . __DIR__ . '/../fixtures/inputrc_vi_mode');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

$line = Reline::readline('prompt> ');

if ($line === null) {
    fwrite(STDOUT, "EOF\r\n");
} else {
    fwrite(STDOUT, "GOT[{$line}]\r\n");
}
