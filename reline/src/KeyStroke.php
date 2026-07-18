<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\KeyActor\KeyActorInterface;

/**
 * Classifies a buffered byte sequence against the active keymap, ported from
 * lib/reline/key_stroke.rb.
 *
 * This is the stateless matcher that lets the read loop decide, after each byte,
 * whether it holds a complete key, a prefix of one, or garbage. It leans on the
 * keymap for known bindings and falls back to match_unknown_escape_sequence()
 * for the generic CSI (`ESC [ ... final`) and SS3 (`ESC O char`) shapes that no
 * table enumerates. expand() turns a matched sequence into Key structs, and
 * performs the single-byte inputrc macro expansion upstream still limits itself
 * to (multibyte and recursive macros are unsupported there too).
 *
 * Bytes are carried as list<int> (0..255), mirroring Ruby's `bytes` arrays. The
 * match statuses are Ruby symbols upstream; here they are string class
 * constants (ADR-0011: no native enums).
 */
final class KeyStroke
{
    private const ESC_BYTE = 27;
    private const CSI_PARAMETER_LOW = 0x30;
    private const CSI_PARAMETER_HIGH = 0x3f;
    private const CSI_INTERMEDIATE_LOW = 0x20;
    private const CSI_INTERMEDIATE_HIGH = 0x2f;

    /** Input exactly matches a key sequence. */
    public const MATCHING = 'matching';
    /** Input partially matches a key sequence. */
    public const MATCHED = 'matched';
    /** Input matches a key sequence and is also a prefix of a longer one. */
    public const MATCHING_MATCHED = 'matching_matched';
    /** Input matches no key sequence. */
    public const UNMATCHED = 'unmatched';

    public string $encoding;

    public function __construct(
        private readonly ConfigInterface $config,
        string $encoding,
    ) {
        $this->encoding = $encoding;
    }

    /**
     * @param list<int> $input
     */
    public function match_status(array $input): string
    {
        $keyMapping = $this->keyMapping();
        $matching = $keyMapping->matching($input);
        $matched = $keyMapping->get($input);
        if ($matching && $matched !== null) {
            return self::MATCHING_MATCHED;
        }
        if ($matching) {
            return self::MATCHING;
        }
        if ($matched !== null) {
            return self::MATCHED;
        }
        if (($input[0] ?? null) === self::ESC_BYTE) {
            return $this->match_unknown_escape_sequence(
                $input,
                $this->config->editing_mode_is('vi_insert', 'vi_command'),
            );
        }

        $s = self::bytesToString($input);
        if (self::validEncoding($s, $this->encoding)) {
            return self::stringSize($s, $this->encoding) === 1 ? self::MATCHED : self::UNMATCHED;
        }

        // Invalid string is MATCHING (part of a valid string) or MATCHED
        // (invalid bytes to be ignored).
        return self::MATCHING_MATCHED;
    }

    /**
     * @param list<int> $input
     * @return array{0: list<Key>, 1: list<int>}
     */
    public function expand(array $input): array
    {
        $matchedBytes = null;
        $size = count($input);
        for ($i = 1; $i <= $size; $i++) {
            $bytes = array_slice($input, 0, $i);
            $status = $this->match_status($bytes);
            if ($status === self::MATCHED || $status === self::MATCHING_MATCHED) {
                $matchedBytes = $bytes;
            }
            if ($status === self::MATCHED || $status === self::UNMATCHED) {
                break;
            }
        }
        if ($matchedBytes === null) {
            return [[], []];
        }

        $func = $this->keyMapping()->get($matchedBytes);
        $s = self::bytesToString($matchedBytes);
        if (is_array($func)) {
            // Simple macro expansion for single-byte key bindings. Multibyte key
            // bindings and recursive macro expansion are unsupported, as upstream.
            $macro = self::bytesToString($func);
            $keys = [];
            foreach (self::chars($macro) as $c) {
                $f = $this->keyMapping()->get(self::stringToBytes($c));
                $keys[] = new Key($c, is_string($f) ? $f : 'ed_insert', false);
            }
        } elseif ($func !== null) {
            $keys = [new Key($s, $func, false)];
        } elseif (self::validEncoding($s, $this->encoding) && self::stringSize($s, $this->encoding) === 1) {
            $keys = [new Key($s, 'ed_insert', false)];
        } else {
            $keys = [];
        }

        return [$keys, array_slice($input, count($matchedBytes))];
    }

    /**
     * Match status of a generic CSI/SS3 sequence the keymap does not enumerate.
     *
     * @param list<int> $input
     */
    private function match_unknown_escape_sequence(array $input, bool $viMode): string
    {
        $size = count($input);
        $idx = 0;
        if (($input[$idx] ?? null) !== self::ESC_BYTE) {
            return self::UNMATCHED;
        }
        $idx++;
        if (($input[$idx] ?? null) === self::ESC_BYTE) {
            $idx++;
        }

        $c = $input[$idx] ?? null;
        if ($c === null) {
            // `ESC` alone could still grow; `ESC ESC` is a partial match.
            return $idx === 1 ? self::MATCHING_MATCHED : self::MATCHING;
        }
        if ($c === 91) { // '['.ord — CSI sequence `ESC [ ... char`
            $idx++;
            while ($idx < $size && $input[$idx] >= self::CSI_PARAMETER_LOW && $input[$idx] <= self::CSI_PARAMETER_HIGH) {
                $idx++;
            }
            while ($idx < $size && $input[$idx] >= self::CSI_INTERMEDIATE_LOW && $input[$idx] <= self::CSI_INTERMEDIATE_HIGH) {
                $idx++;
            }
        } elseif ($c === 79) { // 'O'.ord — SS3 sequence `ESC O char`
            $idx++;
        } elseif ($viMode) {
            // `ESC char` or `ESC ESC char`: disallowed in vi mode.
            return self::UNMATCHED;
        }

        if ($size === $idx) {
            return self::MATCHING;
        }
        if ($size === $idx + 1) {
            return self::MATCHED;
        }

        return self::UNMATCHED;
    }

    private function keyMapping(): KeyActorInterface
    {
        return $this->config->key_bindings();
    }

    /**
     * @param list<int> $bytes
     */
    private static function bytesToString(array $bytes): string
    {
        return $bytes === [] ? '' : pack('C*', ...$bytes);
    }

    /**
     * @return list<int>
     */
    private static function stringToBytes(string $s): array
    {
        if ($s === '') {
            return [];
        }

        return array_values(unpack('C*', $s));
    }

    /**
     * @return list<string>
     */
    private static function chars(string $s): array
    {
        if ($s === '') {
            return [];
        }

        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function validEncoding(string $s, string $encoding): bool
    {
        return mb_check_encoding($s, $encoding);
    }

    private static function stringSize(string $s, string $encoding): int
    {
        return mb_strlen($s, $encoding);
    }
}
