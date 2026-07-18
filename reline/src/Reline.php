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

    public static function readline(string $prompt = ''): ?string
    {
        return self::core()->readline($prompt);
    }

    public static function get_screen_size(): array
    {
        return self::core()->get_screen_size();
    }
}
