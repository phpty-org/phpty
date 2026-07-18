# Reline is ported; it is milestone 2

The question [ADR-0007](./0007-harness-first-reline-undecided.md) deliberately
left open — port Reline, contribute to PsySH instead, or neither — is answered
by the owner (2026-07-19): **port it**. Three things changed since ADR-0007 was
written, and one did not.

What changed:

1. **Milestone 1 shipped.** VTerm, Pty and ScreenTest are released and on
   Packagist. The harness exists, so the port can be rendering-verified from
   its first line — upstream tests its rendering with yamatanooroti
   (`test/reline/yamatanooroti/test_rendering.rb`, 1,988 lines), and ScreenTest
   is that harness's PHP counterpart by design. The port is the first serious
   consumer of milestone 1, which is also the fastest way to find out what
   milestone 1 got wrong.
2. **The port stands on its own terms.** PHP has no maintained pure-PHP line
   editor with multiline editing, incremental cell-diff rendering, East Asian
   width handling, and measured — not tabulated — ambiguous width. `ext-readline`
   is a C binding with none of that. The port is a library for any PHP CLI
   program; PsySH is one possible consumer, not the premise.
3. **The upstream is surveyed.** `docs/porting/reline-architecture-map.md` and
   `docs/porting/reline-io-contract.md` map the 8,182-line upstream
   (gem 0.6.3, submodule `edf8d6b`) into layers and a dependency-ordered tier
   list. The unknowns ADR-0007 was hedging against are now enumerated.

What did not change: PsySH is still building its own interactive readline, and
this port still does not compete with it. Nothing here is aimed at PsySH;
contributing ambiguous-width measurement upstream remains open and is not
blocked by porting.

## Decisions

- **Prior decisions bind the port.** Fidelity:
  [ADR-0005](./0005-port-fidelity-varies-by-layer.md) — file structure and
  method names track upstream closely enough to follow its diffs. Licensing:
  [ADR-0014](./0014-mpl-2.0-uniform-module-licence.md) — the reline module
  keeps upstream's dual Ruby-licence/BSDL terms, with the rb-readline BSD
  notice. Platform: [ADR-0006](./0006-unix-only-first-milestone.md) — the
  Windows IO gate (556 lines) is not ported.
- **Upstream method names survive as-is.** `ed_prev_char`, `vi_yank`,
  `em_kill_line` stay snake_case in PHP, PSR conventions notwithstanding. This
  is what "track upstream closely enough to follow its diffs" costs; renaming
  ~200 dispatch-target methods would break the symbol-keyed keymap tables'
  correspondence with upstream and every future diff against them. Classes are
  PascalCase (PSR-4 requires it of file names): `lib/reline/line_editor.rb` →
  `reline/src/LineEditor.php`.
- **The port targets a pinned upstream.** Gem 0.6.3, the `references/reline`
  submodule commit. Upstream moves; the port follows by diffing releases, not
  by chasing HEAD.
- **Work is ordered by the tier list** in the architecture map: Tty first
  ([ADR-0016](./0016-tty-io-console-shaped.md)), then tier 0 (Unicode/width,
  KeyStroke, KeyActor — pure logic, no Tty dependency, parallelizable with
  Tty), tier 1 (minimal single-line editor over a real tty), then multiline,
  history, completion, vi mode, Face, inputrc — each tier shippable and tested
  before the next. The renderer port (tier 1's risk) gets its own ADR when it
  starts.
- **Tests port with the code.** Upstream's pure-logic tests (unicode,
  key_stroke, key_actor, config, history, kill_ring…) become PHPUnit tests in
  the same tier as their subject. Rendering tests go through ScreenTest, as
  upstream's go through yamatanooroti.

## Consequences

- Tty leaves the waiting room. ADR-0007's consequence "Tty waits until the
  Reline question is answered" is spent; Tty is the first work item.
- The module list grows by two (`tty/`, `reline/`), each a Composer package
  and a split repository like the rest. They enter the release split and the
  root `composer.json` `replace` block when first released, not before.
- ADR-0007's other consequence stands unchanged: settling the ambiguous-width
  divergence on a rendered grid still waits on an emulator that can draw
  ambiguous characters wide, which libghostty-vt cannot. The port *measures*
  the width at runtime as upstream does; the harness can verify the probe is
  emitted and answered, not that a wide-drawing tty changes the layout.
