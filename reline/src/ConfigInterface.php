<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\KeyActor\KeyActorInterface;

/**
 * The slice of Reline::Config that KeyStroke actually consults.
 *
 * Config itself (inputrc parsing, editing-mode state, ~380 lines) is a later
 * tier. KeyStroke only ever calls two things on it — the active keymap and a
 * vi-mode test — so tier 0 ports against this narrow contract and a test double
 * stands in for the real Config. When Config is ported it will implement this.
 *
 * editing_mode_is is upstream's `editing_mode_is?`; the trailing `?` cannot
 * survive in a PHP identifier and is dropped (the one naming rule that bends,
 * recorded in CONTEXT.md).
 */
interface ConfigInterface
{
    /** The composite keymap for the current editing mode (upstream key_bindings). */
    public function key_bindings(): KeyActorInterface;

    /** True if the current editing mode is any of the given labels. */
    public function editing_mode_is(string ...$labels): bool;
}
