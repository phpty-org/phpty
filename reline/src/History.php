<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The history store, ported from lib/reline/history.rb.
 *
 * Upstream `Reline::History` is a subclass of `Array` that overrides the few
 * mutators it needs — push / `<<` / delete_at / `[]` / `[]=` — to enforce the
 * configured size cap and to normalise every stored entry through
 * `Reline::Unicode.safe_encode`. PHP has no Array to subclass, so the port keeps
 * an internal `list<string>` and re-exposes the surface reline actually touches:
 *
 * - `ArrayAccess` for `HISTORY[i]` reads (with the upstream `check_index` bounds
 *   logic) and `HISTORY[i] = val` writes (move_history stores the edited buffer),
 * - `Countable` / `size()` for `HISTORY.size`,
 * - `IteratorAggregate` for the `each` the search helpers rely on (ported here as
 *   plain `to_a()` slicing since PHP ranges differ from Ruby's),
 * - `push` (variadic) and `concat`, plus `pop` / `shift` / `clear` / `delete_at`.
 *
 * Upstream's `<<` (append-one, trimming a single overflow entry) is not a
 * separate method here: for a single value `push` computes exactly the same
 * `shift`-one result, so every `Reline::HISTORY << val` call site maps to
 * `push($val)`.
 *
 * Non-UTF-8 handling is out of scope (CONTEXT.md): `safe_encode` targets UTF-8
 * only, so the encoding normalisation replaces invalid bytes with U+FFFD rather
 * than transcoding to a system encoding as upstream's SJIS path would.
 *
 * @implements \ArrayAccess<int, string>
 * @implements \IteratorAggregate<int, string>
 */
final class History implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var list<string> */
    private array $entries = [];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Swap the backing config. Upstream's test suite does this to the singleton
     * HISTORY (`instance_variable_set(:@config, @config)`) so history_size follows
     * the test's own Config; the port keeps the seam for the same reason.
     */
    public function set_config(Config $config): void
    {
        $this->config = $config;
    }

    public function to_s(): string
    {
        return 'HISTORY';
    }

    /** @return list<string> */
    public function to_a(): array
    {
        return $this->entries;
    }

    public function size(): int
    {
        return \count($this->entries);
    }

    public function length(): int
    {
        return \count($this->entries);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function empty(): bool
    {
        return $this->entries === [];
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /** @return \ArrayIterator<int, string> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** @param int $offset */
    public function offsetExists($offset): bool
    {
        return isset($this->entries[$offset]);
    }

    /**
     * @param int $offset
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->entries[$this->check_index((int) $offset)];
    }

    /**
     * @param int|null $offset
     * @param string   $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->push((string) $value);

            return;
        }
        $this->entries[$this->check_index((int) $offset)] = Unicode::safe_encode((string) $value, 'UTF-8');
    }

    /** @param int $offset */
    public function offsetUnset($offset): void
    {
        $this->delete_at((int) $offset);
    }

    public function delete_at(int $index): string
    {
        $index = $this->check_index($index);
        $removed = $this->entries[$index];
        \array_splice($this->entries, $index, 1);

        return $removed;
    }

    /**
     * Append entries, enforcing the size cap (history.rb:31-50). history_size 0
     * drops everything; negative means unlimited; positive trims the oldest
     * entries (and, when the incoming batch alone overflows, drops the leading
     * part of the batch too). Every stored entry is normalised through safe_encode.
     */
    public function push(string ...$val): self
    {
        // If history_size is zero, all histories are dropped.
        if ($this->config->history_size() === 0) {
            return $this;
        }
        // If history_size is negative, history size is unlimited.
        if ($this->config->history_size() > 0) {
            $diff = \count($this->entries) + \count($val) - $this->config->history_size();
            if ($diff > 0) {
                if ($diff <= \count($this->entries)) {
                    \array_splice($this->entries, 0, $diff); // shift(diff)
                } else {
                    $diff -= \count($this->entries);
                    $this->entries = []; // clear
                    $val = \array_values(\array_slice($val, $diff)); // val.shift(diff)
                }
            }
        }
        foreach ($val as $v) {
            $this->entries[] = Unicode::safe_encode($v, 'UTF-8');
        }

        return $this;
    }

    public function concat(string ...$val): self
    {
        foreach ($val as $v) {
            $this->push($v);
        }

        return $this;
    }

    public function pop(): ?string
    {
        return \array_pop($this->entries);
    }

    public function shift(): ?string
    {
        return \array_shift($this->entries);
    }

    /**
     * Normalise and bounds-check an index, ported from history.rb:62-75. Negative
     * indices count from the end; wildly out-of-range integers raise RangeError
     * (here \RangeException) and in-range-but-absent ones raise IndexError (here
     * \OutOfRangeException), matching upstream's two failure modes.
     */
    private function check_index(int $index): int
    {
        if ($index < 0) {
            $index += \count($this->entries);
        }
        if ($index < -2147483648 || 2147483647 < $index) {
            throw new \RangeException("integer {$index} too big to convert to 'int'");
        }
        // If history_size is negative, history size is unlimited.
        if ($this->config->history_size() > 0) {
            if ($index < -$this->config->history_size() || $this->config->history_size() < $index) {
                throw new \RangeException("index=<{$index}>");
            }
        }
        if ($index < 0 || \count($this->entries) <= $index) {
            throw new \OutOfRangeException("index=<{$index}>");
        }

        return $index;
    }
}
