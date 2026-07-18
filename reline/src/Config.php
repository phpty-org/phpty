<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\KeyActor\Base;
use PhPty\Reline\KeyActor\Composite;
use PhPty\Reline\KeyActor\Emacs;
use PhPty\Reline\KeyActor\KeyActorInterface;

/**
 * Reline's configuration, ported from lib/reline/config.rb — the tier-1 subset.
 *
 * Tier 1 is emacs-only and does not parse inputrc yet (that is tier 7). What is
 * present is the real structure config.rb has, so the parser lands inside this
 * class later without reshaping: three layered keymaps per editing mode
 * (@oneshot over @additional over @default), the editing-mode label, and the
 * variables the rest of the port reads (keyseq_timeout, enable_bracketed_paste,
 * the mode strings, autocompletion / disable_completion). The vi_insert /
 * vi_command keymaps are absent (tier 5); add_default_key_binding_by_keymap for
 * those labels is silently dropped, so the ANSI gate's `set_default_key_bindings`
 * can call for all keymaps unconditionally as upstream does.
 *
 * This class replaces the tier-0 tests/FakeConfig double: it answers the same
 * KeyStroke-facing contract (key_bindings + editing_mode_is) with the same emacs
 * composite, so the KeyStroke tests drive the real Config now.
 */
final class Config implements ConfigInterface
{
    /** @var array<string, Base> inputrc additions, per editing mode (empty until tier 7) */
    private array $additionalKeyBindings;

    private Base $oneshotKeyBindings;

    /** @var array<string, Base> the built-in default keymaps, per editing mode */
    private array $defaultKeyBindings;

    private string $editingModeLabel = 'emacs';

    /** The keymap `add_default_key_binding` (no keymap arg) targets. */
    private string $keymapLabel = 'emacs';

    private int $keyseqTimeout = 500;

    private bool $enableBracketedPaste = true;

    private bool $showModeInPrompt = false;

    private string $viCmdModeString = '(cmd)';

    private string $viInsModeString = '(ins)';

    private string $emacsModeString = '@';

    private bool $autocompletion = false;

    private bool $disableCompletion = false;

    /** Upstream default is -1 (unlimited); config.rb:61. */
    private int $historySize = -1;

    /**
     * Keys that terminate incremental search (config.rb:261). Null until inputrc
     * parsing lands (tier 7); with no terminators only C-j ends the search.
     */
    private ?string $isearchTerminators = null;

    private bool $loaded = false;

    private bool $testMode = false;

    public function __construct()
    {
        $this->reset_variables();
    }

    public function reset_variables(): void
    {
        // Tier 1 wires only the emacs keymaps. The vi_insert / vi_command slots
        // are absent until tier 5; see the class docblock.
        $this->additionalKeyBindings = [
            'emacs' => new Base(),
        ];
        $this->oneshotKeyBindings = new Base();
        $this->defaultKeyBindings = [
            'emacs' => new Base(Emacs::MAPPING),
        ];
        $this->editingModeLabel = 'emacs';
        $this->keymapLabel = 'emacs';
        $this->keyseqTimeout = 500;
        $this->enableBracketedPaste = true;
        $this->showModeInPrompt = false;
        $this->autocompletion = false;
        $this->disableCompletion = false;
        $this->historySize = -1;
        $this->isearchTerminators = null;
        $this->loaded = false;
    }

    /**
     * Called from LineEditor#finish. Upstream flips vi_command back to vi_insert
     * and clears one-shot bindings; tier 1 has no vi, so only the clear remains.
     */
    public function reset(): void
    {
        $this->oneshotKeyBindings->clear();
    }

    // --- KeyStroke-facing contract (ConfigInterface) -----------------------

    public function key_bindings(): KeyActorInterface
    {
        // Oneshot over inputrc additions over the built-in default, per
        // config.rb:142-145. The user-defined layers win over the default map.
        return new Composite([
            $this->oneshotKeyBindings,
            $this->additionalKeyBindings[$this->editingModeLabel] ?? new Base(),
            $this->defaultKeyBindings[$this->editingModeLabel] ?? new Base(),
        ]);
    }

    public function editing_mode_is(string ...$labels): bool
    {
        return \in_array($this->editingModeLabel, $labels, true);
    }

    // --- Editing mode ------------------------------------------------------

    /** The active default keymap (upstream `editing_mode`). */
    public function editing_mode(): KeyActorInterface
    {
        return $this->defaultKeyBindings[$this->editingModeLabel] ?? new Base();
    }

    public function set_editing_mode(string $label): void
    {
        $this->editingModeLabel = $label;
    }

    // --- Key-binding mutators ----------------------------------------------

    /**
     * @param list<int>        $keystroke
     * @param string|list<int> $target
     */
    public function add_default_key_binding(array $keystroke, $target): void
    {
        $this->add_default_key_binding_by_keymap($this->keymapLabel, $keystroke, $target);
    }

    /**
     * @param list<int>        $keystroke
     * @param string|list<int> $target
     */
    public function add_default_key_binding_by_keymap(string $keymap, array $keystroke, $target): void
    {
        // vi keymaps are absent in tier 1; drop their bindings silently so the
        // ANSI gate can register for every keymap as upstream does.
        if (isset($this->defaultKeyBindings[$keymap])) {
            $this->defaultKeyBindings[$keymap]->add($keystroke, $target);
        }
    }

    /**
     * @param list<mixed>      $keystroke
     * @param string|list<int> $target
     */
    public function add_oneshot_key_binding(array $keystroke, $target): void
    {
        // IRB sets an invalid keystroke (a Reline::Key). Ignore it, as config.rb:149.
        foreach ($keystroke as $c) {
            if (!\is_int($c)) {
                return;
            }
        }
        $this->oneshotKeyBindings->add($keystroke, $target);
    }

    public function reset_oneshot_key_bindings(): void
    {
        $this->oneshotKeyBindings->clear();
    }

    // --- Variables read by the rest of the port ----------------------------

    public function keyseq_timeout(): int
    {
        return $this->keyseqTimeout;
    }

    public function enable_bracketed_paste(): bool
    {
        return $this->enableBracketedPaste;
    }

    public function show_mode_in_prompt(): bool
    {
        return $this->showModeInPrompt;
    }

    public function vi_cmd_mode_string(): string
    {
        return $this->viCmdModeString;
    }

    public function vi_ins_mode_string(): string
    {
        return $this->viInsModeString;
    }

    public function emacs_mode_string(): string
    {
        return $this->emacsModeString;
    }

    public function autocompletion(): bool
    {
        return $this->autocompletion;
    }

    public function set_autocompletion(bool $value): void
    {
        $this->autocompletion = $value;
    }

    public function disable_completion(): bool
    {
        return $this->disableCompletion;
    }

    public function history_size(): int
    {
        return $this->historySize;
    }

    /** Test/inputrc seam: the emacs history tests set this directly, as upstream does. */
    public function set_history_size(int $value): void
    {
        $this->historySize = $value;
    }

    public function isearch_terminators(): ?string
    {
        return $this->isearchTerminators;
    }

    public function test_mode(): bool
    {
        return $this->testMode;
    }

    public function loaded(): bool
    {
        return $this->loaded;
    }

    /**
     * inputrc parsing lands here in tier 7 (config.rb:122-215). Structured as a
     * no-op now so Core's `unless config.test_mode or config.loaded?` gate has a
     * real method to call and the parser slots in without moving call sites.
     */
    public function read(?string $file = null): void
    {
        $this->loaded = true;
    }
}
