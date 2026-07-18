<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\Config\InvalidInputrc;
use PhPty\Reline\KeyActor\Base;
use PhPty\Reline\KeyActor\Composite;
use PhPty\Reline\KeyActor\Emacs;
use PhPty\Reline\KeyActor\KeyActorInterface;
use PhPty\Reline\KeyActor\ViCommand;
use PhPty\Reline\KeyActor\ViInsert;

/**
 * Reline's configuration, ported from lib/reline/config.rb.
 *
 * As of tier 7 this is the full inputrc parser: three layered keymaps per editing
 * mode (@oneshot over @additional over @default), the editing-mode label, the
 * variables the rest of the port reads (keyseq_timeout, enable_bracketed_paste, the
 * mode strings, isearch_terminators, history_size, completion flags), the file
 * resolution order ($INPUTRC / ~/.inputrc / XDG), $if/$else/$endif/$include, `set`,
 * and `bind_key` / macro bindings into the additional-keymap layers.
 *
 * PORT DEVIATIONS FROM config.rb, all consistent with the UTF-8-first milestone:
 * - `Reline.encoding_system_needs` is UTF-8, so the SJIS/EUC-JP line-conversion
 *   branch of read_lines is dropped; a line that is not valid UTF-8 raises
 *   InvalidInputrc with the same message shape (see test_invalid_byte_sequence).
 * - `convert-meta` is seeded from the gate encoding passed to the constructor
 *   (upstream reads Reline::IOGate.encoding); it is stored but inert here, exactly
 *   as upstream stores it — nothing in the port converts meta bytes.
 * - Ruby Symbol function targets (`:history_search_backward`) are plain strings,
 *   and macro targets are `list<int>` byte lists — the same string|list<int> union
 *   Base.add already accepts.
 *
 * This class replaces the tier-0 tests/FakeConfig double: it answers the same
 * KeyStroke-facing contract (key_bindings + editing_mode_is) with the same
 * composite, so the KeyStroke tests drive the real Config now.
 */
final class Config implements ConfigInterface
{
    /**
     * config.rb:4 — the notation grammar for a bound key sequence. `\h` (Ruby hex
     * digit) becomes `[0-9A-Fa-f]` and `\d` (Ruby [0-9]) becomes `[0-9]`, the two
     * PCRE spellings that differ from Ruby's Onigmo. Scanned in byte order but with
     * the `u` flag so a multibyte mode-string char is one `.` token (a codepoint),
     * round-tripped through mb_chr in retrieve_string.
     */
    private const KEYSEQ_PATTERN = <<<'RE'
        ~\\(?:C|Control)-[A-Za-z_]|\\(?:M|Meta)-[0-9A-Za-z_]|\\(?:C|Control)-\\(?:M|Meta)-[A-Za-z_]|\\(?:M|Meta)-\\(?:C|Control)-[A-Za-z_]|\\e|\\[\\"'abdfnrtv]|\\[0-9]{1,3}|\\x[0-9A-Fa-f]{1,2}|.~u
        RE;

    private const RE_CTRL_META = <<<'RE'
        ~(?:\\(?:C|Control)-\\(?:M|Meta)|\\(?:M|Meta)-\\(?:C|Control))-([A-Za-z_])~
        RE;

    private const RE_CTRL = <<<'RE'
        ~\\(?:C|Control)-([A-Za-z_])~
        RE;

    private const RE_META = <<<'RE'
        ~\\(?:M|Meta)-([0-9A-Za-z_])~
        RE;

    private const RE_OCTAL = <<<'RE'
        ~\A\\([0-9]{1,3})~
        RE;

    private const RE_HEX = <<<'RE'
        ~\A\\x([0-9A-Fa-f]{1,2})~
        RE;

    /** config.rb:10-23. The names accepted by `set`; the boolean ones store inert. */
    private const VARIABLE_NAMES = [
        'completion-ignore-case', 'convert-meta', 'disable-completion',
        'history-size', 'keyseq-timeout', 'show-all-if-ambiguous',
        'show-mode-in-prompt', 'vi-cmd-mode-string', 'vi-ins-mode-string',
        'emacs-mode-string', 'enable-bracketed-paste', 'isearch-terminators',
    ];

    /** The `set` variables that are plain on/off booleans (config.rb:308-311). */
    private const BOOLEAN_VARIABLES = [
        'completion-ignore-case' => 'completionIgnoreCase',
        'convert-meta' => 'convertMeta',
        'disable-completion' => 'disableCompletion',
        'show-all-if-ambiguous' => 'showAllIfAmbiguous',
        'enable-bracketed-paste' => 'enableBracketedPaste',
    ];
    /** @var array<string, Base> inputrc additions, per editing mode (empty until tier 7) */
    private array $additionalKeyBindings;

    private Base $oneshotKeyBindings;

    /** @var array<string, Base> the built-in default keymaps, per editing mode */
    private array $defaultKeyBindings;

    private string $editingModeLabel = 'emacs';

    /** The keymap `add_default_key_binding` and inputrc `bind_key` target. */
    private string $keymapLabel = 'emacs';

    /** Prefix bytes prepended to every inputrc binding (config.rb:51, emacs-ctlx/meta). */
    private array $keymapPrefix = [];

    private int $keyseqTimeout = 500;

    private bool $enableBracketedPaste = true;

    private bool $showModeInPrompt = false;

    private string $viCmdModeString = '(cmd)';

    private string $viInsModeString = '(ins)';

    private string $emacsModeString = '@';

    private bool $autocompletion = false;

    private bool $disableCompletion = false;

    private bool $completionIgnoreCase = false;

    private bool $showAllIfAmbiguous = false;

    /** Upstream default is -1 (unlimited); config.rb:61. */
    private int $historySize = -1;

    /**
     * Keys that terminate incremental search (config.rb:261). Null until inputrc
     * parsing lands (tier 7); with no terminators only C-j ends the search.
     */
    private ?string $isearchTerminators = null;

    /**
     * Seeded from the gate encoding (US-ASCII -> true), config.rb:65. Stored but
     * inert in this port: nothing converts meta bytes.
     */
    private bool $convertMeta = false;

    /** The encoding the seven-bit convert-meta default is measured against. */
    private string $encoding;

    private bool $loaded = false;

    private bool $testMode = false;

    /** Memoised inputrc path (config.rb:118-120); null means unresolved. */
    private ?string $defaultInputrcPath = null;

    /**
     * @param string $encoding the gate encoding (upstream Reline::IOGate.encoding);
     *                         only its US-ASCII-ness is read, for convert-meta
     */
    public function __construct(string $encoding = 'UTF-8')
    {
        $this->encoding = $encoding;
        $this->reset_variables();
    }

    public function reset_variables(): void
    {
        // The three editing modes each get a default keymap (config.rb:26-33) and
        // an empty inputrc-additions layer. vi_insert / vi_command landed at tier 5.
        $this->additionalKeyBindings = [
            'emacs' => new Base(),
            'vi_insert' => new Base(),
            'vi_command' => new Base(),
        ];
        $this->oneshotKeyBindings = new Base();
        $this->defaultKeyBindings = [
            'emacs' => new Base(Emacs::MAPPING),
            'vi_insert' => new Base(ViInsert::MAPPING),
            'vi_command' => new Base(ViCommand::MAPPING),
        ];
        $this->editingModeLabel = 'emacs';
        $this->keymapLabel = 'emacs';
        $this->keymapPrefix = [];
        $this->viCmdModeString = '(cmd)';
        $this->viInsModeString = '(ins)';
        $this->emacsModeString = '@';
        $this->keyseqTimeout = 500;
        $this->enableBracketedPaste = true;
        $this->showModeInPrompt = false;
        $this->autocompletion = false;
        $this->disableCompletion = false;
        $this->completionIgnoreCase = false;
        $this->showAllIfAmbiguous = false;
        $this->historySize = -1;
        $this->isearchTerminators = null;
        $this->convertMeta = $this->seven_bit_encoding($this->encoding);
        $this->defaultInputrcPath = null;
        $this->loaded = false;
    }

    /**
     * Called from LineEditor#finish. Upstream (config.rb:35-40) flips vi_command
     * back to vi_insert and clears one-shot bindings.
     */
    public function reset(): void
    {
        if ($this->editing_mode_is('vi_command')) {
            $this->editingModeLabel = 'vi_insert';
        }
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

    /** The active editing-mode label ('emacs' / 'vi_insert' / 'vi_command'). */
    public function editing_mode_label(): string
    {
        return $this->editingModeLabel;
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
        // Unknown keymaps are dropped silently so the ANSI gate can register for
        // every keymap unconditionally as upstream does.
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

    public function completion_ignore_case(): bool
    {
        return $this->completionIgnoreCase;
    }

    /** Test/inputrc seam (config.rb variable `completion-ignore-case`). */
    public function set_completion_ignore_case(bool $value): void
    {
        $this->completionIgnoreCase = $value;
    }

    public function show_all_if_ambiguous(): bool
    {
        return $this->showAllIfAmbiguous;
    }

    public function set_show_all_if_ambiguous(bool $value): void
    {
        $this->showAllIfAmbiguous = $value;
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

    /** config.rb variable `convert-meta`; stored but inert in this port. */
    public function convert_meta(): bool
    {
        return $this->convertMeta;
    }

    public function loaded(): bool
    {
        return $this->loaded;
    }

    // --- inputrc parsing (config.rb:92-378) --------------------------------

    /**
     * Resolve the inputrc path, config.rb:92-116. $INPUTRC wins; then ~/.inputrc
     * (kept ahead of XDG for GNU Readline compatibility); then the XDG
     * readline/inputrc if it exists and is already absolute; then ~/.config/…;
     * else fall back to ~/.inputrc even when absent.
     */
    public function inputrc_path(): string
    {
        $inputrc = \getenv('INPUTRC');
        if ($inputrc !== false && $inputrc !== '') {
            return self::expand_path($inputrc);
        }

        $home_rc_path = self::expand_path('~/.inputrc');
        if (\is_file($home_rc_path)) {
            return $home_rc_path;
        }

        $xdg = \getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '') {
            $path = $xdg . '/readline/inputrc';
            if (\is_file($path) && $path === self::expand_path($path)) {
                return $path;
            }
        }

        $path = self::expand_path('~/.config/readline/inputrc');
        if (\is_file($path)) {
            return $path;
        }

        return $home_rc_path;
    }

    private function default_inputrc_path(): string
    {
        return $this->defaultInputrcPath ??= $this->inputrc_path();
    }

    /**
     * Read and apply an inputrc file, config.rb:122-140. A missing file is not an
     * error (returns having only set `loaded`); an InvalidInputrc is warned and
     * swallowed, so a malformed rc never aborts startup.
     */
    public function read(?string $file = null): void
    {
        $this->loaded = true;
        $file ??= $this->default_inputrc_path();
        if (!\is_file($file)) {
            return;
        }
        $lines = \file($file);
        if ($lines === false) {
            return;
        }
        try {
            $this->read_lines($lines, $file);
        } catch (InvalidInputrc $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");
        }
    }

    public function reload(): void
    {
        $this->reset_variables();
        $this->read();
    }

    /**
     * The parser proper, config.rb:166-215. `lines` are raw lines (with their
     * newlines, as File.readlines / PHP file() give them).
     *
     * @param list<string> $lines
     */
    public function read_lines(array $lines, ?string $file = null): void
    {
        /** @var list<array{0: int, 1: bool}> $if_stack [lineno, skip] */
        $if_stack = [];

        foreach ($lines as $index => $line) {
            // Even in the UTF-8-only port, a line that is not valid UTF-8 cannot be
            // applied to the locale (config.rb:182-184).
            if (!\mb_check_encoding($line, 'UTF-8')) {
                throw new InvalidInputrc("{$file}:" . ($index + 1) . ": can't be converted to the locale UTF-8");
            }
            if (\preg_match('/\A\s*#/', $line) === 1) {
                continue;
            }

            $no = $index + 1;
            $line = \ltrim($this->chomp($line));
            if ($line !== '' && $line[0] === '$') {
                $this->handle_directive(\substr($line, 1), $file, $no, $if_stack);
                continue;
            }

            foreach ($if_stack as $entry) {
                if ($entry[1]) {
                    continue 2;
                }
            }

            if (\preg_match('/^set +([^ ]+) +(.+)/i', $line, $m) === 1) {
                // value ignores everything after a space, raw_value does not.
                $var = \strtolower($m[1]);
                $raw_value = $m[2];
                $value = \explode(' ', $raw_value, 2)[0];
                $this->bind_variable($var, $value, $raw_value);
            } elseif (\preg_match('/^\s*(?:M|Meta)-([a-zA-Z_])\s*:\s*(.*)\s*$/', $line, $m) === 1) {
                $this->bind_key('"\\M-' . $m[1] . '"', $m[2]);
            } elseif (\preg_match('/^\s*(?:C|Control)-([a-zA-Z_])\s*:\s*(.*)\s*$/', $line, $m) === 1) {
                $this->bind_key('"\\C-' . $m[1] . '"', $m[2]);
            } elseif (\preg_match('/^\s*(?:(?:C|Control)-(?:M|Meta)|(?:M|Meta)-(?:C|Control))-([a-zA-Z_])\s*:\s*(.*)\s*$/', $line, $m) === 1) {
                $this->bind_key('"\\M-\\C-' . $m[1] . '"', $m[2]);
            } elseif (\preg_match('/^\s*("' . self::keyseqBody() . '+")\s*:\s*(.*)\s*$/u', $line, $m) === 1) {
                $this->bind_key($m[1], $m[2]);
            }
        }

        if ($if_stack !== []) {
            $last = \end($if_stack);
            throw new InvalidInputrc("{$file}:{$last[0]}: unclosed if");
        }
    }

    /**
     * @param list<array{0: int, 1: bool}> $if_stack
     */
    private function handle_directive(string $directive, ?string $file, int $no, array &$if_stack): void
    {
        $parts = \preg_split('/\s+/', \trim($directive));
        $name = $parts[0] ?? '';
        $args = $parts[1] ?? null;

        switch ($name) {
            case 'if':
                $condition = false;
                if ($args !== null && \preg_match('/^mode=(vi|emacs)$/i', $args, $m) === 1) {
                    $mode = \strtolower($m[1]);
                    // NOTE: mode=vi means vi-insert mode.
                    $mode = $mode === 'vi' ? 'vi_insert' : $mode;
                    if ($this->editingModeLabel === $mode) {
                        $condition = true;
                    }
                } elseif ($args === 'term' || $args === 'version') {
                    // config.rb:230-231 — recognised but always false in 0.6.3.
                } else {
                    // Application name: reline matches "Ruby" and "Reline" (config.rb:232-234).
                    if ($args === 'Ruby' || $args === 'Reline') {
                        $condition = true;
                    }
                }
                $if_stack[] = [$no, !$condition];
                break;
            case 'else':
                if ($if_stack === []) {
                    throw new InvalidInputrc("{$file}:{$no}: unmatched else");
                }
                $if_stack[\count($if_stack) - 1][1] = !$if_stack[\count($if_stack) - 1][1];
                break;
            case 'endif':
                if ($if_stack === []) {
                    throw new InvalidInputrc("{$file}:{$no}: unmatched endif");
                }
                \array_pop($if_stack);
                break;
            case 'include':
                if ($args !== null) {
                    $this->read(self::expand_path($args));
                }
                break;
        }
    }

    private function bind_variable(string $name, string $value, string $raw_value): void
    {
        switch ($name) {
            case 'history-size':
                $n = \filter_var($value, \FILTER_VALIDATE_INT);
                $this->historySize = $n === false ? 500 : $n;
                return;
            case 'isearch-terminators':
                $this->isearchTerminators = $this->retrieve_string($raw_value);
                return;
            case 'editing-mode':
                if ($value === 'emacs') {
                    $this->editingModeLabel = 'emacs';
                    $this->keymapLabel = 'emacs';
                    $this->keymapPrefix = [];
                } elseif ($value === 'vi') {
                    $this->editingModeLabel = 'vi_insert';
                    $this->keymapLabel = 'vi_insert';
                    $this->keymapPrefix = [];
                }
                return;
            case 'keymap':
                switch ($value) {
                    case 'emacs':
                    case 'emacs-standard':
                        $this->keymapLabel = 'emacs';
                        $this->keymapPrefix = [];
                        break;
                    case 'emacs-ctlx':
                        $this->keymapLabel = 'emacs';
                        $this->keymapPrefix = [0x18]; // C-x
                        break;
                    case 'emacs-meta':
                        $this->keymapLabel = 'emacs';
                        $this->keymapPrefix = [0x1b]; // ESC
                        break;
                    case 'vi':
                    case 'vi-move':
                    case 'vi-command':
                        $this->keymapLabel = 'vi_command';
                        $this->keymapPrefix = [];
                        break;
                    case 'vi-insert':
                        $this->keymapLabel = 'vi_insert';
                        $this->keymapPrefix = [];
                        break;
                }
                return;
            case 'keyseq-timeout':
                $this->keyseqTimeout = (int) $value;
                return;
            case 'show-mode-in-prompt':
                $this->showModeInPrompt = $value === 'on';
                return;
            case 'vi-cmd-mode-string':
                $this->viCmdModeString = $this->retrieve_string($raw_value);
                return;
            case 'vi-ins-mode-string':
                $this->viInsModeString = $this->retrieve_string($raw_value);
                return;
            case 'emacs-mode-string':
                $this->emacsModeString = $this->retrieve_string($raw_value);
                return;
        }

        if (isset(self::BOOLEAN_VARIABLES[$name])) {
            $prop = self::BOOLEAN_VARIABLES[$name];
            $this->{$prop} = $value === '1' || $value === 'on';
        }
        // Any other name (not in VARIABLE_NAMES) is silently ignored, config.rb:308.
    }

    /**
     * config.rb:314-317 — strip a surrounding pair of double quotes, then decode
     * the key-sequence escapes and reassemble the string.
     */
    private function retrieve_string(string $str): string
    {
        if (\preg_match('/\A"(.*)"\z/s', $str, $m) === 1) {
            $str = $m[1];
        }
        $out = '';
        foreach ($this->parse_keyseq($str) as $code) {
            $out .= $code < 128 ? \chr($code) : \mb_chr($code, 'UTF-8');
        }

        return $out;
    }

    /**
     * config.rb:319-322. Parses the binding and adds it to the current keymap's
     * additional layer, prefixed with the active keymap prefix.
     */
    public function bind_key(string $key, string $value): void
    {
        [$keystroke, $func] = $this->parse_key_binding($key, $value);
        if ($keystroke !== null && isset($this->additionalKeyBindings[$this->keymapLabel])) {
            $this->additionalKeyBindings[$this->keymapLabel]->add(\array_merge($this->keymapPrefix, $keystroke), $func);
        }
    }

    /**
     * config.rb:324-336. A quoted value is a macro (a byte list to input); an
     * unquoted value is a function name (upstream Symbol, here a snake_case string).
     *
     * @return array{0: list<int>|null, 1: string|list<int>}
     */
    public function parse_key_binding(string $key, string $func_name): array
    {
        $keyseq = \preg_match('/\A"(.*)"\z/s', $key, $m) === 1 ? $this->parse_keyseq($m[1]) : null;
        if (\preg_match('/"(.*)"/s', $func_name, $fm) === 1) {
            $func = $this->parse_keyseq($fm[1]);
        } else {
            $first = \preg_split('/\s+/', \trim($func_name))[0] ?? '';
            $func = \str_replace('-', '_', $first);
        }

        return [$keyseq, $func];
    }

    /**
     * config.rb:338-362 — one notation token to its byte code(s).
     *
     * @return int|list<int>
     */
    public function key_notation_to_code(string $notation)
    {
        if (\preg_match(self::RE_CTRL_META, $notation, $m) === 1) {
            return [0x1b, \ord($m[1]) % 32];
        }
        if (\preg_match(self::RE_CTRL, $notation, $m) === 1) {
            return \ord(\strtoupper($m[1])) % 32;
        }
        if (\preg_match(self::RE_META, $notation, $m) === 1) {
            return [0x1b, \ord($m[1])];
        }
        if (\preg_match(self::RE_OCTAL, $notation, $m) === 1) {
            return \intval($m[1], 8);
        }
        if (\preg_match(self::RE_HEX, $notation, $m) === 1) {
            return (int) \hexdec($m[1]);
        }
        switch ($notation) {
            case '\e': return 0x1b;
            case '\\\\': return 0x5c; // backslash-backslash
            case '\"': return 0x22;
            case "\\'": return 0x27;
            case '\a': return 0x07;
            case '\b': return 0x08;
            case '\d': return 0x64; // Ruby ?\d.ord is 'd', not DEL
            case '\f': return 0x0c;
            case '\n': return 0x0a;
            case '\r': return 0x0d;
            case '\t': return 0x09;
            case '\v': return 0x0b;
        }

        return \mb_ord($notation, 'UTF-8');
    }

    /**
     * config.rb:364-368 — scan the notation and flat-map each token to its codes.
     *
     * @return list<int>
     */
    public function parse_keyseq(string $str): array
    {
        \preg_match_all(self::KEYSEQ_PATTERN, $str, $matches);
        $codes = [];
        foreach ($matches[0] as $notation) {
            $code = $this->key_notation_to_code($notation);
            if (\is_array($code)) {
                foreach ($code as $c) {
                    $codes[] = $c;
                }
            } else {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function seven_bit_encoding(string $encoding): bool
    {
        return \strtoupper($encoding) === 'US-ASCII' || \strtoupper($encoding) === 'ASCII';
    }

    /** Ruby String#chomp: strip a single trailing \r\n / \r / \n. */
    private function chomp(string $line): string
    {
        return \preg_replace('/(\r\n|\r|\n)\z/', '', $line);
    }

    /**
     * The KEYSEQ_PATTERN body without the delimiters/flags, for embedding in the
     * quoted-binding line regex (config.rb:208).
     */
    private static function keyseqBody(): string
    {
        $pattern = self::KEYSEQ_PATTERN;

        return \substr($pattern, 1, \strrpos($pattern, '~') - 1);
    }

    /** Ruby File.expand_path: absolutise, expanding a leading ~ to $HOME. */
    private static function expand_path(string $path): string
    {
        if ($path === '~' || \strncmp($path, '~/', 2) === 0) {
            $home = \getenv('HOME');
            if ($home !== false && $home !== '') {
                $path = $home . \substr($path, 1);
            }
        }
        if ($path === '' || $path[0] !== '/') {
            $path = \getcwd() . '/' . $path;
        }

        return self::normalise_path($path);
    }

    /** Collapse `.` / `..` / redundant separators, as expand_path does. */
    private static function normalise_path(string $path): string
    {
        $isAbsolute = $path !== '' && $path[0] === '/';
        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                \array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . \implode('/', $segments);
    }
}
