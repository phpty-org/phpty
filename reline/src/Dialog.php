<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * One registered dialog and its per-frame render state, ported from the Dialog
 * class nested in line_editor.rb:654-697. It owns the scroll position, the
 * highlighted pointer, and the computed column / vertical offset that
 * update_each_dialog fills in, plus the coloured `contents` rows render() paints.
 *
 * `contents=` and `width=` (Ruby writers) become setContents / setWidth here; the
 * width auto-derivation on assigning contents is kept. A `trap_key` binds extra
 * one-shot keys while the dialog is open (line_editor.rb:686-694); the default
 * autocomplete proc sets none, so it stays null unless a caller installs one.
 */
final class Dialog
{
    public int $scroll_top = 0;

    public ?int $pointer = null;

    public int $column = 0;

    public int $vertical_offset = 0;

    /** @var list<int>|list<list<int>>|null */
    public $trap_key = null;

    private string $name;

    private Config $config;

    private DialogProcScope $proc_scope;

    /** @var list<string>|null */
    private ?array $contents = null;

    private ?int $width = null;

    public function __construct(string $name, Config $config, DialogProcScope $proc_scope)
    {
        $this->name = $name;
        $this->config = $config;
        $this->proc_scope = $proc_scope;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string>|null */
    public function contents(): ?array
    {
        return $this->contents;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function set_cursor_pos(int $col, int $row): void
    {
        $this->proc_scope->set_cursor_pos($col, $row);
    }

    public function setWidth(int $width): void
    {
        $this->width = $width;
    }

    /** @param list<string>|null $contents */
    public function setContents(?array $contents): void
    {
        $this->contents = $contents;
        if ($contents !== null && $this->width === null) {
            $widths = \array_map(static fn (string $line): int => Unicode::calculate_width($line, true), $contents);
            $this->width = $widths === [] ? 0 : \max($widths);
        }
    }

    public function call(?Key $key): ?DialogRenderInfo
    {
        $this->proc_scope->set_dialog($this);
        $this->proc_scope->set_key($key);
        $dialog_render_info = $this->proc_scope->call();
        if ($this->trap_key !== null) {
            $isNested = false;
            foreach ($this->trap_key as $t) {
                if (\is_array($t)) {
                    $isNested = true;
                    break;
                }
            }
            if ($isNested) {
                /** @var list<list<int>> $trap */
                $trap = $this->trap_key;
                foreach ($trap as $t) {
                    $this->config->add_oneshot_key_binding($t, $this->name);
                }
            } else {
                /** @var list<int> $trap */
                $trap = $this->trap_key;
                $this->config->add_oneshot_key_binding($trap, $this->name);
            }
        }

        return $dialog_render_info;
    }
}
