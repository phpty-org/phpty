<?php

declare(strict_types=1);

namespace PhPty\Reline\KeyActor;

/**
 * A generic keymap: a byte-sequence -> command trie, ported from
 * lib/reline/key_actor/base.rb.
 *
 * Two tables back it. @keyBindings maps a whole byte sequence to its command;
 * @matchingBytes records every strict prefix of every bound sequence, so a
 * partial read can be told "keep reading" apart from "no such key". Ruby keys
 * these hashes on Array-of-bytes directly; PHP arrays cannot be hash keys, so a
 * byte list is joined into a comma-separated string (bytes are 0..255, so this
 * is unambiguous). That encoding is the one deviation from upstream in this file.
 *
 * add_mappings() consumes the flat 256-slot Emacs/Vi tables: slot k is the plain
 * key k, slot k|0x80 the Meta row emitted as the ESC-prefixed sequence [27, k].
 */
final class Base implements KeyActorInterface
{
    /** @var array<string, bool> */
    private array $matchingBytes = [];

    /** @var array<string, string|list<int>> */
    private array $keyBindings = [];

    /**
     * @param array<int, string|null>|null $mappings a flat 256-slot command table
     */
    public function __construct(?array $mappings = null)
    {
        if ($mappings !== null) {
            $this->add_mappings($mappings);
        }
    }

    /**
     * @param array<int, string|null> $mappings
     */
    public function add_mappings(array $mappings): void
    {
        $this->add([27], 'ed_ignore');
        for ($key = 0; $key < 128; $key++) {
            $func = $mappings[$key] ?? null;
            $metaFunc = $mappings[$key | 0b10000000] ?? null;
            if ($func !== null) {
                $this->add([$key], $func);
            }
            if ($metaFunc !== null) {
                $this->add([27, $key], $metaFunc);
            }
        }
    }

    /**
     * @param list<int>        $key
     * @param string|list<int> $func
     */
    public function add(array $key, $func): void
    {
        $size = count($key);
        for ($take = 1; $take < $size; $take++) {
            $this->matchingBytes[self::keyOf(array_slice($key, 0, $take))] = true;
        }
        $this->keyBindings[self::keyOf($key)] = $func;
    }

    public function matching(array $key): bool
    {
        return $this->matchingBytes[self::keyOf($key)] ?? false;
    }

    public function get(array $key)
    {
        return $this->keyBindings[self::keyOf($key)] ?? null;
    }

    public function clear(): void
    {
        $this->matchingBytes = [];
        $this->keyBindings = [];
    }

    /**
     * @param list<int> $key
     */
    private static function keyOf(array $key): string
    {
        return implode(',', $key);
    }
}
