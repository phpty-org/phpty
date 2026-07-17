<?php

declare(strict_types=1);

namespace PhPty\VTerm;

/**
 * A Cell's width behaviour, mirroring libghostty's GhosttyCellWide.
 *
 * This is a hand-rolled enum, not a native one: native enums used as a type —
 * stored, compared, constructed from a value — do not survive Rector's downgrade
 * to PHP 7.4 (the constant-list class it produces has no instances and no
 * from()). A final class with singleton instances works identically on 7.4 and
 * modern PHP, so identity comparison (===) holds. See
 * docs/adr/0011-no-native-enums-hand-rolled-instead.md.
 *
 * The distinction that matters most is spacerTail(): the second Cell of a
 * fullwidth character, which renders nothing — its content belongs to the Cell
 * before it. Conflating it with an unwritten narrow() Cell corrupts the
 * rendering of any line containing fullwidth text.
 */
final class Wide
{
    /** @var array<int, self> */
    private static array $cache = [];

    private function __construct(
        private readonly int $value,
        private readonly string $name,
    ) {
    }

    public static function narrow(): self
    {
        return self::of(0, 'Narrow');
    }

    public static function wide(): self
    {
        return self::of(1, 'Wide');
    }

    public static function spacerTail(): self
    {
        return self::of(2, 'SpacerTail');
    }

    public static function spacerHead(): self
    {
        return self::of(3, 'SpacerHead');
    }

    /** Map a raw libghostty GhosttyCellWide value to its Wide. */
    public static function fromInt(int $value): self
    {
        switch ($value) {
            case 0:
                return self::narrow();
            case 1:
                return self::wide();
            case 2:
                return self::spacerTail();
            case 3:
                return self::spacerHead();
            default:
                throw new \InvalidArgumentException("Unknown GhosttyCellWide value: {$value}.");
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function name(): string
    {
        return $this->name;
    }

    private static function of(int $value, string $name): self
    {
        return self::$cache[$value] ?? (self::$cache[$value] = new self($value, $name));
    }
}
