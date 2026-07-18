<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The dialog colour seam — the minimal slice of lib/reline/face.rb tier 4 needs.
 *
 * Upstream Face is a full SGR-theme DSL (truecolor/256-colour builders, an
 * inputrc-driven config, `Face.config(:name) { |c| c.define ... }`). Per the tier
 * plan (architecture-map §8, tier 6) that DSL is deferred; tier 4 ships the two
 * built-in faces the dialog renderer actually reads, with their default SGR
 * sequences hardcoded to the exact bytes face.rb's `load_initial_configs`
 * produces:
 *
 *   :default            — all slots reset (`\e[0m`), used when a dialog names no face
 *   :completion_dialog  — the autocomplete dropdown's colours
 *
 * The `\e[0m<params>m` shape mirrors format_to_sgr (face.rb:126-151): every
 * sequence is prefixed with a reset. The three slot names (default / enhanced /
 * scrollbar) are ESSENTIAL_DEFINE_NAMES (face.rb:59); update_each_dialog reads all
 * three off the resolved face.
 *
 * TIER 6: replace this hardcoding with the ported Face DSL. Call sites take a face
 * name and read the three slots, so the DSL slots in behind `sgr` without touching
 * update_each_dialog — this class is the whole seam.
 */
final class Face
{
    private const RESET = "\e[0m";

    /** @var array<string, array{default: string, enhanced: string, scrollbar: string}> */
    private const FACES = [
        'default' => [
            'default' => self::RESET,
            'enhanced' => self::RESET,
            'scrollbar' => self::RESET,
        ],
        // face.rb:188-192 — bright_white(97)/gray-bg(100), black(30)/white-bg(47),
        // white(37)/gray-bg(100), each prefixed with a reset by format_to_sgr.
        'completion_dialog' => [
            'default' => self::RESET . "\e[97;100m",
            'enhanced' => self::RESET . "\e[30;47m",
            'scrollbar' => self::RESET . "\e[37;100m",
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Resolve a face name to its three SGR slots (default / enhanced / scrollbar),
     * mirroring `Reline::Face[name]` returning a config indexable by slot. An
     * unknown name falls back to :default, as an unset dialog face does upstream.
     *
     * @return array{default: string, enhanced: string, scrollbar: string}
     */
    public static function get(?string $name): array
    {
        return self::FACES[$name ?? 'default'] ?? self::FACES['default'];
    }
}
