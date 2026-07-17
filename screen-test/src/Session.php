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

    /**
     * Start a Subject on a Screen of the given size and settle its initial
     * output.
     *
     * @param list<string> $command argv for the Subject; the first is the program
     * @param float        $wait    seconds to let output arrive between reads
     */
    public static function start(int $rows, int $cols, array $command, float $wait = 0.1): self
    {
        $session = new self(
            Pty::spawn($command, $rows, $cols),
            new VTerm($rows, $cols),
            $rows,
            $cols,
            $wait,
        );
        $session->sync();

        return $session;
    }

    /** Send input to the Subject, then settle whatever it emits in response. */
    public function write(string $bytes): void
    {
        $this->pty->write($bytes);
        $this->sync();
    }

    /**
     * Drain everything the Subject has emitted into the VTerm, so the Screen
     * reflects it, then stop once a read finds nothing. Bracket every read and
     * write with this.
     *
     * Not yet wired: writing the VTerm's replies back to the Subject. A terminal
     * answers some queries (a cursor-position report, for one), and a Subject
     * that waits for the answer would hang. libghostty-vt surfaces those replies
     * through a write-pty callback; until that is bound, Sync only reads, which
     * suffices for Subjects that do not query — see screen-test/CONTEXT.md.
     */
    public function sync(): void
    {
        while (true) {
            \usleep($this->waitMicroseconds);
            $chunk = $this->pty->read();
            if ($chunk === '') {
                return;
            }
            $this->vterm->write($chunk);
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
