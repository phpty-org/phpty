<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\ScreenTest\PHPUnit\AssertScreen;
use PhPty\ScreenTest\Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * End-to-end rendering tests per ADR-0017: a real Reline::readline runs on a Pty,
 * its output drives a VTerm, and the assertions are on the rendered Screen — what
 * a user's terminal would show. This is the PHP equivalent of upstream's
 * yamatanooroti pty rendering suite, routed through ScreenTest.
 *
 * DSR-under-VTerm: the harness's VTerm answers `\e[6n` (proven by
 * screen-test SessionTest::testWritesQueryRepliesBackToTheSubject), so the ANSI
 * gate's cursor-position probes — the ambiguous-width probe and reset()'s base_y
 * query — complete against it, and the ANSI path renders here rather than falling
 * back to Dumb.
 */
final class ReadlineScreenTest extends TestCase
{
    use AssertScreen;

    /** @var list<Session> */
    private array $sessions = [];

    protected function set_up(): void
    {
        foreach (['FFI', 'pcntl', 'posix', 'mbstring'] as $extension) {
            if (!\extension_loaded($extension)) {
                $this->markTestSkipped("The {$extension} extension is required to exercise Reline on a Pty.");
            }
        }
        if (\PHP_OS_FAMILY === 'Windows' || (\getenv('PHPTY_LIBGHOSTTY_VT') ?: '') === '') {
            $this->markTestSkipped('Needs a Unix host and libghostty-vt (the Nix dev shell provides both).');
        }
    }

    protected function tear_down(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];
    }

    private function startReadline(int $rows = 4, int $cols = 30): Session
    {
        $subject = __DIR__ . '/subjects/readline_subject.php';

        return $this->sessions[] = Session::start(
            $rows,
            $cols,
            [\PHP_BINARY, $subject],
            0.1,
            'prompt>',
        );
    }

    public function testPromptRendersAtStartup(): void
    {
        $session = $this->startReadline();

        $this->assertSame('prompt>', $session->render()[0]);
    }

    public function testEchoesTypedInput(): void
    {
        $session = $this->startReadline();
        $session->write('abc');

        $this->assertSame('prompt> abc', $session->render()[0]);
    }

    public function testCursorMotionAndReinsertion(): void
    {
        // Type "ac", move left one, insert "b" -> "abc".
        $session = $this->startReadline();
        $session->write('ac');
        $session->write("\x02"); // C-b
        $session->write('b');

        $this->assertSame('prompt> abc', $session->render()[0]);
    }

    public function testBackspaceAcrossWideChar(): void
    {
        $session = $this->startReadline();
        $session->write('日本');
        $this->assertSame('prompt> 日本', $session->render()[0]);
        $session->write("\x7f"); // Backspace removes 本 (a wide char) cleanly

        $this->assertSame('prompt> 日', $session->render()[0]);
    }

    public function testKillLineClearsToEnd(): void
    {
        $session = $this->startReadline();
        $session->write('abcdef');
        $session->write("\x01"); // C-a to beginning
        $session->write("\x0b"); // C-k kills to end of line

        $this->assertSame('prompt>', $session->render()[0]);
    }

    public function testAcceptLineReturnsBuffer(): void
    {
        $session = $this->startReadline();
        $session->write('hello');
        $session->write("\r"); // Enter accepts the line

        // render_finished prints the accepted line; the subject then echoes the
        // value readline returned.
        $rendered = \implode("\n", $session->render());
        $this->assertStringContainsString('GOT[hello]', $rendered);
    }
}
