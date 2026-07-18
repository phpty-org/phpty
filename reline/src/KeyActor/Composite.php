<?php

declare(strict_types=1);

namespace PhPty\Reline\KeyActor;

/**
 * Layers several keymaps, ported from lib/reline/key_actor/composite.rb.
 *
 * Config stacks one-shot bindings over inputrc additions over the built-in
 * default map; the first layer with a hit wins for get(), and matching() is true
 * if any layer reports a prefix. Order therefore matters and is the caller's.
 */
final class Composite implements KeyActorInterface
{
    /**
     * @param list<KeyActorInterface> $keyActors
     */
    public function __construct(
        private readonly array $keyActors,
    ) {
    }

    public function matching(array $key): bool
    {
        foreach ($this->keyActors as $keyActor) {
            if ($keyActor->matching($key)) {
                return true;
            }
        }

        return false;
    }

    public function get(array $key)
    {
        foreach ($this->keyActors as $keyActor) {
            $func = $keyActor->get($key);
            if ($func !== null) {
                return $func;
            }
        }

        return null;
    }
}
