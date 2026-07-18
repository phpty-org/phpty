<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The public facade, ported from the `Reline` module in lib/reline.rb.
 *
 * Upstream `Reline` is a module of singleton-delegating methods over one lazily
 * built `Reline::Core`. This port keeps that shape: a process-wide Core built on
 * first use, with `readline` delegating to it. The tier-1 surface is deliberately
 * small — `readline(prompt)` — matching what Core implements.
 */
final class Reline
{
    private static ?Core $core = null;

    public static function core(): Core
    {
        return self::$core ??= new Core();
    }

    /** Replace the singleton Core (tests inject a Core over a scripted gate). */
    public static function setCore(?Core $core): void
    {
        self::$core = $core;
    }

    /**
     * The shared history store — upstream's `Reline::HISTORY` module constant,
     * reached here through the singleton Core (the injected-not-global deviation
     * noted in CONTEXT.md).
     */
    public static function HISTORY(): History
    {
        return self::core()->history();
    }

    public static function readline(string $prompt = '', bool $add_history = false): ?string
    {
        return self::core()->readline($prompt, $add_history);
    }

    /**
     * Read a multiline buffer, delegating the completion decision to $confirm.
     * Mirrors Reline.readmultiline: the block is required and receives the whole
     * buffer with a trailing newline.
     *
     * @param callable(string): bool $confirm
     */
    public static function readmultiline(string $prompt, callable $confirm, bool $add_history = false): ?string
    {
        return self::core()->readmultiline($prompt, $confirm, $add_history);
    }

    public static function get_screen_size(): array
    {
        return self::core()->get_screen_size();
    }

    // --- Completion / dialog surface (delegates to Core) -------------------

    /** @param (callable): mixed|null $proc */
    public static function set_completion_proc(?callable $proc): void
    {
        self::core()->set_completion_proc($proc);
    }

    public static function set_completion_append_character(?string $val): void
    {
        self::core()->set_completion_append_character($val);
    }

    /** @param (callable(string): void)|null $proc */
    public static function set_dig_perfect_match_proc(?callable $proc): void
    {
        self::core()->set_dig_perfect_match_proc($proc);
    }

    public static function set_basic_word_break_characters(string $v): void
    {
        self::core()->set_basic_word_break_characters($v);
    }

    public static function set_completer_word_break_characters(string $v): void
    {
        self::core()->set_completer_word_break_characters($v);
    }

    public static function set_basic_quote_characters(string $v): void
    {
        self::core()->set_basic_quote_characters($v);
    }

    public static function set_completer_quote_characters(string $v): void
    {
        self::core()->set_completer_quote_characters($v);
    }

    public static function set_filename_quote_characters(string $v): void
    {
        self::core()->set_filename_quote_characters($v);
    }

    public static function set_special_prefixes(string $v): void
    {
        self::core()->set_special_prefixes($v);
    }

    public static function set_completion_case_fold(bool $v): void
    {
        self::core()->set_completion_case_fold($v);
    }

    public static function completion_case_fold(): bool
    {
        return self::core()->completion_case_fold();
    }

    public static function completion_quote_character(): ?string
    {
        return self::core()->completion_quote_character();
    }

    public static function autocompletion(): bool
    {
        return self::core()->autocompletion();
    }

    public static function set_autocompletion(bool $v): void
    {
        self::core()->set_autocompletion($v);
    }

    /**
     * @param callable(DialogProcScope): ?DialogRenderInfo|null $p
     * @param list<mixed>|null                                  $context
     */
    public static function add_dialog_proc(string $name, ?callable $p, ?array $context = null): void
    {
        self::core()->add_dialog_proc($name, $p, $context);
    }

    /** @return array{proc: callable, context: list<mixed>|null}|null */
    public static function dialog_proc(string $name): ?array
    {
        return self::core()->dialog_proc($name);
    }
}
