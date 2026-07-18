<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\ConfigInterface;
use PhPty\Reline\KeyActor\Base;
use PhPty\Reline\KeyActor\Composite;
use PhPty\Reline\KeyActor\Emacs;
use PhPty\Reline\KeyActor\KeyActorInterface;

/**
 * The tier-0 stand-in for Reline::Config that KeyStroke talks to.
 *
 * Config proper is a later tier; this double reproduces only the behaviour the
 * KeyStroke tests exercise, and only for emacs mode (the sole mode those tests
 * use). It mirrors upstream Config's structure: a one-shot keymap layered over
 * the built-in Emacs default map, composed on demand by key_bindings(). The
 * add_* methods are the exact upstream names, including the IRB guard in
 * add_oneshot_key_binding that ignores non-integer key sequences.
 */
final class FakeConfig implements ConfigInterface
{
    private Base $oneshot;

    private Base $emacsDefault;

    private string $editingModeLabel = 'emacs';

    public function __construct()
    {
        $this->oneshot = new Base();
        $this->emacsDefault = new Base(Emacs::MAPPING);
    }

    /**
     * @param list<int>        $keystroke
     * @param string|list<int> $target
     */
    public function add_default_key_binding(array $keystroke, $target): void
    {
        $this->emacsDefault->add($keystroke, $target);
    }

    /**
     * @param list<mixed>      $keystroke
     * @param string|list<int> $target
     */
    public function add_oneshot_key_binding(array $keystroke, $target): void
    {
        foreach ($keystroke as $c) {
            if (!is_int($c)) {
                // IRB sets an invalid keystroke (Reline::Key). Ignore it.
                return;
            }
        }
        $this->oneshot->add($keystroke, $target);
    }

    public function set_editing_mode(string $label): void
    {
        $this->editingModeLabel = $label;
    }

    public function key_bindings(): KeyActorInterface
    {
        return new Composite([$this->oneshot, $this->emacsDefault]);
    }

    public function editing_mode_is(string ...$labels): bool
    {
        return in_array($this->editingModeLabel, $labels, true);
    }
}
