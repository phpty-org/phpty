<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * A single resolved key press: the upstream Reline::Key struct.
 *
 * Upstream (lib/reline.rb) is `Struct.new(:char, :method_symbol, :unused_boolean)`.
 * The field names are kept verbatim, including `unused_boolean` — it is dead in
 * upstream too (a former with-meta flag), but IRB still constructs Key with a
 * third argument, so the slot must exist for compatibility.
 *
 * `char` is the byte string of the press (null only for the EOF key);
 * `method_symbol` is the editing-command name, a Ruby Symbol upstream and a PHP
 * string here (ADR-0005 keeps these snake_case), or null for EOF.
 *
 * PHPUnit's assertEquals compares two Keys field-by-field, which is how the
 * ported KeyStroke tests assert on expanded key lists — no custom equality is
 * needed for that. match() mirrors the struct's one behavioural method.
 */
final class Key
{
    /**
     * @param string|null      $char           the raw press, null for EOF
     * @param string|int|null  $method_symbol  editing-command name, null for EOF
     * @param bool             $unused_boolean retained for struct-shape parity
     */
    public function __construct(
        public readonly ?string $char = null,
        public readonly string|int|null $method_symbol = null,
        public readonly bool $unused_boolean = false,
    ) {
    }

    /** For dialog procs: `key.match?(name)` upstream. */
    public function match(?string $sym): bool
    {
        return $this->method_symbol !== null && $this->method_symbol === $sym;
    }
}
