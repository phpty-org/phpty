<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The bounded circular tape backing the kill ring, ported from the RingBuffer
 * class in lib/reline/kill_ring.rb. Pushes rotate the reading head; once `max`
 * entries are present the oldest is evicted. Pure data structure.
 */
final class RingBuffer
{
    private int $max;

    private int $size = 0;

    private ?RingPoint $head = null;

    public function __construct(int $max = 1024)
    {
        $this->max = $max;
    }

    public function push(RingPoint $point): void
    {
        if ($this->size === 0) {
            $this->head = $point;
            $this->head->backward = $this->head;
            $this->head->forward = $this->head;
            $this->size = 1;
        } elseif ($this->size >= $this->max) {
            $tail = $this->head->forward;
            $newTail = $tail->forward;
            $this->head->forward = $point;
            $point->backward = $this->head;
            $newTail->backward = $point;
            $point->forward = $newTail;
            $this->head = $point;
        } else {
            $tail = $this->head->forward;
            $this->head->forward = $point;
            $point->backward = $this->head;
            $tail->backward = $point;
            $point->forward = $tail;
            $this->head = $point;
            $this->size += 1;
        }
    }

    public function head(): ?RingPoint
    {
        return $this->head;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function empty(): bool
    {
        return $this->size === 0;
    }
}
