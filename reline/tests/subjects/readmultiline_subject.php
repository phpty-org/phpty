<?php

declare(strict_types=1);

/*
 * ScreenTest subject for the multiline path: drives Reline::readmultiline with a
 * heredoc-style confirm proc that accepts once the final line is "EOF". The
 * rendered Screen shows every buffer row (each carries the "ml> " prompt); on
 * accept the returned buffer is echoed with newlines flattened to "|" so the
 * harness can assert a single GOT[...] marker.
 */

putenv('TERM=xterm-256color');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhPty\Reline\Reline;

$line = Reline::readmultiline('ml> ', static function (string $buffer): bool {
    $lines = explode("\n", rtrim($buffer, "\n"));

    return end($lines) === 'EOF';
});

if ($line === null) {
    fwrite(STDOUT, "EOF\r\n");
} else {
    fwrite(STDOUT, 'GOT[' . str_replace("\n", '|', $line) . "]\r\n");
}
