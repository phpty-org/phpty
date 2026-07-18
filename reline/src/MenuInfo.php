<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The completion menu (the flat candidate list shown under the input on an
 * ambiguous Tab), ported from the MenuInfo class nested in line_editor.rb:48-69.
 * `lines` lays the sorted candidates out into as many equal-width columns as the
 * screen holds, column-major, right-stripping each rendered row — the render()
 * menu branch turns each returned row into an overlay line.
 */
final class MenuInfo
{
    /** @var list<string> */
    private array $list;

    /** @param list<string> $list */
    public function __construct(array $list)
    {
        $this->list = $list;
    }

    /** @return list<string> */
    public function list(): array
    {
        return $this->list;
    }

    /** @return list<string> */
    public function lines(int $screen_width): array
    {
        if ($this->list === []) {
            return [];
        }

        $list = $this->list;
        \sort($list);
        $sizes = \array_map(static fn (string $item): int => Unicode::calculate_width($item), $list);
        $item_width = \max($sizes) + 2;
        $num_cols = \max(\intdiv($screen_width, $item_width), 1);
        $num_rows = (int) \ceil(\count($list) / $num_cols);
        $list_with_padding = [];
        foreach ($list as $i => $item) {
            $list_with_padding[] = $item . \str_repeat(' ', $item_width - $sizes[$i]);
        }
        // Pad to a full num_rows x num_cols grid with nulls, slice column-major
        // (each_slice(num_rows) then transpose), join and rstrip each row.
        $padded = \array_pad($list_with_padding, $num_rows * $num_cols, null);
        $rows = [];
        for ($r = 0; $r < $num_rows; $r++) {
            $row = '';
            for ($c = 0; $c < $num_cols; $c++) {
                $row .= $padded[$c * $num_rows + $r] ?? '';
            }
            $rows[] = \rtrim($row);
        }

        return $rows;
    }
}
