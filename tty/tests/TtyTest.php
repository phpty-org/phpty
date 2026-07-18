<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\FfiBackend;
use PhPty\Tty\Tty;
use PhPty\Tty\Winsize;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The facade's Backend selection and its non-Tty behaviour, both observable
 * without a real Tty. Raw mode over a real Tty — that it engages, restores, and
 * nests — is TtyOnPtyTest's job.
 */
final class TtyTest extends TestCase
{
    /** @var list<resource> */
    private array $streams = [];

    protected function tear_down(): void
    {
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        $this->streams = [];
    }

    public function testChoosesTheFfiBackendWhenFfiIsLoaded(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('The Ffi Backend is only chosen when the ffi extension is present.');
        }

        // No injected Backend: the choice is made on first use. getWinsize on a
        // non-Tty throws, but the Backend is resolved before it does, so the
        // resolved instance is what we assert on.
        $tty = new Tty(null, $this->nonTty());
        try {
            $tty->getWinsize();
        } catch (\RuntimeException $e) {
            // expected: not a Tty
        }

        $property = new \ReflectionProperty(Tty::class, 'backend');
        $property->setAccessible(true);
        $this->assertInstanceOf(FfiBackend::class, $property->getValue($tty));
    }

    public function testAnInjectedBackendIsUsed(): void
    {
        $backend = new FakeBackend(new Winsize(7, 30));
        $tty = new Tty($backend);

        $winsize = $tty->getWinsize();

        $this->assertSame(7, $winsize->rows());
        $this->assertSame(30, $winsize->cols());
        $this->assertSame(['getWinsize'], $backend->log);
    }

    public function testSetWinsizeDelegatesToTheBackend(): void
    {
        $backend = new FakeBackend();
        (new Tty($backend))->setWinsize(11, 47);

        $this->assertSame(['setWinsize:11:47'], $backend->log);
    }

    public function testIsTtyIsFalseForANonTtyStream(): void
    {
        $this->assertFalse((new Tty(new FakeBackend(), $this->nonTty()))->isTty());
    }

    public function testWithRawModeOnANonTtyIsAPassThrough(): void
    {
        // Not a Tty: the callback runs and its value is returned, and the Backend
        // is never touched — matching upstream's with_raw_input tty? guard.
        $backend = new FakeBackend();
        $tty = new Tty($backend, $this->nonTty());

        $result = $tty->withRawMode(static function (): string {
            return 'ran';
        });

        $this->assertSame('ran', $result);
        $this->assertSame([], $backend->log);
    }

    /**
     * @return resource a stream that is not a Tty
     */
    private function nonTty()
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));

        return $this->streams[] = $stream;
    }
}
