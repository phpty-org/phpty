<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * The Raw mode and Winsize operations shelling out to `stty`, the fallback for
 * FFI-less installs (ADR-0001). Equivalent in outcome to the Ffi Backend, dearer
 * in cost: every state change is a fork+exec, and a save/enter/restore cycle is
 * three of them. That is the price ADR-0016 accepts for keeping the fallback
 * honest.
 *
 * `stty -g` serialises the whole termios state into one opaque string; replaying
 * it restores exactly that state. Raw mode is entered with `raw -echo` plus the
 * min/time thresholds, and — because `raw` also clears ISIG — `isig` is added
 * back when signals are to be kept. State is never left mutated on failure: the
 * facade saves before entering and restores in a finally, and a failed enter
 * still has the saved string to replay.
 */
final class SttyBackend implements Backend
{
    private SttyRunner $runner;

    public function __construct(?SttyRunner $runner = null)
    {
        $this->runner = $runner ?? new SystemSttyRunner();
    }

    /**
     * @return string the opaque `stty -g` state
     */
    public function save(): mixed
    {
        return \trim($this->runner->run(['-g']));
    }

    public function enterRaw(RawOptions $opts): void
    {
        $args = ['raw', '-echo'];
        if ($opts->intr()) {
            // `raw` turned ISIG off; put it back so Ctrl-C/Ctrl-Z still signal.
            $args[] = 'isig';
        }
        // Override `raw`'s implied `min 1 time 0` with the requested thresholds.
        $args[] = 'min';
        $args[] = (string) $opts->vmin();
        $args[] = 'time';
        $args[] = (string) $opts->vtime();

        $this->runner->run($args);
    }

    /**
     * @param string $saved a token from save()
     */
    public function restore(mixed $saved): void
    {
        $this->runner->run([$saved]);
    }

    public function getWinsize(): Winsize
    {
        // `stty size` prints "rows cols".
        $output = \trim($this->runner->run(['size']));
        $parts = \preg_split('/\s+/', $output);
        if (!\is_array($parts) || \count($parts) !== 2 || !\ctype_digit($parts[0]) || !\ctype_digit($parts[1])) {
            throw new \RuntimeException("Could not parse 'stty size' output: {$output}");
        }

        return new Winsize((int) $parts[0], (int) $parts[1]);
    }

    public function setWinsize(int $rows, int $cols): void
    {
        $this->runner->run(['rows', (string) $rows, 'cols', (string) $cols]);
    }
}
