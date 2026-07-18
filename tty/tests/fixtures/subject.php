<?php

declare(strict_types=1);

/*
 * A Subject run on a Pty by TtyOnPtyTest: its stdin/stdout are a real Tty, the
 * only place Raw mode and Winsize can actually be observed (ADR-0016 Testing).
 * It takes a Backend name and an action on argv, exercises Tty, and prints
 * markers the test asserts on from the Controller end.
 */

use PhPty\Tty\FfiBackend;
use PhPty\Tty\Libc;
use PhPty\Tty\RawOptions;
use PhPty\Tty\SttyBackend;
use PhPty\Tty\Tty;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

function out(string $text): void
{
    \fwrite(\STDOUT, $text);
    \fflush(\STDOUT);
}

function makeTty(string $backend): Tty
{
    switch ($backend) {
        case 'ffi':
            return new Tty(new FfiBackend(Libc::load()));
        case 'stty':
            return new Tty(new SttyBackend());
        default:
            return new Tty();
    }
}

$backend = $argv[1] ?? 'auto';
$action = $argv[2] ?? 'rawmode';
$tty = makeTty($backend);

switch ($action) {
    case 'istty':
        out('ISTTY:' . ($tty->isTty() ? 'yes' : 'no'));
        break;

    case 'winsize':
        $size = $tty->getWinsize();
        out('SIZE:' . $size->rows() . 'x' . $size->cols());
        break;

    case 'setwinsize':
        $tty->setWinsize(11, 47);
        $size = $tty->getWinsize();
        out('SIZE:' . $size->rows() . 'x' . $size->cols());
        break;

    case 'rawmode':
        // Prints RAW> once raw is engaged, reads one byte with echo off, then
        // exits. DONE only prints if that byte arrived without a newline (raw's
        // unbuffered VMIN=1 delivery); the byte is never echoed. The final
        // cooked fgets echoes its input only if the prior state was restored.
        $tty->withRawMode(static function (): void {
            out('RAW>');
            @\fread(\STDIN, 1);
        });
        out('DONE');
        \fgets(\STDIN);
        out('END');
        break;

    case 'exception':
        // withRawMode must restore even when the callback throws: the cooked
        // echoed read afterwards is the proof.
        try {
            $tty->withRawMode(static function (): void {
                out('RAW>');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            out('CAUGHT');
        }
        \fgets(\STDIN);
        out('END');
        break;

    case 'nested':
        // Re-entrant: an inner withRawMode (here with intr:false) runs and
        // restores to the outer raw state, and the whole nest restores to cooked
        // — proven by the echoed cooked read at the end.
        $tty->withRawMode(static function () use ($tty): void {
            $tty->withRawMode(static function (): void {
                out('INNER');
            }, new RawOptions(false, 1, 0));
            out('OUTER');
        });
        out('DONE');
        \fgets(\STDIN);
        out('END');
        break;
}
