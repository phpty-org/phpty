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
}
