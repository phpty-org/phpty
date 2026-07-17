# Port fidelity varies by layer

"Port" does not mean one thing in this project. The upstreams differ too much in kind for a single fidelity rule, so each layer gets its own:

- **Tty, Pty** — designed from POSIX and termios documentation, not transliterated. There is no Ruby design worth preserving, and originality is what keeps them MIT ([ADR-0004](./0004-licensing-varies-by-module.md)).
- **VTerm** — not a port at all, though the name suggests one. It follows libghostty-vt's C API, which is the design; `vterm-gem` is glue over a *different* library ([ADR-0002](./0002-vterm-binds-libghostty-vt.md)) and has nothing left to lend it.
- **ScreenTest** — behaviour is preserved, the design is not. Upstream is built on test-unit's `TestCase` inheritance; ours is framework-agnostic, coupling to no test framework and offering only a thin, optional PHPUnit trait ([ADR-0010](./0010-testing-spans-74-to-85-via-polyfills.md)). It is renamed for exactly that reason: with the design gone — and moved further from any single framework than upstream ever was — the upstream name would overstate the kinship.
- **Reline** — file structure and method names track upstream closely enough to follow its diffs. Reline is roughly 8,200 lines of actively developed logic; structural divergence would mean permanently losing access to upstream bug fixes.

## Consequences

Reline will not read as idiomatic PHP, and that is deliberate. Ruby idioms with no PHP equivalent — blocks, Symbols, `method_missing` — still need per-case decisions, and each one is a small tear in the ability to follow upstream.
