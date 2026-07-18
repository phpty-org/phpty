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
- **Ambiguous width has no owner yet.** Upstream reads `Reline.ambiguous_width`,
  measured once against the tty at startup. Tier 0 has no Core to run that probe,
  so `Unicode::ambiguousWidth()` is a static default of 1; the Core will set it
  via `setAmbiguousWidth()` when it lands.

## Current status: tier 0

The terminal-free foundation, headless-testable. Present:

- `Unicode` + `Unicode\EastAsianWidth` — grapheme width, ambiguous-width lookup,
  escape-aware measuring, and the emacs/vi word-motion scanners.
- `KeyStroke` — the byte-stream → key matcher (CSI/SS3 resolution, macro
  expansion), ported against a minimal `ConfigInterface` (Config itself is a
  later tier; a test double stands in).
- `KeyActor\Base` / `Composite` / `Emacs` — the keymap trie and the Emacs table.
- `Key` — the resolved-keypress struct.

**No line editor, no rendering, no real terminal I/O yet.** Those are tier 1+.

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
(`EastAsianWidthTest`, `EmacsMappingTest`) guard the generation step. Run the
whole monorepo suite with
`vendor-bin/phpunit/vendor/bin/phpunit --no-progress` from the repo root, inside
`nix develop .#default`.

## Licence

The one exception to PhPty's uniform MPL-2.0: as a port, Reline keeps upstream's
dual Ruby-license / 2-clause-BSD grant plus the rb-readline BSD notice. See the
[README](README.md#license) and [ADR-0014](../docs/adr/0014-mpl-2.0-uniform-module-licence.md).
