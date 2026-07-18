<?php

declare(strict_types=1);

namespace PhPty\Reline\KeyActor;

/**
 * The keymap contract both Base and Composite answer to.
 *
 * Upstream has no such interface — Base and Composite are duck-typed on
 * `matching?` and `get`. PHP wants a nominal type so Composite can hold a list
 * of keymaps and KeyStroke can name what Config#key_bindings returns; that is
 * the whole reason it exists (noted in CONTEXT.md). A key is a byte sequence,
 * carried as a list<int> of 0..255 values, matching Ruby's `bytes` arrays.
 */
interface KeyActorInterface
{
    /**
     * Is this byte sequence a strict prefix of some bound key?
     *
     * @param list<int> $key
     */
    public function matching(array $key): bool;

    /**
     * The command bound to this exact byte sequence, or null.
     *
     * Returns a command name (string), a macro (list<int> of bytes) or null.
     *
     * @param list<int> $key
     * @return string|list<int>|null
     */
    public function get(array $key);
}
