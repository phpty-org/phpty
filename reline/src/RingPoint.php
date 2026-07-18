<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * A node on the kill ring's circular tape, ported from the RingPoint Struct in
 * lib/reline/kill_ring.rb. `backward`/`forward` are the circular links; `str` is
 * the killed text (mutable — kill-append rewrites it in place). Object identity
 * is the equality upstream relies on (`==` is `equal?`), which PHP `===` on
 * objects gives for free.
 */
final class RingPoint
{
    public ?RingPoint $backward = null;

    public ?RingPoint $forward = null;

    public function __construct(
        public string $str,
    ) {
    }
}
