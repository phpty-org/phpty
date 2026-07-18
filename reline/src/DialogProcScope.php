<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The execution scope handed to a dialog proc, ported from the DialogProcScope
 * class nested in line_editor.rb:575-652.
 *
 * Upstream runs the proc with `instance_exec(&@proc_to_exec)`, so the proc's
 * `self` is the scope and it calls `config`, `completion_journey_data`,
 * `cursor_pos`, `dialog`, `screen_width`, `preferred_dialog_height` etc. as bare
 * receivers. PHP has no clean `instance_exec` over an arbitrary closure, so this
 * port passes the scope in explicitly: a dialog proc here is
 * `function (DialogProcScope $scope): ?DialogRenderInfo` and reads the same
 * surface through `$scope`. That is the one idiom deviation in the dialog path;
 * every method below mirrors an upstream scope method one-to-one.
 */
final class DialogProcScope
{
    private LineEditor $line_editor;

    private Config $config;

    /** @var callable(DialogProcScope): ?DialogRenderInfo */
    private $proc_to_exec;

    /** @var list<mixed>|null the IRB-facing context array (unused in-port) */
    private ?array $context;

    private CursorPos $cursor_pos;

    private ?Dialog $dialog = null;

    private ?Key $key = null;

    /**
     * @param callable(DialogProcScope): ?DialogRenderInfo $proc_to_exec
     * @param list<mixed>|null                             $context
     */
    public function __construct(LineEditor $line_editor, Config $config, callable $proc_to_exec, ?array $context)
    {
        $this->line_editor = $line_editor;
        $this->config = $config;
        $this->proc_to_exec = $proc_to_exec;
        $this->context = $context;
        $this->cursor_pos = new CursorPos(0, 0);
    }

    /** @return list<mixed>|null */
    public function context(): ?array
    {
        return $this->context;
    }

    /** @return array{0: string, 1: string, 2: string} [pre, target, post] */
    public function retrieve_completion_block(): array
    {
        [$pre, $target, $post] = $this->line_editor->retrieve_completion_block();

        return [$pre, $target, $post];
    }

    /**
     * @return list<string>|null
     */
    public function call_completion_proc_with_checking_args(string $pre, string $target, string $post): ?array
    {
        return $this->line_editor->call_completion_proc_with_checking_args($pre, $target, $post);
    }

    public function set_dialog(Dialog $dialog): void
    {
        $this->dialog = $dialog;
    }

    public function dialog(): ?Dialog
    {
        return $this->dialog;
    }

    public function set_cursor_pos(int $col, int $row): void
    {
        // CursorPos is an immutable value object here, so replace it rather than
        // mutating .x/.y as upstream's mutable Struct does.
        $this->cursor_pos = new CursorPos($col, $row);
    }

    public function set_key(?Key $key): void
    {
        $this->key = $key;
    }

    public function key(): ?Key
    {
        return $this->key;
    }

    public function cursor_pos(): CursorPos
    {
        return $this->cursor_pos;
    }

    public function just_cursor_moving(): bool
    {
        return $this->line_editor->just_cursor_moving();
    }

    public function screen_width(): int
    {
        return $this->line_editor->screen_width();
    }

    public function screen_height(): int
    {
        return $this->line_editor->screen_height();
    }

    public function preferred_dialog_height(): int
    {
        [, $wrapped_cursor_y] = $this->line_editor->wrapped_cursor_position();

        return \max(
            $this->line_editor->upper_space_height($wrapped_cursor_y),
            $this->line_editor->rest_height($wrapped_cursor_y),
            \intdiv($this->screen_height() + 6, 5),
        );
    }

    public function completion_journey_data(): ?CompletionJourneyData
    {
        return $this->line_editor->dialog_proc_scope_completion_journey_data();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function call(): ?DialogRenderInfo
    {
        return ($this->proc_to_exec)($this);
    }
}
