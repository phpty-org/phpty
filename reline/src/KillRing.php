<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The emacs kill ring, ported from lib/reline/kill_ring.rb.
 *
 * A pure data structure: a bounded ring buffer of killed strings plus the small
 * state machine (FRESH/CONTINUED/PROCESSED/YANK) that decides whether successive
 * kills append into one entry or start a new one, and whether yank/yank-pop are
 * live. No IO, no terminal — used only by the emacs kill/yank commands.
 *
 * Ruby's Struct-based RingPoint (a doubly-linked node on a circular tape) is
 * ported as a plain node object. The Enumerable `each` is not needed by the
 * tier-1 command subset and is omitted (absence tracks upstream better than an
 * unused port; it returns when a consumer appears).
 */
final class KillRing
{
    private const STATE_FRESH = 'fresh';
    private const STATE_CONTINUED = 'continued';
    private const STATE_PROCESSED = 'processed';
    private const STATE_YANK = 'yank';

    private RingBuffer $ring;

    private ?RingPoint $ringPointer = null;

    private string $state = self::STATE_FRESH;

    public function __construct(int $max = 1024)
    {
        $this->ring = new RingBuffer($max);
    }

    public function append(string $string, bool $before_p = false): void
    {
        switch ($this->state) {
            case self::STATE_FRESH:
            case self::STATE_YANK:
                $this->ring->push(new RingPoint($string));
                $this->state = self::STATE_CONTINUED;
                break;
            case self::STATE_CONTINUED:
            case self::STATE_PROCESSED:
                $head = $this->ring->head();
                if ($before_p) {
                    $head->str = $string . $head->str;
                } else {
                    $head->str = $head->str . $string;
                }
                $this->state = self::STATE_CONTINUED;
                break;
        }
    }

    public function process(): void
    {
        switch ($this->state) {
            case self::STATE_CONTINUED:
                $this->state = self::STATE_PROCESSED;
                break;
            case self::STATE_PROCESSED:
                $this->state = self::STATE_FRESH;
                break;
            // FRESH and YANK: nothing to do.
        }
    }

    public function yank(): ?string
    {
        if (!$this->ring->empty()) {
            $this->state = self::STATE_YANK;
            $this->ringPointer = $this->ring->head();

            return $this->ringPointer->str;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null [current-yank, previous-yank]
     */
    public function yank_pop(): ?array
    {
        if ($this->state === self::STATE_YANK && $this->ringPointer !== null) {
            $prevYank = $this->ringPointer->str;
            $this->ringPointer = $this->ringPointer->backward;

            return [$this->ringPointer->str, $prevYank];
        }

        return null;
    }
}
