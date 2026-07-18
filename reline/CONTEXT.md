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

## Current status: tier 4

Completion and the dialog UI. Tiers 0-3 built the editor, history, and the
full-shape renderer whose dialog-overlay rows (ADR-0017) had been proven by the
upstream `RenderLineDifferentialTest` dialog cases but never driven by a real
dialog; tier 4 finally drives them.

- **Completion core, ported from line_editor.rb:816-1352.** `complete` (Tab / `^I`,
  the only completion binding the 0.6.3 emacs keymap has) runs
  `retrieve_completion_block` — the word-break/quote scan that splits the line into
  preposing/target/postposing — then `call_completion_proc` (arity chosen by
  reflecting the callable, 1/2/3+ args as upstream reads `proc.parameters`) and
  `perform_completion`: common-prefix insertion, the append character on a lone
  perfect match, and the `@completion_state` walk NORMAL →
  MENU_WITH_PERFECT_MATCH → PERFECT_MATCH → MENU that lists candidates on the
  second Tab (`MenuInfo`, rendered as the menu overlay). `move_completed_list` +
  `retrieve_completion_journey_state` drive cycling; `menu_complete` /
  `menu_complete_backward` / `completion_journey_up` are ported but, as in
  upstream, **bound to no emacs key** (upstream's own tests reach them by symbol,
  and so do the ported ones) — the pty tests therefore cycle with Tab.
- **Autocompletion (`Reline.autocompletion`, default off).** With it on, every edit
  rebuilds the completion journey (input_key), so the dropdown follows what is
  typed with no explicit Tab, and Tab cycles the journey instead of inserting a
  prefix. The accessor lives on `Config` (`autocompletion` / `set_autocompletion`),
  delegated through Core and the `Reline` facade.
- **Dialog UI, ported from line_editor.rb:446-798 + reline.rb:161-247.** `Dialog`,
  `DialogProcScope`, `add_dialog_proc`, `clear_dialogs`, `update_dialogs`,
  `update_each_dialog`, `dialog_range`, and the deferred-from-tier-2
  `upper_space_height` / `rest_height` (they are dialog geometry, not scroll
  inputs — the tier-2 note called this). `DEFAULT_DIALOG_PROC_AUTOCOMPLETE`
  (the autosuggest dropdown, incl. the scrollbar column and the block glyphs set
  in `reset`) is registered on Core as `:autocomplete` and pushed to the editor by
  `inner_readline` unless the gate is dumb, exactly as upstream. The dropdown
  positions itself below the cursor, or **flips above** when `rest_height` leaves no
  room and the cursor is `>=` the dialog height down the screen. The render()
  dialog branch paints each dialog's rows into overlay level `index + 3` (0 prompt,
  1 line, 2 the still-deferred rprompt), feeding the already-ported overlay
  renderer.
- **Ruby `instance_exec` → an explicit scope argument.** Upstream runs a dialog
  proc under `instance_exec(&proc)` so its `self` is the `DialogProcScope`; PHP has
  no clean equivalent over an arbitrary closure, so a dialog proc here is
  `function (DialogProcScope $scope): ?DialogRenderInfo` and reads the same surface
  through `$scope`. This is the one idiom deviation in the dialog path, recorded on
  `DialogProcScope`. `CursorPos` is immutable here, so `set_cursor_pos` replaces it
  rather than mutating `.x`/`.y`.
- **The injected-not-global completion settings.** Upstream's completion
  configuration (`completion_proc`, `completion_append_character`, the
  basic/completer word-break and quote characters, `dig_perfect_match_proc`) lives
  on `Reline::Core` and `retrieve_completion_block` reaches the word-break/quote
  sets back through the `Reline` module. Following the injected-not-global IO/history
  deviation, Core owns the canonical settings (with the upstream accessors:
  `completion_proc=`, `completion_append_character=` with its single-char
  normalisation, `completer_word_break_characters=`, `completer_quote_characters=`,
  `basic_*`, `filename_quote_characters=`, `special_prefixes=`, `completion_case_fold`
  → `Config#completion_ignore_case`) and `inner_readline` pushes the per-readline
  slice onto the editor, as upstream assigns `line_editor.completion_proc =` etc.
- **The dialog colour seam (tier 6 deferred).** Per the tier plan, the Face DSL is
  tier 6; tier 4 ships `Reline\Face`, a tiny class holding just the two built-in
  faces the dialog renderer reads (`:default` all-reset, `:completion_dialog` the
  dropdown colours) with their SGR bytes hardcoded to exactly what face.rb's
  `load_initial_configs` produces (`\e[0m\e[97;100m` etc.). `update_each_dialog`
  reads a face by name and indexes its default/enhanced/scrollbar slots, so tier 6
  drops the real DSL in behind `Face::get` without touching that call site — the
  class is the whole seam, with a prose pointer to tier 6 on it.

Still out (tiers 5-7): vi mode (both tables + the `vi_*` methods), rprompt,
`auto_indent_proc` / `output_modifier_proc`, Face theming (the DSL behind the seam
above), inputrc parsing. The renderer's rprompt row (overlay index 2) stays empty.

## Earlier status: tier 3

History: the store, its navigation, and incremental search. Tier 2 left
`ed_prev_history`/`ed_next_history` with the multiline vertical-motion branch live
and the `move_history` fall-through a guarded no-op; tier 3 fills that in and adds
the rest of the history commands the emacs keymap reaches.

- **`History` (`src/History.php`) ports `history.rb`.** Ruby's `Reline::History <
  Array` becomes a `final class` over an internal `list<string>` that re-exposes
  the surface reline touches: `ArrayAccess` for `HISTORY[i]` reads (with the
  upstream `check_index` bounds logic) and `HISTORY[i] = val` writes, `Countable`
  / `size()`, `IteratorAggregate`, and `push` / `concat` / `pop` / `shift` /
  `clear` / `delete_at`. The size cap is ported verbatim (history_size 0 drops
  everything, negative is unlimited, positive trims the oldest — and the leading
  part of an over-long batch). Upstream's `<<` is not a separate method: for a
  single value `push` computes the same one-entry trim, so every `HISTORY << val`
  maps to `push($val)`. `check_index`'s two failure modes survive as
  `\OutOfRangeException` (IndexError) and `\RangeException` (RangeError).
  Encoding: `safe_encode` is UTF-8-only (CONTEXT's non-UTF-8 note), so invalid
  bytes become U+FFFD rather than transcoding to a system encoding.
- **Injected, not global.** Upstream's `Reline::HISTORY` is a module constant
  (`reline.rb:528`, `History.new(Reline.core.config)`). Following the
  injected-not-global IO deviation, the store is owned by `Core`, handed to
  `LineEditor`'s constructor, and reached via `Reline::HISTORY()` on the facade;
  every `Reline::HISTORY` upstream is `$this->history` in the editor.
- **`move_history` and the navigation commands.** `move_history` (line_editor.rb:
  1607) is ported with its save-back semantics: the current buffer is stashed into
  `@line_backup_in_history` when leaving the fresh line, or written back into the
  store when leaving an already-recalled entry, so **an edited history line keeps
  its edit until the buffer moves off it** — the "leaves the original intact until
  accept" behaviour, which is upstream keeping edited copies in the store.
  `ed_prev_history`/`ed_next_history` now call it past the buffer ends;
  `ed_beginning_of_history`/`ed_end_of_history` (M-<, M->) landed too. `@history_
  pointer`, `@line_backup_in_history` reset per readline.
- **Incremental search (C-r / C-s).** `vi_search_prev`/`vi_search_next` (bound to
  C-r/C-s and M-P/M-N in the emacs keymap) drive `incremental_search_history` +
  `generate_searcher` (line_editor.rb:1451-1565). The `@waiting_proc` machinery is
  ported into `process_key`/`wrap_method_call`: while a search runs each key is fed
  to the proc, a multi-character key ends the wait, C-g cancels and restores, a
  termination key commits. The search prompt renders through the full-shape
  renderer — `@searching_prompt` overrides `@prompt` in `check_multiline_prompt`
  and is a `prompt_list` cache dependency, so the search row redraws as typed.
  `last_incremental_search` (upstream `Reline.last_incremental_search`, module
  level) is kept on the reused editor so it survives across readline calls, and is
  not cleared by `reset_variables`.
- **`add_history` on accept.** `Core::readline`/`readmultiline` take an
  `$add_history` flag (reline.rb:276,250): the chomped line / whole buffer is
  appended to the store when set and non-empty, mirroring upstream's `inner_
  readline` append. `Config` gains `history_size` (default -1) and a null
  `isearch_terminators`.

**Reachable from the emacs keymap and ported:** `ed_prev_history`/`previous_
history`, `ed_next_history`/`next_history`, `ed_beginning_of_history`/`beginning_
of_history` (M-<), `ed_end_of_history`/`end_of_history` (M->), `vi_search_prev`/
`reverse_search_history` (C-r, M-P), `vi_search_next`/`forward_search_history`
(C-s, M-N). **Not reachable in emacs, therefore skipped:** `ed_search_prev_
history`/`ed_search_next_history` (`history_search_backward`/`forward`) — the
0.6.3 emacs mapping binds no key to them (upstream's own tests note "doesn't have
default binding" and call them via `__send__`), so their `search_history` helper
is not ported. They and the vi keymap's history bindings land with vi (tier 5).

Still out (tiers 4-7): completion/dialogs, rprompt, `auto_indent_proc`, vi mode,
inputrc parsing (which is why `isearch-terminators` is unset). The renderer's
rprompt/menu/dialog rows stay empty, as at tier 1.

## Earlier status: tier 2

The multiline editor over the wrapping/scrolling renderer. Tier 1 already landed
the renderer in full upstream shape (ADR-0017) with the wrap and scroll *inputs*
present but undriven; tier 2 is the promised widening — it turns those inputs on
and ports the multiline buffer commands, reshaping nothing.

- **Wrapping and scrolling are now driven, not rewritten.**
  `wrapped_prompt_and_input_lines` / `split_line_by_width` (display-width wrap,
  wide chars refusing to straddle the right edge) and the
  `render_differential` scroll branch (`base_y` + `scroll_down` when content
  exceeds `screen_height`) were ported at tier 1; tier 2 simply feeds them a
  multi-line `@buffer_of_lines`. Vertical scroll is driven by `scroll_into_view`
  (advancing `@scroll_partial_screen` to keep the wrapped cursor visible) plus
  that `render_differential` `base_y` branch — both already present. **Divergence
  from the tier plan:** `upper_space_height`/`rest_height` (reline-architecture-map
  §4 lists them as scroll inputs) are in fact dialog geometry
  (`line_editor.rb:638,764`), consumed only by `dialog_range`; they belong to
  tier 4 and are deferred, not needed to drive scroll.
- **Multiline buffer commands, upstream bodies filled in:** `key_newline` +
  `insert_new_line` (split the line at the cursor), `insert_multiline_text` (the
  bracketed-paste target Core already routes to, CRLF-normalised), `ed_newline`
  widened to the emacs multiline accept path, `delete_text()` widened to the
  multi-line-buffer branches. `em_delete` (join at EOL), `em_delete_prev_char`
  (join at BOL), `ed_kill_line` (join), and `ed_next_char`/`ed_prev_char` (cross
  lines) already carried their multiline branches from tier 1 and are unchanged.
- **Cross-line vertical motion.** The tier brief names `ed_prev_line`/
  `ed_next_line`, but gem 0.6.3 has no such methods: up/down within a multiline
  buffer is the leading branch of `ed_prev_history`/`ed_next_history`
  (`line_editor.rb:1629,1646`) — move `@line_index`, keep the display column via
  `calculate_nearest_cursor`. Those two are ported with that branch live; the
  `move_history` fall-through is tier 3 and left a guarded no-op, exactly as the
  unbound history commands were in tier 1 (follow-the-diff, ADR-0015).
- **Multiline accept.** `confirm_multiline_termination` calls the caller's proc
  with the whole buffer plus a trailing newline (`line_editor.rb:1189`);
  `Core::readmultiline` mirrors `Reline#readmultiline` (reline.rb:250) — requires
  the confirm block, sets it on the editor, returns `whole_buffer`, or nil on C-d
  EOF. `Reline::readmultiline(prompt, confirm)` is the facade.
- **Dynamic prompt landed.** `prompt_proc` fell out naturally in
  `check_multiline_prompt` (its multiline branch), so it is ported: an unset proc
  gives `[prompt] * buffer.size`; a set proc maps the buffer to per-line prompts
  with the buffer-size padding. `auto_indent_proc`, `rprompt`, and dialogs remain
  tier 4+.

(At tier 2 the history store and navigation were still out; they landed at tier
3 — see the current-status section above.)

## Earlier status: tier 1

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
completion dialogs, and rprompt were absent at tier 1 (tiers 2/4); the renderer
branches that would drive them collapsed to no-ops with their inputs empty.
Wrapping/scrolling/multiline are now driven — see the tier-2 status above.

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

Tier 2 adds:

- `LineEditorMultilineTest` — the multiline subset of `test_key_actor_emacs.rb`:
  `\n` splitting the buffer, the `confirm_multiline_termination` accept gate,
  cross-line vertical motion, line joins at BOL/EOL, and the bracketed-paste
  `insert_multiline_text` (incl. CRLF normalisation).
- `UnicodeTest::testSplitLineByWidthEdgeCases` — `split_line_by_width` on empty
  input, exact fit, under-fit, a wide char refusing to straddle the boundary, and
  a non-zero offset.
- `ReadlineScreenTest` (tier-2 cases) — long input wrapping with a backspace
  across the wrap boundary, a wide char (`日本語`) moving whole to the next row,
  `Reline::readmultiline` over a heredoc-style confirm proc (via
  `subjects/readmultiline_subject.php`) showing both rows then accepting, and an
  8-line buffer scrolling a 6-row screen so the top scrolls off.

Tier 3 adds:

- `HistoryTest` — ports `test_history.rb`: the size cap (0 / negative / positive
  trimming), the index/get/set semantics with the two failure modes, push chains
  and `concat`, `pop`/`shift`/`delete_at`, iteration, and the UTF-8 encoding
  normalisation (the SJIS half is out of scope).
- `LineEditorHistoryTest` — the history cases of `test_key_actor_emacs.rb`:
  prev/next navigation (incl. a multiline history entry), the editor-side size
  cap, the "edited history line kept until accept" behaviour, M-</M->, and the
  full C-r/C-s incremental-search suite (to-back/to-front, front-and-back,
  mid-history, twice, last-determined reuse, csi-key cancel, C-g restore). The
  `isearch-terminators` case needs inputrc (tier 7) and is not ported.
- `ReadlineScreenTest` (tier-3 case) — one Session, two `Reline::readline` calls
  with `add_history`: type and accept a line, then arrow-up (C-p) on the next call
  recalls it and the Screen shows the recalled text (via
  `subjects/readline_history_subject.php`).

Tier 4 adds:

- `LineEditorCompletionTest` — the completion cases of `test_key_actor_emacs.rb`:
  Tab common-prefix insertion, the second-Tab candidate menu, the perfect-match
  FSM (incl. `dig_perfect_match_proc` and its disabled variant), the append
  character, quote-append at end of line, completion-ignore-case, mid-line
  completion, a nil-padded candidate list, and the autocompletion cycle. Bytes go
  through `KeyStroke` for Tab (`^I`); `menu_complete` / `menu_complete_backward` /
  `completion_journey_up` are driven by symbol, as upstream's helper does (they
  have no default emacs binding).
- `DialogTest` — the dialog positioning math against the in-memory `LoggingIO` on a
  small screen: the autocomplete dropdown opening below the cursor near the top,
  flipping above it near the bottom of a multiline buffer, and vanishing when the
  edit leaves no candidates.
- `CompletionScreenTest` — three ScreenTest scenarios on a real Pty: (1) Tab
  completes a common prefix; (2) `autocompletion=true` shows the dropdown below the
  cursor with the candidates on the Screen, Tab cycles the highlighted candidate
  into the buffer, and accepting erases the dialog; (3) the dropdown flips above
  the cursor when a multiline buffer drives the cursor to the bottom of a short
  Pty. Subjects live in `tests/subjects/readline_complete_subject.php`,
  `readline_autocomplete_subject.php`, and `readmultiline_autocomplete_subject.php`.

Run the whole monorepo suite with
`vendor-bin/phpunit/vendor/bin/phpunit --no-progress` from the repo root, inside
`nix develop .#default`.

## Licence

The one exception to PhPty's uniform MPL-2.0: as a port, Reline keeps upstream's
dual Ruby-license / 2-clause-BSD grant plus the rb-readline BSD notice. See the
[README](README.md#license) and [ADR-0014](../docs/adr/0014-mpl-2.0-uniform-module-licence.md).
