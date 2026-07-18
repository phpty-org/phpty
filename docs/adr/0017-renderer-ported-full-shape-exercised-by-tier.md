# The renderer is ported in full shape; tiers restrict what exercises it

Tier 1 of the Reline port ([ADR-0015](./0015-port-reline-as-milestone-2.md))
needs rendering, and upstream's renderer is the riskiest port: incremental
cell-diff drawing (`line_editor.rb:406,473,488`) with overlay levels for
dialogs, scroll handling, and a cached previous frame. The architecture map
suggested restricting tier 1 to a single-row renderer and generalising later.
That suggestion is declined.

**Decision: port the renderer's real shape once — cell-tuple rows, the
rendered-screen cache, per-row then per-span diffing — and let each tier
restrict what *exercises* it, not what *exists*.** Tier 1 drives it with
one-row content only (no wrap, no scroll, no dialogs); tier 2 adds the wrapping
and scroll inputs; tier 4 adds dialog overlay rows. The diff algorithm itself
is never rewritten between tiers.

Reasons:

- A "simplified single-row renderer" is a design of our own invention, which is
  exactly what [ADR-0005](./0005-port-fidelity-varies-by-layer.md) forbids for
  Reline: it would diverge from upstream precisely where upstream's bug-fix
  traffic is densest, then be thrown away one tier later.
- The renderer's inputs narrow naturally: with a one-line buffer and no
  dialogs, `render` builds one row, `render_differential` diffs one row, and
  the scroll branch is simply never entered. Restricting the *driver* costs
  nothing; restricting the *code* costs a second port.
- ScreenTest asserts on rendered Screens, not on which internal branch drew
  them — tier 1's tests are equally valid against the full-shape renderer.

Alongside the renderer, tier 1 ports the surrounding minimum: the IO gate pair
(Ansi and Dumb, consuming Tty per [ADR-0016](./0016-tty-io-console-shaped.md)),
the `Core` read loop with keyseq-timeout matching (`reline.rb:378-406`), a
hardcoded-emacs `Config` subset behind the existing `ConfigInterface`, and the
`LineEditor` buffer state with the single-line editing commands. Upstream
method bodies that tier 1 does not need (`vi_*`, dialog procs, multiline
motions) are simply absent — absence tracks upstream diffs better than stubs
that would drift.

## Consequences

- Tier 2 becomes a widening, not a rewrite: it adds
  `wrapped_prompt_and_input_lines` / `split_line_by_width` and turns the
  already-ported scroll branch on.
- The rendered-screen cache and cell-tuple row format are fixed vocabulary
  from tier 1 onward; a change there is an upstream-tracking event, not a
  refactor.
- Tier 1's test surface is honest: ScreenTest scenarios (echo, cursor motion,
  backspace across a wide character, kill-to-end, accept-line) assert what a
  user's tty shows, and the fake-gate unit tests port upstream's
  `render_line_differential` cases directly.
