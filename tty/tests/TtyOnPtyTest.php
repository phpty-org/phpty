<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\ScreenTest\Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Raw mode and Winsize observed the only way they can be: from a process whose
 * stdin is a real Tty. A small PHP Subject (tests/fixtures/subject.php) runs on a
 * Pty via ScreenTest; this test drives it from the Controller end and asserts on
 * the rendered Screen — that raw mode engages (a byte arrives unbuffered and
 * unechoed) and is restored (cooked echo returns), that restoration survives an
 * exception and nesting, and that Winsize round-trips. Both Backends are forced
 * explicitly through the Subject's argv (ADR-0016 Testing).
 *
 * The Backends are covered by paired methods rather than a data provider: the
 * suite spans PHPUnit 9.6 to 12 (ADR-0010), whose provider mechanisms differ,
 * and the other modules keep to plain methods for the same reason.
 */
final class TtyOnPtyTest extends TestCase
{
    /** @var list<Session> */
    private array $sessions = [];

    protected function set_up(): void
    {
        foreach (['FFI', 'pcntl', 'posix', 'mbstring'] as $extension) {
            if (!\extension_loaded($extension)) {
                $this->markTestSkipped("The {$extension} extension is required to drive a Subject on a Pty.");
            }
        }
        if (PHP_OS_FAMILY === 'Windows' || (\getenv('PHPTY_LIBGHOSTTY_VT') ?: '') === '') {
            $this->markTestSkipped('This test needs a Unix host and libghostty-vt (the Nix dev shell provides both).');
        }
    }

    protected function tear_down(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];
    }

    public function testRawModeEngagesAndRestoresOverFfi(): void
    {
        $this->assertRawModeEngagesAndRestores('ffi');
    }

    public function testRawModeEngagesAndRestoresOverStty(): void
    {
        $this->assertRawModeEngagesAndRestores('stty');
    }

    public function testRawModeIsRestoredAfterAnExceptionOverFfi(): void
    {
        $this->assertRawModeIsRestoredAfterAnException('ffi');
    }

    public function testRawModeIsRestoredAfterAnExceptionOverStty(): void
    {
        $this->assertRawModeIsRestoredAfterAnException('stty');
    }

    public function testNestedRawModeRestoresOverFfi(): void
    {
        $this->assertNestedRawModeRestores('ffi');
    }

    public function testNestedRawModeRestoresOverStty(): void
    {
        $this->assertNestedRawModeRestores('stty');
    }

    public function testGetWinsizeReflectsTheControllersSizeOverFfi(): void
    {
        $this->assertGetWinsizeReflectsTheControllersSize('ffi');
    }

    public function testGetWinsizeReflectsTheControllersSizeOverStty(): void
    {
        $this->assertGetWinsizeReflectsTheControllersSize('stty');
    }

    public function testSetWinsizeRoundTripsOverFfi(): void
    {
        $this->assertSetWinsizeRoundTrips('ffi');
    }

    public function testSetWinsizeRoundTripsOverStty(): void
    {
        $this->assertSetWinsizeRoundTrips('stty');
    }

    public function testIsTtyIsTrueOnAPty(): void
    {
        $session = $this->start(6, 40, 'auto', 'istty', 'ISTTY:');

        $this->assertStringContainsString('ISTTY:yes', $this->screen($session));
    }

    private function assertRawModeEngagesAndRestores(string $backend): void
    {
        $session = $this->start(6, 40, $backend, 'rawmode', 'RAW>');

        // One byte, no newline. In raw mode its VMIN=1 read returns at once
        // (DONE prints) and echo is off (the byte never shows on the Screen).
        $session->write('Z');
        $screen = $this->screen($session);
        $this->assertStringContainsString('DONE', $screen, 'the byte should arrive unbuffered');
        $this->assertStringNotContainsString('Z', $screen, 'the byte should not be echoed in raw mode');

        // After withRawMode the prior cooked state is back, so this line echoes.
        $session->write("hello\n");
        $screen = $this->screen($session);
        $this->assertStringContainsString('hello', $screen, 'echo should be restored after raw mode');
        $this->assertStringContainsString('END', $screen);
    }

    private function assertRawModeIsRestoredAfterAnException(string $backend): void
    {
        $session = $this->start(6, 40, $backend, 'exception', 'RAW>');

        $this->assertStringContainsString('CAUGHT', $this->screen($session));

        // Echo returning proves the finally restored the state despite the throw.
        $session->write("hi\n");
        $screen = $this->screen($session);
        $this->assertStringContainsString('hi', $screen, 'echo should be restored even when the callback threw');
        $this->assertStringContainsString('END', $screen);
    }

    private function assertNestedRawModeRestores(string $backend): void
    {
        $session = $this->start(6, 40, $backend, 'nested', 'INNER');

        $screen = $this->screen($session);
        $this->assertStringContainsString('INNER', $screen);
        $this->assertStringContainsString('OUTER', $screen);
        $this->assertStringContainsString('DONE', $screen);

        $session->write("hi\n");
        $screen = $this->screen($session);
        $this->assertStringContainsString('hi', $screen, 'the whole nest should restore to cooked');
        $this->assertStringContainsString('END', $screen);
    }

    private function assertGetWinsizeReflectsTheControllersSize(string $backend): void
    {
        // The Controller (Pty::spawn) sizes the Tty; the Subject reads it back.
        $session = $this->start(17, 83, $backend, 'winsize', 'SIZE:');

        $this->assertStringContainsString('SIZE:17x83', $this->screen($session));
    }

    private function assertSetWinsizeRoundTrips(string $backend): void
    {
        // The Subject sets 11x47 then reads it straight back.
        $session = $this->start(17, 83, $backend, 'setwinsize', 'SIZE:');

        $this->assertStringContainsString('SIZE:11x47', $this->screen($session));
    }

    private function start(int $rows, int $cols, string $backend, string $action, string $marker): Session
    {
        $command = [PHP_BINARY, __DIR__ . '/fixtures/subject.php', $backend, $action];

        return $this->sessions[] = Session::start($rows, $cols, $command, 0.1, $marker);
    }

    private function screen(Session $session): string
    {
        return \implode("\n", $session->render());
    }
}
