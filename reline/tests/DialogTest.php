<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\Core;
use PhPty\Reline\KeyStroke;
use PhPty\Reline\LineEditor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests for the dialog positioning math (dialog_range / update_each_dialog),
 * driven against the in-memory LoggingIO gate on a deliberately small screen. No
 * terminal, no render — the buffer is edited through update() so the autocomplete
 * journey builds, then update_dialogs runs the ported DEFAULT_DIALOG_PROC_
 * AUTOCOMPLETE and the resulting Dialog geometry (column, vertical_offset,
 * contents) is asserted. This exercises the below/above-cursor flip and the
 * dialog vanishing when there is no completion.
 */
final class DialogTest extends TestCase
{
    private Config $config;

    private LineEditor $editor;

    private KeyStroke $keyStroke;

    private function build(int $rows, int $cols): void
    {
        $this->config = new Config();
        $this->config->set_autocompletion(true);
        $this->editor = new LineEditor($this->config, new LoggingIO());
        $this->editor->reset('> ');
        $this->editor->set_screen_size_for_test($rows, $cols);
        $this->editor->add_dialog_proc('autocomplete', Core::default_dialog_proc_autocomplete(), []);
        $this->keyStroke = new KeyStroke($this->config, 'UTF-8');
    }

    private function type(string $input): void
    {
        $bytes = $input === '' ? [] : \array_values(\unpack('C*', $input));
        while ($bytes !== []) {
            [$expanded, $bytes] = $this->keyStroke->expand($bytes);
            foreach ($expanded as $key) {
                $this->editor->update($key);
            }
        }
    }

    public function testDropdownRendersBelowCursorNearTop(): void
    {
        $this->build(10, 30);
        $this->editor->set_completion_proc(static fn (string $word): array => ['Readline', 'Regexp', 'RegexpError']);
        $this->type('Re');

        $dialog = $this->editor->dialogs()[0];
        $this->assertNotNull($dialog->contents(), 'the dropdown is visible');
        $this->assertCount(3, $dialog->contents());
        // cursor at column 4 (prompt "> " + "Re"); the proc anchors at cursor - completed_width.
        $this->assertSame(2, $dialog->column);
        // Plenty of room below the cursor, so the dialog opens one row below it.
        $this->assertSame(1, $dialog->vertical_offset);
        $this->assertStringContainsString('Readline', $dialog->contents()[0]);
    }

    public function testDropdownFlipsAboveCursorNearBottom(): void
    {
        // A 4-row screen with the cursor driven to the last line: no room below,
        // so the dialog must open above the cursor (negative vertical offset).
        $this->build(4, 30);
        $this->editor->multiline_on();
        $this->editor->set_completion_proc(static fn (string $word): array => ['Readline', 'Regexp']);
        $this->type("l1\rl2\rl3\rRe"); // \r accepts-as-newline in multiline -> 4 buffer lines

        $this->assertSame(3, $this->editor->line_index(), 'cursor is on the 4th line');
        $dialog = $this->editor->dialogs()[0];
        $this->assertNotNull($dialog->contents(), 'the dropdown is visible');
        $this->assertCount(2, $dialog->contents());
        // height is 2 (two candidates); flipped above -> offset = pos.y - height.
        $this->assertSame(-2, $dialog->vertical_offset);
    }

    public function testScrollbarClipsAndDrawsWhenCandidatesExceedHeight(): void
    {
        // A short screen caps the dialog height; ten candidates overflow it, so the
        // contents are clipped and the scrollbar column is drawn.
        $this->build(5, 30);
        $candidates = [];
        for ($i = 0; $i < 10; $i++) {
            $candidates[] = 'x' . $i;
        }
        $this->editor->set_completion_proc(static fn (string $word): array => $candidates);
        $this->type('x');

        $dialog = $this->editor->dialogs()[0];
        $contents = $dialog->contents();
        $this->assertNotNull($contents);
        // preferred_dialog_height caps at rest_height (4) here, so 10 rows clip to 4.
        $this->assertCount(4, $contents);
        // The scrollbar's full-block glyph is drawn at the top of the bar.
        $this->assertStringContainsString('█', \implode('', $contents));
    }

    public function testDialogVanishesWithoutAMatch(): void
    {
        $this->build(10, 30);
        $this->editor->set_completion_proc(static fn (string $word): array => ['Readline', 'Regexp']);
        $this->type('Re');
        $this->assertNotNull($this->editor->dialogs()[0]->contents());

        // 'zz' has no candidates, so the journey collapses and the dialog clears.
        $this->type('zz');
        $this->assertNull($this->editor->dialogs()[0]->contents());
    }
}
