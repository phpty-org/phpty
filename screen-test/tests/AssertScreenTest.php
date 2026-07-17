<?php

declare(strict_types=1);

namespace PhPty\ScreenTest\Tests;

use PhPty\ScreenTest\PHPUnit\AssertScreen;
use PhPty\ScreenTest\Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AssertScreenTest extends TestCase
{
    use AssertScreen;

    /** @var list<Session> */
    private array $sessions = [];

    protected function set_up(): void
    {
        foreach (['FFI', 'pcntl', 'posix', 'mbstring'] as $extension) {
            if (!\extension_loaded($extension)) {
                $this->markTestSkipped("The {$extension} extension is required to exercise Session.");
            }
        }
        if (PHP_OS_FAMILY === 'Windows' || (getenv('PHPTY_LIBGHOSTTY_VT') ?: '') === '') {
            $this->markTestSkipped('Session needs a Unix host and libghostty-vt (the Nix dev shell provides both).');
        }
    }

    protected function tear_down(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];
    }

    public function testAssertScreenComparesTheRendering(): void
    {
        $session = $this->sessions[] = Session::start(3, 20, ['/bin/sh', '-c', 'printf Hello']);

        $this->assertScreen(['Hello', '', ''], $session);
    }

    public function testAssertScreenDrivesAndAssertsAcrossWrites(): void
    {
        $session = $this->sessions[] = Session::start(2, 20, ['/bin/cat']);
        $session->write("hi\n");

        // cat and the Tty echo each show the line; both land on row 0 then wrap.
        $this->assertStringContainsString('hi', $this->render($session)[0]);
    }

    /** @return list<string> */
    private function render(Session $session): array
    {
        $session->sync();

        return $session->render();
    }
}
