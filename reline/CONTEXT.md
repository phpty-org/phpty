# Reline

A port of Ruby's [Reline](https://github.com/ruby/reline) line editor, pinned to
gem **0.6.3** (the `references/reline` submodule, commit `edf8d6b`). Unlike the
other PhPty modules, this is a genuine port: file structure and method names
track upstream closely enough to follow its diffs. See
[ADR-0015](../docs/adr/0015-port-reline-as-milestone-2.md) for the port decision
and [`docs/porting/reline-architecture-map.md`](../docs/porting/reline-architecture-map.md)
for the tier plan and the upstream survey.

## The fidelity rule

Per [ADR-0005](../docs/adr/0005-port-fidelity-varies-by-layer.md), Reline is
tracked, not redesigned. Reline is ~8,200 lines of actively developed logic;
structural divergence would permanently lose access to upstream bug fixes. So:

- **Upstream method names survive as-is, snake_case.** `em_forward_word`,
  `vi_backward_word`, `east_asian_width`, `match_status` — PSR conventions
  notwithstanding. Classes are PascalCase because PSR-4 requires it of file
  names. File layout mirrors upstream: `lib/reline/key_stroke.rb` →
  `src/KeyStroke.php`, `lib/reline/key_actor/base.rb` → `src/KeyActor/Base.php`,
  `lib/reline/unicode/east_asian_width.rb` → `src/Unicode/EastAsianWidth.php`.
- **Follow-the-diff, don't improve.** When a method reads oddly for PHP, that is
  usually upstream's shape showing through, and it is kept on purpose.

## Language: mapping Ruby idioms

A few Ruby constructs have no PHP equivalent. The mappings are collected here,
once, rather than re-explained at each call site (per ADR-0005's consequences):

- **Symbols → strings.** Editing-command names (`:ed_insert`) and match statuses
  (`:matching_matched`) are Ruby Symbols; here they are plain PHP strings, and
  the status set is hand-rolled class constants — no native enums (ADR-0011).
- **`bytes` arrays → `list<int>`.** Key sequences are lists of 0..255 byte
  values. Where Ruby uses a byte Array as a Hash key (the KeyActor tries), PHP
  cannot, so the list is joined into a comma-separated string internally.
- **`grapheme_clusters` → PCRE `\X`.** Grapheme segmentation uses PCRE2's `\X`
  (the `u` flag), which follows the same UAX #29 rules Ruby's engine does. No
  `ext-intl` dependency is taken. The `\G`-anchored WIDTH_SCANNER becomes a
  manual offset walk yielding the same token stream.
- **A trailing `?` on a predicate is dropped.** `editing_mode_is?` becomes
  `editing_mode_is`; `String#capitalize` etc. are ported as private helpers.
- **Ambiguous width is set by the Core probe.** Upstream reads
  `Reline.ambiguous_width`, measured once against the tty at startup.
  `Core::may_req_ambiguous_char_width` now runs that probe (write `▽`, read the
  cursor column via DSR) and calls `Unicode::setAmbiguousWidth()`; on a dumb gate
  or non-tty it defaults to 1, as upstream.
- **The global `Reline::IOGate` becomes an injected gate.** Upstream selects one
  IO gate into a module constant and every class reaches it through that global.
  PHP has no clean constant-swap, and upstream's own render tests swap the
  constant to a fake — so this port injects the `IO` gate into `Core` and
  `LineEditor` (constructor) instead, and each `Reline::IOGate.foo` upstream is
  `$this->io->foo`. Same seam, made explicit.
- **`RenderedScreen` is an array, not a struct.** The `base_y`/`lines`/`cursor_y`
  frame cache is a mutable associative array here rather than a Ruby Struct; the
  cell-tuple row format (`[x, w, content]` triples, `null` for an absent overlay)
  is unchanged.
- **Deferred signals need ext-pcntl.** SIGWINCH/SIGCONT/SIGINT are trapped with
  `pcntl_signal` (handlers only flip a flag; the read loop services them via
  `handle_signal`), all guarded for pcntl's absence. Two tier-1 gaps, documented
  at their call sites: SIGCONT does not re-assert raw mode (that needs a bare,
  unscoped raw set Tty deliberately withholds per ADR-0016; the read loop's
  `withRawMode` scope re-establishes it next iteration), and `handle_interrupted`
  keeps the deferred-flag plumbing but does not re-raise Interrupt (SIGINT
  semantics are not exercised by the tier-1 tests).

## Current status: tier 1

The minimal single-line editor, driven end-to-end on a real pty. Added on top of
tier 0 (per [ADR-0017](../docs/adr/0017-renderer-ported-full-shape-exercised-by-tier.md)):

- `IO` + `IO\Ansi` + `IO\Dumb` — the full IO-gate contract
  ([reline-io-contract.md](../docs/porting/reline-io-contract.md)): raw mode via
  Tty, the DSR cursor-position probe with byte pushback, bracketed-paste
  prep/deprep, screen size, cursor/erase/scroll emission (scroll as `"\n"`
  repetition per ruby/reline#576), `getc` polling in 10ms slices servicing
  deferred signals, and the LIFO `ungetc` buffer. Windows is out of scope.
- `LineEditor` — buffer state (`@buffer_of_lines`, kept a list even though tier 1
  has one line, so tier 2 widens without reshaping), the emacs single-line
  command subset, and the **renderer ported in full shape** (ADR-0017):
  `render` / `render_differential` / `render_line_differential` with the
  cell-tuple rows, rendered-screen cache, and per-row then per-span diffing —
  never a simplified single-row renderer. Overlay levels degenerate to
  prompt-over-input with no dialogs/rprompt, exercising the same algorithm.
- `Config` — the emacs-only tier-1 subset behind the real config.rb structure
  (three layered keymaps per mode, keyseq_timeout, the mode/paste variables);
  inputrc parsing lands inside it at tier 7. It replaces the tier-0
  `tests/FakeConfig` double — the KeyStroke tests now drive the real `Config`.
- `Core` + `Reline` — `readline(prompt)`, `inner_readline`, `read_io` with the
  ESC/keyseq-timeout matching, and the ambiguous-width probe.
- `KillRing` (+ `RingBuffer`/`RingPoint`), `CursorPos` — the pure kill-ring data
  structure and the cursor-position value object.

**Commands landed** (emacs, single-line): `ed_insert`, `ed_digit`,
`ed_prev_char`, `ed_next_char`, `ed_move_to_beg`, `ed_move_to_end`,
`em_delete_prev_char`, `ed_delete_prev_word`, `em_delete_next_word`,
`em_next_word`/`ed_prev_word`, `em_delete` (C-d incl. EOF-on-empty), `key_delete`,
`ed_kill_line`, `em_kill_line`, `ed_clear_screen`, `ed_transpose_chars`,
`em_yank`/`em_yank_pop`, `insert_raw_char`, `ed_newline`/accept, plus
`ed_ignore`/`ed_unassigned`.

**History and vi are out** (tiers 3 and 5): unbound methods — arrow-up
`ed_prev_history`, undo, completion, all `vi_*` — dispatch to nothing via
`wrap_method_call`'s method-exists guard, the ed_unassigned-equivalent, so their
keymap entries are harmless and track upstream. Multiline, wrapping/scrolling,
completion dialogs, and rprompt are absent (tiers 2/4); the renderer branches
that would drive them collapse to no-ops with their inputs empty.

Scope deviations, all consistent with the unix/UTF-8-first milestone: non-UTF-8
encodings are not ported (upstream's SJIS handling in `safe_encode`, and the
`.encode('sjis')` variants of the word tests, are omitted), and `safe_encode`
supports a UTF-8 target only.

## Generated tables

Two source files are machine-generated from the upstream Ruby and must not be
hand-edited; regenerate them with their committed generators:

- `src/Unicode/EastAsianWidth.php` ← `bin/generate_east_asian_width.php`, which
  transcribes upstream's already-generated Ruby literal verbatim (Unicode 16.0.0)
  rather than re-deriving from `EastAsianWidth.txt`, so the width policy stays
  upstream's by construction.
- `src/KeyActor/Emacs.php` ← `bin/generate_emacs_mapping.php`, which parses the
  256-slot `EMACS_MAPPING` and preserves its per-slot comments for line-for-line
  diffability.

## Testing

Pure-logic upstream tests are ported into `tests/` (`UnicodeTest`,
`KeyStrokeTest`), keeping upstream method names and data. Table-integrity tests
(`EastAsianWidthTest`, `EmacsMappingTest`) guard the generation step. Tier 1 adds:

- `RenderLineDifferentialTest` — the full-shape row-diff renderer, ported from
  upstream's `RenderLineDifferentialTest` against an in-memory `LoggingIO`
  (the TestIO analogue): dialog-overlay and multibyte cases pass unchanged.
- `LineEditorEmacsTest` — the tier-1 subset of `test_key_actor_emacs.rb`, feeding
  key bytes through `KeyStroke::expand` into `input_key` and asserting the buffer
  split at the cursor (insertion incl. wide/combining, motion, backspace over
  日本語, kill/yank, transpose, C-d EOF).
- `KeyseqTimeoutTest` — `Core::read_io`'s ESC-vs-arrow disambiguation over a
  scripted gate (`ScriptedIO`), no real waits.
- `ReadlineScreenTest` — end-to-end `Reline::readline` on a pty via ScreenTest
  (echo, cursor motion + reinsertion, backspace across a wide char, kill-to-end,
  accept-line). The harness VTerm answers `\e[6n`, so the ANSI gate's DSR probes
  complete and the real rendering path is exercised (not the Dumb fallback).

Run the whole monorepo suite with
`vendor-bin/phpunit/vendor/bin/phpunit --no-progress` from the repo root, inside
`nix develop .#default`.

## Licence

The one exception to PhPty's uniform MPL-2.0: as a port, Reline keeps upstream's
dual Ruby-license / 2-clause-BSD grant plus the rb-readline BSD notice. See the
[README](README.md#license) and [ADR-0014](../docs/adr/0014-mpl-2.0-uniform-module-licence.md).
