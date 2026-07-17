# VTerm binds libghostty-vt rather than reimplementing the emulator

VTerm binds an existing terminal emulator through FFI and implements no emulation
of its own — the shortest path to a trustworthy harness, and the harness is what
makes everything else measurable. A pure-PHP emulator stays a long-term goal;
reaching for it now would mean porting many thousands of lines with nothing to
check the result against, whereas doing it later means the binding is the
verification oracle.

The library is **libghostty-vt** (MIT, © 2024 Mitchell Hashimoto and Ghostty
contributors), the terminal core extracted from Ghostty. It is bindable from PHP:
`build.zig` installs a shared library via `GhosttyLibVt.initShared()`, and PHP's
FFI can only `dlopen` a shared object — a static-only library would have been
unusable, which is how the Rust and Go bindings get away with linking statically
and we cannot.

## Considered options

- **libvterm** — the obvious choice, and what upstream `vterm-gem` binds. Stable
  and packaged, but stagnant (0.3.3 dates from 2023), Unix-oriented, and with no
  grapheme-cluster support at all: it measures width per codepoint, so emoji ZWJ
  sequences and VS16 presentation come out wrong. libghostty-vt models mode 2027
  clustering and exposes `ghostty_unicode_grapheme_width()`.
- **A pure-PHP emulator now** — see above.

## What this does not buy

**Neither library solves ambiguous width.** `ghostty_unicode_codepoint_width()`
takes a codepoint and nothing else — no context, no configuration — and its
contract is explicit: 2 for East Asian Wide/Fullwidth, 1 for everything else. So
East Asian Ambiguous is 1, hardcoded, exactly as in libvterm and in
`symfony/string`. See [ADR-0007](./0007-harness-first-reline-undecided.md) for
why that matters and what it costs us.

## Consequences

- **The harness depends on an API in flux.** libghostty-vt has no tagged release
  and its signatures are expected to move. Its *behaviour* is proven — it is
  Ghostty's own core, fuzzed and shipped — but a verification foundation resting
  on an unstable interface is a real tension, accepted with open eyes.
- Contributors need a source build; there is no `brew install` for it. This is
  absorbed by [ADR-0008](./0008-nix-flake-for-dev-and-ci.md): Ghostty's flake
  exposes `libghostty-vt` as a package, so the Zig toolchain never enters this
  repository and `flake.lock` pins the commit. Verified end to end — PHP loads
  the resulting `.dylib` over FFI and `ghostty_unicode_codepoint_width()`
  answers 2 for `日`, 1 for `→`, exactly as claimed above.
- Windows and WebAssembly become reachable, since libghostty-vt targets both.
  This does not on its own reopen [ADR-0006](./0006-unix-only-first-milestone.md):
  VTerm stops being the obstacle, but Pty still is.
