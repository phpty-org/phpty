<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\Face\Config as FaceConfig;

/**
 * The SGR-theme DSL, ported from lib/reline/face.rb.
 *
 * Upstream `Reline::Face` is a class of class-methods over a `@configs` hash of
 * named `Reline::Face::Config` objects: `Face.config(:name) { |c| c.define ... }`
 * registers a face, `Face[:name][:slot]` resolves a slot to its SGR escape
 * sequence, and `load_initial_configs` seeds the two built-ins (`:default`,
 * `:completion_dialog`) at module load (reline.rb:526). The port keeps that shape
 * with static state; there is no module-load hook, so the initial configs are
 * seeded lazily on first access (which preserves the `configs` key order the tests
 * assert: the two built-ins before any user faces).
 *
 * TIER 4 CONTINUITY: tier 4 shipped this class as a seam holding the two built-in
 * faces with their bytes hardcoded, resolved through `Face::get`. Tier 6 replaces
 * the internals with the real DSL and `load_initial_configs` — `get` is unchanged,
 * and because `:completion_dialog` uses named colours (truecolor-independent) it
 * still resolves to exactly the tier-4 bytes (`\e[0m\e[97;100m` etc.), proven by
 * FaceContinuityTest.
 */
final class Face
{
    /** @var array<string, FaceConfig>|null lazily seeded with the built-ins */
    private static ?array $configs = null;

    private static bool $forceTruecolor = false;

    private function __construct()
    {
    }

    // --- The tier-4 seam: update_each_dialog reads a face's three slots --------

    /**
     * Resolve a face name to its three SGR slots, the shape update_each_dialog
     * consumes (upstream `Face[name || :default]` then indexing each slot). An
     * unknown / null name falls back to :default, as an unset dialog face does.
     *
     * @return array{default: string, enhanced: string, scrollbar: string}
     */
    public static function get(?string $name): array
    {
        self::ensureInitialised();
        $config = self::$configs[$name ?? 'default'] ?? self::$configs['default'];

        return [
            'default' => $config->get('default'),
            'enhanced' => $config->get('enhanced'),
            'scrollbar' => $config->get('scrollbar'),
        ];
    }

    // --- The DSL (face.rb class-methods) --------------------------------------

    /**
     * Register a face, mirroring face.rb:173-176. The block receives the Config
     * and calls `define` per slot (the explicit-argument idiom, see Face\Config).
     *
     * @param callable(FaceConfig): void $block
     */
    public static function config(string $name, callable $block): void
    {
        self::ensureInitialised();
        self::$configs[$name] = new FaceConfig($block);
    }

    /** The Config for a name (upstream `Face[name]`); null if unregistered. */
    public static function getConfig(string $name): ?FaceConfig
    {
        self::ensureInitialised();

        return self::$configs[$name] ?? null;
    }

    /**
     * All faces' definitions (upstream `Face.configs`, face.rb:178-180).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function configs(): array
    {
        self::ensureInitialised();

        return \array_map(static fn (FaceConfig $c): array => $c->definition(), self::$configs);
    }

    /** face.rb:160-162 — truecolor is forced, or COLORTERM says so. */
    public static function truecolor(): bool
    {
        return self::$forceTruecolor || \in_array(\getenv('COLORTERM'), ['truecolor', '24bit'], true);
    }

    /** face.rb:164-167 — force truecolor and recompute every registered face. */
    public static function force_truecolor(): void
    {
        self::$forceTruecolor = true;
        if (self::$configs !== null) {
            foreach (self::$configs as $config) {
                $config->reconfigure();
            }
        }
    }

    /** Test seam: clear the force-truecolor flag (upstream sets @force_truecolor nil). */
    public static function unset_force_truecolor(): void
    {
        self::$forceTruecolor = false;
    }

    /** face.rb:182-193 — seed the two built-in faces. */
    public static function load_initial_configs(): void
    {
        self::$configs ??= [];
        self::config('default', static function (FaceConfig $conf): void {
            $conf->define('default', ['style' => 'reset']);
            $conf->define('enhanced', ['style' => 'reset']);
            $conf->define('scrollbar', ['style' => 'reset']);
        });
        self::config('completion_dialog', static function (FaceConfig $conf): void {
            $conf->define('default', ['foreground' => 'bright_white', 'background' => 'gray']);
            $conf->define('enhanced', ['foreground' => 'black', 'background' => 'white']);
            $conf->define('scrollbar', ['foreground' => 'white', 'background' => 'gray']);
        });
    }

    /** face.rb:195-198 — discard user faces and reseed the built-ins. */
    public static function reset_to_initial_configs(): void
    {
        self::$configs = [];
        self::load_initial_configs();
    }

    private static function ensureInitialised(): void
    {
        if (self::$configs === null) {
            self::load_initial_configs();
        }
    }
}
