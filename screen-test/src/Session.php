<?php

declare(strict_types=1);

namespace PhPty\ScreenTest;

use PhPty\Pty\Pty;
use PhPty\VTerm\VTerm;

/**
 * Drives a Subject on a Pty, feeds its output into a VTerm, and renders the
 * resulting Screen. Framework-agnostic: render() hands back the lines a user
 * would have seen, and the caller asserts on them however it likes. See
 * screen-test/CONTEXT.md and docs/adr/0010-testing-spans-74-to-85-via-polyfills.md.
 */
final class Session
{
    private Pty $pty;
    private VTerm $vterm;
    private int $rows;
    private int $cols;
    private int $waitMicroseconds;

    private function __construct(Pty $pty, VTerm $vterm, int $rows, int $cols, float $wait)
    {
        $this->pty = $pty;
        $this->vterm = $vterm;
        $this->rows = $rows;
        $this->cols = $cols;
        $this->waitMicroseconds = (int) ($wait * 1_000_000);
    }

    /** Give a slow Subject up to this long to print its startup message. */
    private const STARTUP_TIMEOUT_MICROSECONDS = 5_000_000;

    /**
     * Start a Subject on a Screen of the given size and settle its initial
     * output.
     *
     * @param list<string> $command        argv for the Subject; the first is the program
     * @param float        $wait           seconds to let output go quiet before a Sync stops
     * @param string|null  $startupMessage if set, block until the Subject's output
     *                                     begins with it — so a REPL's banner has
     *                                     appeared before any assertion runs
     */
    public static function start(
        int $rows,
        int $cols,
        array $command,
        float $wait = 0.1,
        ?string $startupMessage = null
    ): self {
        $session = new self(
            Pty::spawn($command, $rows, $cols),
            new VTerm($rows, $cols),
            $rows,
            $cols,
            $wait,
        );
        if ($startupMessage !== null && $startupMessage !== '') {
            $session->awaitStartup($startupMessage);
        }
        $session->sync();

        return $session;
    }

    /**
     * Poll until the Subject's output begins with $marker, feeding it to the
     * VTerm as it arrives. Bounded: a Subject that never prints the marker gives
     * up rather than hanging, and the assertion that follows reports the empty
     * Screen. This deliberately does not stop on a quiet gap the way sync() does
     * — a banner printed after a pause is exactly what it waits through.
     */
    private function awaitStartup(string $marker): void
    {
        $accumulated = '';
        $polls = \intdiv(self::STARTUP_TIMEOUT_MICROSECONDS, self::POLL_MICROSECONDS);

        for ($i = 0; $i < $polls; $i++) {
            $chunk = $this->pty->read();
            if ($chunk === '') {
                \usleep(self::POLL_MICROSECONDS);
                continue;
            }
            $accumulated .= $chunk;
            $this->vterm->write($chunk);
            $responses = $this->vterm->takeResponses();
            if ($responses !== '') {
                $this->pty->write($responses);
            }
            if (\strpos($accumulated, $marker) === 0) {
                return;
            }
        }
    }

    /** Send input to the Subject, then settle whatever it emits in response. */
    public function write(string $bytes): void
    {
        $this->pty->write($bytes);
        $this->sync();
    }

    private const POLL_MICROSECONDS = 5000;

    /**
     * Drain everything the Subject has emitted into the VTerm, so the Screen
     * reflects it, then stop once output has been quiet for `wait`. Bracket every
     * read and write with this.
     *
     * Polling for a quiet period, rather than sleeping `wait` and reading once,
     * catches output that arrives in bursts or a little late without waiting the
     * full `wait` between chunks — steadier under CI load.
     *
     * A Sync also writes back: the VTerm may have replies to send upstream (a
     * cursor-position report, for one), and a Subject waiting for the answer
     * would hang if it never arrived. So each drained chunk's replies go back to
     * the Subject — see screen-test/CONTEXT.md.
     */
    public function sync(): void
    {
        $quietPolls = 0;
        $maxQuietPolls = \max(1, \intdiv($this->waitMicroseconds, self::POLL_MICROSECONDS));

        while (true) {
            $chunk = $this->pty->read();
            if ($chunk !== '') {
                $this->vterm->write($chunk);
                $responses = $this->vterm->takeResponses();
                if ($responses !== '') {
                    $this->pty->write($responses);
                }
                $quietPolls = 0;
                continue;
            }

            if (++$quietPolls >= $maxQuietPolls) {
                return;
            }
            \usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The Screen as lines of text a human would read: each fullwidth character
     * as its single character (its spacer contributes nothing), unwritten cells
     * as spaces, and trailing spaces stripped from each line.
     *
     * @return list<string>
     */
    public function render(): array
    {
        $lines = [];
        for ($row = 0; $row < $this->rows; $row++) {
            $line = '';
            for ($col = 0; $col < $this->cols; $col++) {
                $cell = $this->vterm->cellAt($row, $col);
                if ($cell->isSpacerTail()) {
                    continue;
                }
                $text = $cell->text();
                $line .= $text === '' ? ' ' : $text;
            }
            $lines[] = \rtrim($line, ' ');
        }

        return $lines;
    }

    public function close(): void
    {
        $this->pty->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
