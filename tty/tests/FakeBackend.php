<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\Backend;
use PhPty\Tty\RawOptions;
use PhPty\Tty\Winsize;

/**
 * A Backend that records the calls the Tty facade makes to it, so TtyTest can
 * assert the facade's orchestration (that it delegates, and that on a non-Tty it
 * does not touch the Backend at all) without a real Tty.
 */
final class FakeBackend implements Backend
{
    /** @var list<string> */
    public array $log = [];

    private Winsize $winsize;

    public function __construct(?Winsize $winsize = null)
    {
        $this->winsize = $winsize ?? new Winsize(24, 80);
    }

    public function save(): mixed
    {
        $this->log[] = 'save';

        return 'token';
    }

    public function enterRaw(RawOptions $opts): void
    {
        $this->log[] = 'enterRaw';
    }

    public function restore(mixed $saved): void
    {
        $this->log[] = 'restore';
    }

    public function getWinsize(): Winsize
    {
        $this->log[] = 'getWinsize';

        return $this->winsize;
    }

    public function setWinsize(int $rows, int $cols): void
    {
        $this->log[] = "setWinsize:{$rows}:{$cols}";
    }
}
