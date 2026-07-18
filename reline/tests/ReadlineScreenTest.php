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

    private function startReadmultiline(int $rows = 6, int $cols = 30): Session
    {
        $subject = __DIR__ . '/subjects/readmultiline_subject.php';

        return $this->sessions[] = Session::start(
            $rows,
            $cols,
            [\PHP_BINARY, $subject],
            0.1,
            'ml>',
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

    // --- Tier 2: wrapping, multiline, scrolling ----------------------------

    public function testLongInputWrapsAndBackspaceCrossesTheBoundary(): void
    {
        // cols=20, prompt "prompt> " is 8 wide, so 12 input columns fit on row 0.
        $session = $this->startReadline(4, 20);
        $session->write('abcdefghijklmno'); // 15 chars: 12 on row 0, 3 wrap to row 1

        $this->assertSame('prompt> abcdefghijkl', $session->render()[0]);
        $this->assertSame('mno', $session->render()[1]);

        // Backspace deletes the first wrapped char; the wrap boundary re-renders.
        $session->write("\x7f");
        $this->assertSame('prompt> abcdefghijkl', $session->render()[0]);
        $this->assertSame('mn', $session->render()[1]);
    }

    public function testWideCharWrapMovesWholeCharToNextRow(): void
    {
        // cols=12, prompt 8 wide -> 4 input columns on row 0. 日本 fills them (2+2);
        // 語 would straddle the right edge, so it moves whole to the next row.
        $session = $this->startReadline(4, 12);
        $session->write('日本語');

        $this->assertSame('prompt> 日本', $session->render()[0]);
        $this->assertSame('語', $session->render()[1]);
    }

    public function testReadmultilineShowsBothRowsAndAcceptsOnTerminator(): void
    {
        $session = $this->startReadmultiline(6, 30);
        $session->write("hello\n"); // first line; confirm proc declines, so it splits

        $this->assertSame('ml> hello', $session->render()[0]);
        $this->assertSame('ml>', $session->render()[1]);

        $session->write("EOF\n"); // terminator line: confirm proc accepts

        $rendered = \implode("\n", $session->render());
        $this->assertStringContainsString('GOT[hello|EOF]', $rendered);
    }

    public function testTallBufferScrollsAShortScreen(): void
    {
        // rows=6: an 8-line buffer is taller than the screen, so the top scrolls
        // off and the window follows the cursor on the last line.
        $session = $this->startReadmultiline(6, 30);
        $session->write("l1\nl2\nl3\nl4\nl5\nl6\nl7\nl8");

        $rendered = $session->render();
        // The cursor sits on l8, so the visible window ends there and l1/l2 are gone.
        $this->assertSame('ml> l8', $rendered[5]);
        $this->assertStringContainsString('ml> l3', \implode("\n", $rendered));
        $this->assertStringNotContainsString('l1', \implode("\n", $rendered));
        $this->assertStringNotContainsString('l2', \implode("\n", $rendered));
    }
}
