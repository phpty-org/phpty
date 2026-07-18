<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\ScreenTest\PHPUnit\AssertScreen;
use PhPty\ScreenTest\Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tier-4 end-to-end rendering tests per ADR-0017: real Reline::readline on a Pty,
 * output driving a VTerm, assertions on the rendered Screen. These drive
 * completion and the autocomplete dropdown the way a user would — through the
 * keymap on a pty — the PHP equivalent of the completion slice of upstream's
 * yamatanooroti suite.
 *
 * Only Tab (^I, `complete`) is bound to completion in the emacs keymap, so Tab is
 * what cycles the dropdown here; the arrow keys stay history motions, exactly as
 * upstream's default emacs keymap leaves them (menu_complete / menu_complete_
 * backward / completion_journey_up have no default binding — see the completion
 * unit test, which drives them by symbol as upstream's own tests do).
 */
final class CompletionScreenTest extends TestCase
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

    /** @param list<string> $args extra argv passed to the subject */
    private function start(string $subject, int $rows, int $cols, string $prompt, array $args = []): Session
    {
        return $this->sessions[] = Session::start(
            $rows,
            $cols,
            \array_merge([\PHP_BINARY, __DIR__ . '/subjects/' . $subject], $args),
            0.1,
            $prompt,
        );
    }

    public function testTabCompletesACommonPrefix(): void
    {
        $session = $this->start('readline_complete_subject.php', 6, 30, 'p>');
        $session->write('fo');
        $this->assertSame('p> fo', $session->render()[0]);

        $session->write("\x09"); // Tab: all candidates share the prefix "foo_"
        $this->assertSame('p> foo_', $session->render()[0]);

        $session->write("\r");
        $rendered = \implode("\n", $session->render());
        $this->assertStringContainsString('GOT[foo_]', $rendered);
    }

    public function testAutocompleteDropdownAppearsBelowAndCyclesAndErasesOnAccept(): void
    {
        $session = $this->start('readline_autocomplete_subject.php', 8, 30, '>');
        $session->write('Re');

        // The input stays "Re" on row 0; the dropdown opens on the rows below it.
        $this->assertSame('> Re', $session->render()[0]);
        $below = \implode("\n", \array_slice($session->render(), 1));
        $this->assertStringContainsString('Readline', $below);
        $this->assertStringContainsString('Regexp', $below);
        $this->assertStringContainsString('RegexpError', $below);

        // Tab cycles the highlighted candidate into the buffer.
        $session->write("\x09");
        $this->assertSame('> Readline', $session->render()[0]);
        $session->write("\x09");
        $this->assertSame('> Regexp', $session->render()[0]);

        // Accepting erases the dropdown and returns the chosen candidate.
        $session->write("\r");
        $rendered = \implode("\n", $session->render());
        $this->assertStringContainsString('GOT[Regexp]', $rendered);
        $this->assertStringNotContainsString('RegexpError', $rendered);
    }

    public function testAutocompleteDropdownFlipsAboveNearBottom(): void
    {
        // A 5-row screen with four buffer lines drives the cursor to row 3, so
        // there is no room below and the two-candidate dropdown flips above it.
        $session = $this->start('readmultiline_autocomplete_subject.php', 5, 30, '>');
        $session->write("l1\rl2\rl3\rRe"); // \r splits the buffer; the confirm proc declines

        $rendered = $session->render();
        // The "Re" line is at row 3; the candidates render on the rows above it.
        $this->assertSame('> Re', $rendered[3]);
        $above = \implode("\n", \array_slice($rendered, 0, 3));
        $this->assertStringContainsString('Readline', $above);
        $this->assertStringContainsString('Regexp', $above);
    }
}
