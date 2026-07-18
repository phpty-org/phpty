<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\LineEditor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from RenderLineDifferentialTest in test/reline/test_line_editor.rb.
 *
 * These drive the per-span row-diff renderer (line_editor.rb:406) directly with
 * cell-tuple rows and assert the exact sequence of cursor moves, writes, and
 * erases. Per ADR-0017 the renderer is ported in full shape, so these upstream
 * cases — including the dialog-overlay and multibyte rows tier 1's own editing
 * never produces — pass unchanged against it. The expected strings are upstream's
 * with `\e[0m` already absent (the Dumb-based LoggingIO emits no colour reset).
 */
final class RenderLineDifferentialTest extends TestCase
{
    private LineEditor $editor;

    private LoggingIO $io;

    protected function set_up(): void
    {
        $this->io = new LoggingIO();
        $this->editor = new LineEditor(null, $this->io);
        $this->editor->set_screen_size_for_test(24, 80);
    }

    /**
     * @param list<array{0: int, 1: int, 2: string}|null> $old
     * @param list<array{0: int, 1: int, 2: string}|null> $new
     */
    private function assertOutput(string $expected, array $old, array $new): void
    {
        $this->io->reset();
        $this->editor->render_line_differential($old, $new);
        // Strip the colour-reset sequence, exactly as upstream's assert_output does.
        $this->assertSame($expected, \str_replace("\e[0m", '', $this->io->log()));
    }

    public function testLineIncreaseDecrease(): void
    {
        $this->assertOutput('[COL_0]bb', [[0, 1, 'a']], [[0, 2, 'bb']]);
        $this->assertOutput('[COL_0]b[COL_1][ERASE]', [[0, 2, 'aa']], [[0, 1, 'b']]);
    }

    public function testDialogAppearDisappear(): void
    {
        $this->assertOutput('[COL_3]dialog', [[0, 1, 'a']], [[0, 1, 'a'], [3, 6, 'dialog']]);
        $this->assertOutput('[COL_3]dialog', [[0, 10, \str_repeat('a', 10)]], [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']]);
        $this->assertOutput('[COL_1][ERASE]', [[0, 1, 'a'], [3, 6, 'dialog']], [[0, 1, 'a']]);
        $this->assertOutput('[COL_3]aaaaaa', [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']], [[0, 10, \str_repeat('a', 10)]]);
    }

    public function testDialogChange(): void
    {
        $this->assertOutput('[COL_3]DIALOG', [[0, 2, 'a'], [3, 6, 'dialog']], [[0, 2, 'a'], [3, 6, 'DIALOG']]);
        $this->assertOutput('[COL_3]DIALOG', [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']], [[0, 10, \str_repeat('a', 10)], [3, 6, 'DIALOG']]);
    }

    public function testUpdateUnderDialog(): void
    {
        $this->assertOutput('[COL_0]b[COL_1] ', [[0, 2, 'aa'], [4, 6, 'dialog']], [[0, 1, 'b'], [4, 6, 'dialog']]);
        $this->assertOutput('[COL_0]bbb[COL_9]b', [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']], [[0, 10, \str_repeat('b', 10)], [3, 6, 'dialog']]);
        $this->assertOutput('[COL_0]b[COL_1]  [COL_9][ERASE]', [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']], [[0, 1, 'b'], [3, 6, 'dialog']]);
    }

    public function testDialogMove(): void
    {
        $this->assertOutput('[COL_3]dialog[COL_9][ERASE]', [[0, 1, 'a'], [4, 6, 'dialog']], [[0, 1, 'a'], [3, 6, 'dialog']]);
        $this->assertOutput('[COL_4] [COL_5]dialog', [[0, 1, 'a'], [4, 6, 'dialog']], [[0, 1, 'a'], [5, 6, 'dialog']]);
        $this->assertOutput('[COL_2]dialog[COL_8]a', [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']], [[0, 10, \str_repeat('a', 10)], [2, 6, 'dialog']]);
        $this->assertOutput('[COL_2]a[COL_3]dialog', [[0, 10, \str_repeat('a', 10)], [2, 6, 'dialog']], [[0, 10, \str_repeat('a', 10)], [3, 6, 'dialog']]);
    }

    public function testMultibyte(): void
    {
        $base = [0, 12, '一二三一二三'];
        $left = [0, 3, 'LLL'];
        $right = [9, 3, 'RRR'];
        $front = [3, 6, 'FFFFFF'];

        $this->assertOutput('[COL_2]二三一二', [$base, $front], [$base, null]);
        $this->assertOutput('[COL_3] 三一二', [$base, $left, $front], [$base, $left, null]);
        $this->assertOutput('[COL_2]二三一 ', [$base, $right, $front], [$base, $right, null]);
        $this->assertOutput('[COL_3] 三一 ', [$base, $left, $right, $front], [$base, $left, $right, null]);
    }

    public function testComplicated(): void
    {
        $stateA = [null, [19, 7, 'bbbbbbb'], [15, 8, 'cccccccc'], [10, 5, 'ddddd'], [18, 4, 'eeee'], [1, 3, 'fff'], [17, 2, 'gg'], [7, 1, 'h']];
        $stateB = [[5, 9, 'aaaaaaaaa'], null, [15, 8, 'cccccccc'], null, [18, 4, 'EEEE'], [25, 4, 'ffff'], [17, 2, 'gg'], [2, 2, 'hh']];

        $this->assertOutput('[COL_1] [COL_2]hh[COL_5]aaaaaaaaa[COL_14] [COL_19]EEE[COL_23]  [COL_25]ffff', $stateA, $stateB);
        $this->assertOutput('[COL_1]fff[COL_5]  [COL_7]h[COL_8]  [COL_10]ddddd[COL_19]eee[COL_23]bbb[COL_26][ERASE]', $stateB, $stateA);
    }
}
