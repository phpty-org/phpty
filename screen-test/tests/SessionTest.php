<?php

declare(strict_types=1);

namespace PhPty\ScreenTest\Tests;

use PhPty\ScreenTest\Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SessionTest extends TestCase
{
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

    public function testRendersTheSubjectsOutput(): void
    {
        $session = $this->start(3, 20, ['/bin/sh', '-c', 'printf Hello']);

        $this->assertSame(['Hello', '', ''], $session->render());
    }

    public function testRendersOneLinePerNewline(): void
    {
        $session = $this->start(3, 10, ['/bin/sh', '-c', "printf 'a\nb\nc'"]);

        $this->assertSame(['a', 'b', 'c'], $session->render());
    }

    public function testTrailingSpacesAreStripped(): void
    {
        $session = $this->start(1, 20, ['/bin/sh', '-c', "printf 'hi     '"]);

        $this->assertSame(['hi'], $session->render());
    }

    public function testFullwidthCharactersRenderOnePerCharacter(): void
    {
        // Each fullwidth character occupies two Cells; its spacer contributes
        // nothing, so the line reads as the three characters, not six.
        $session = $this->start(1, 10, ['/bin/sh', '-c', "printf '%s' '日本語'"]);

        $this->assertSame(['日本語'], $session->render());
    }

    public function testWriteDrivesTheSubject(): void
    {
        // cat echoes stdin back; with the Tty's own echo, "xy" appears twice.
        $session = $this->start(2, 20, ['/bin/cat']);
        $session->write("xy\n");

        $this->assertStringContainsString('xy', $session->render()[0]);
    }

    public function testOutputBeyondTheBottomScrolls(): void
    {
        // Four lines into a two-row Screen: the first two scroll off the top.
        $session = $this->start(2, 10, ['/bin/sh', '-c', "printf 'a\nb\nc\nd'"]);

        $this->assertSame(['c', 'd'], $session->render());
    }

    public function testWaitsForTheStartupMessage(): void
    {
        // The Subject pauses, prints a banner, then idles. Without a startup
        // message, start()'s Sync would return during the pause and miss it.
        $session = $this->sessions[] = Session::start(
            3,
            20,
            ['/bin/sh', '-c', 'sleep 0.3; printf "READY> "; cat'],
            0.1,
            'READY>',
        );

        $this->assertStringContainsString('READY>', $session->render()[0]);
    }

    public function testWritesQueryRepliesBackToTheSubject(): void
    {
        $bash = $this->findBash();
        if ($bash === null) {
            $this->markTestSkipped('This test needs bash for a Subject that queries the terminal.');
        }

        // The Subject asks for the cursor position and blocks until it receives
        // the reply, only then printing. If the reply were not written back, it
        // would hang and print nothing.
        $session = $this->start(3, 30, [$bash, '-c', 'printf "\033[6n"; IFS= read -r -d R _; printf DONE']);
        $session->sync();

        $this->assertStringContainsString('DONE', \implode('', $session->render()));
    }

    private function findBash(): ?string
    {
        foreach (['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash'] as $path) {
            if (\is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     */
    private function start(int $rows, int $cols, array $command): Session
    {
        return $this->sessions[] = Session::start($rows, $cols, $command);
    }
}
