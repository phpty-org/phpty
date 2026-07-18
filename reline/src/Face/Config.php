<?php

declare(strict_types=1);

namespace PhPty\Reline\Face;

/**
 * One named face's slot definitions, ported from the nested `Reline::Face::Config`
 * in lib/reline/face.rb.
 *
 * Upstream builds a face with `Reline::Face.config(:name) { |conf| conf.define ... }`
 * — the block receives the Config and calls `define` per slot. Ruby's block/self
 * idiom has no clean PHP equivalent over an arbitrary closure (the same problem the
 * dialog procs have, CONTEXT.md), so the port passes the Config explicitly: the
 * builder is `function (Config $conf): void { $conf->define(...); }`. This is the
 * one idiom deviation in the Face path, mirroring DialogProcScope.
 *
 * `define(name, **values)` becomes `define(string $name, array $values)` where
 * $values is an ordered map of the SGR keys (`foreground` / `background` / `style`)
 * — insertion order is preserved by PHP arrays exactly as Ruby's `values.to_a`
 * preserves keyword order, so format_to_sgr emits the parameters in the given order.
 */
final class Config
{
    /** face.rb:59 — the three slots every face must resolve. */
    public const ESSENTIAL_DEFINE_NAMES = ['default', 'enhanced', 'scrollbar'];

    public const RESET_SGR = "\e[0m";

    /** face.rb:4-56 — the named-colour / style parameter tables. */
    private const SGR_PARAMETERS = [
        'foreground' => [
            'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,
            'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
            'bright_black' => 90, 'gray' => 90, 'bright_red' => 91,
            'bright_green' => 92, 'bright_yellow' => 93, 'bright_blue' => 94,
            'bright_magenta' => 95, 'bright_cyan' => 96, 'bright_white' => 97,
        ],
        'background' => [
            'black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43,
            'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47,
            'bright_black' => 100, 'gray' => 100, 'bright_red' => 101,
            'bright_green' => 102, 'bright_yellow' => 103, 'bright_blue' => 104,
            'bright_magenta' => 105, 'bright_cyan' => 106, 'bright_white' => 107,
        ],
        'style' => [
            'reset' => 0, 'bold' => 1, 'faint' => 2, 'italicized' => 3,
            'underlined' => 4, 'slowly_blinking' => 5, 'blinking' => 5,
            'rapidly_blinking' => 6, 'negative' => 7, 'concealed' => 8,
            'crossed_out' => 9,
        ],
    ];

    /** @var array<string, array<string, mixed>> slot name -> values (incl. escape_sequence) */
    private array $definition = [];

    /**
     * @param callable(Config): void $block
     */
    public function __construct(callable $block)
    {
        $block($this);
        foreach (self::ESSENTIAL_DEFINE_NAMES as $name) {
            if (!isset($this->definition[$name])) {
                $this->definition[$name] = ['style' => 'reset', 'escape_sequence' => self::RESET_SGR];
            }
        }
    }

    /**
     * Define one slot's SGR, mirroring face.rb:72-75. The escape sequence is
     * computed from the ordered $values and appended; the original keys are kept
     * so `configs` can report them, as upstream's values hash does.
     *
     * @param array<string, mixed> $values ordered map of foreground/background/style
     */
    public function define(string $name, array $values): void
    {
        $values['escape_sequence'] = $this->format_to_sgr($values);
        $this->definition[$name] = $values;
    }

    /** face.rb:77-82 — recompute every slot's escape sequence (after force_truecolor). */
    public function reconfigure(): void
    {
        foreach ($this->definition as $name => $values) {
            unset($values['escape_sequence']);
            $values['escape_sequence'] = $this->format_to_sgr($values);
            $this->definition[$name] = $values;
        }
    }

    /**
     * The escape sequence for a slot (upstream `face[name]`). face.rb:84-86.
     */
    public function get(string $name): string
    {
        if (!isset($this->definition[$name]['escape_sequence'])) {
            throw new \InvalidArgumentException("unknown face: {$name}");
        }

        return $this->definition[$name]['escape_sequence'];
    }

    /** @return array<string, array<string, mixed>> upstream `config.definition` */
    public function definition(): array
    {
        return $this->definition;
    }

    /**
     * face.rb:126-151 — build the SGR escape sequence from the ordered values.
     * Every non-reset sequence is prefixed with a reset, so the emitted bytes
     * match `RESET_SGR + "\e[...m"`.
     *
     * @param array<string, mixed> $orderedValues
     */
    private function format_to_sgr(array $orderedValues): string
    {
        $parts = [];
        foreach ($orderedValues as $key => $value) {
            if ($key === 'escape_sequence') {
                continue;
            }
            $rendition = null;
            if ($key === 'foreground' || $key === 'background') {
                if (\is_string($value) && $this->rgb_expression($value)) {
                    $rendition = $this->sgr_rgb($key, $value);
                } elseif (\is_string($value) && isset(self::SGR_PARAMETERS[$key][$value])) {
                    $rendition = (string) self::SGR_PARAMETERS[$key][$value];
                }
            } elseif ($key === 'style') {
                $names = \is_array($value) ? $value : [$value];
                $codes = [];
                $valid = true;
                foreach ($names as $styleName) {
                    if (!\is_string($styleName) || !isset(self::SGR_PARAMETERS['style'][$styleName])) {
                        $valid = false;
                        break;
                    }
                    $codes[] = (string) self::SGR_PARAMETERS['style'][$styleName];
                }
                $rendition = $valid ? \implode(';', $codes) : null;
            }
            if ($rendition === null) {
                throw new \InvalidArgumentException('invalid SGR parameter: ' . self::inspect($value));
            }
            $parts[] = $rendition;
        }
        $sgr = "\e[" . \implode(';', $parts) . 'm';

        return $sgr === self::RESET_SGR ? self::RESET_SGR : self::RESET_SGR . $sgr;
    }

    /** face.rb:90-97 — dispatch a `#rrggbb` value to truecolor or 256-colour. */
    private function sgr_rgb(string $key, string $value): ?string
    {
        if (!$this->rgb_expression($value)) {
            return null;
        }

        return \PhPty\Reline\Face::truecolor()
            ? $this->sgr_rgb_truecolor($key, $value)
            : $this->sgr_rgb_256color($key, $value);
    }

    /** face.rb:99-106. */
    private function sgr_rgb_truecolor(string $key, string $value): string
    {
        $prefix = $key === 'foreground' ? '38;2;' : '48;2;';
        $pairs = \str_split(\substr($value, 1, 6), 2);

        return $prefix . \implode(';', \array_map(static fn (string $h): string => (string) (int) \hexdec($h), $pairs));
    }

    /**
     * face.rb:108-124 — convert `#rrggbb` to the 216-colour cube index.
     * Color steps are [0, 95, 135, 175, 215, 255].
     */
    private function sgr_rgb_256color(string $key, string $value): string
    {
        $rgb = \array_map(static fn (string $h): int => (int) \hexdec($h), \str_split(\substr($value, 1, 6), 2));
        $scaled = \array_map(
            static fn (int $v): int => $v <= 95 ? \intdiv($v, 48) : \intdiv($v - 35, 40),
            $rgb,
        );
        [$r, $g, $b] = $scaled;
        $color = 16 + 36 * $r + 6 * $g + $b;

        return ($key === 'foreground' ? '38;5;' : '48;5;') . $color;
    }

    /** face.rb:153-155. */
    private function rgb_expression(string $color): bool
    {
        return \preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1;
    }

    /** Approximate Ruby's `value.inspect` for the error message (Symbol -> :name). */
    private static function inspect($value): string
    {
        if (\is_string($value)) {
            return ':' . $value;
        }
        if (\is_array($value)) {
            return '[' . \implode(', ', \array_map([self::class, 'inspect'], $value)) . ']';
        }

        return \var_export($value, true);
    }
}
