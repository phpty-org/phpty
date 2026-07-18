# PhPty

**Find out what your terminal program actually renders.**

PHP can run a program and capture its bytes. It cannot tell you what those bytes
would have *looked like* — escape sequences only become a display once something
interprets them. PhPty interprets them, so a test can assert on the screen a user
would have seen rather than on a stream of control codes.

> **Status: early.** Nothing here is usable yet. The design is written down in
> [`CONTEXT-MAP.md`](./CONTEXT-MAP.md) and [`docs/adr/`](./docs/adr/); the code
> is not. Names and interfaces will move.

## Modules

PhPty is a monorepo. Each module is developed here and distributed as its own
repository. They agree on licence — MPL-2.0 ([`LICENSE.md`](./LICENSE.md)) — and on platform:
all are developed on modern PHP and shipped as PHP 7.4, downgraded by Rector at
release — see [ADR-0003](./docs/adr/0003-php-version-uniform-dev-modern-ship-74.md)
and [ADR-0009](./docs/adr/0009-downgrade-on-release-with-rector.md).

| Module         | What it does                                                       |
| -------------- | ------------------------------------------------------------------ |
| `vterm/`       | Interprets a byte stream into a screen. Binds libghostty-vt via FFI |
| `screen-test/` | Runs a program on a pty and asserts on the screen it renders        |
| `pty/`         | Creates pseudo-terminal pairs and starts child processes on them    |
| `tty/`         | Reads and changes the terminal state of the current process         |

The first milestone is `vterm` and `screen-test`. `pty` supports them. `tty` is
not started — see [ADR-0007](./docs/adr/0007-harness-first-reline-undecided.md)
for why, and for the question of whether a reline port should exist at all.

## Requirements

- **Nix**, and nothing else. `nix develop` gets you PHP with FFI, Composer, and a
  libghostty-vt built from the commit pinned in `flake.lock`. CI uses the same
  flake — see [ADR-0008](./docs/adr/0008-nix-flake-for-dev-and-ci.md).
- **Unix.** Windows is deferred, not rejected — see
  [ADR-0006](./docs/adr/0006-unix-only-first-milestone.md).

```console
$ nix develop
PhPty  ·  8.4.23  ·  FFI on
```

Without Nix you would need a Zig toolchain and a source build of libghostty-vt,
which has no distribution package and no tagged release. PHP 7.4 and 8.0 are not
available in nixpkgs; this does not affect the first milestone, and ADR-0008
explains what happens if it ever does.

## Lineage

PhPty owes its ideas to work by [aycabta](https://github.com/aycabta) and the
Ruby community, and reimplements rather than transliterates them — see
[ADR-0005](./docs/adr/0005-port-fidelity-varies-by-layer.md).

- [vterm-gem](https://github.com/aycabta/vterm-gem) — the idea of binding an emulator so that rendering can be tested. `vterm/` binds a different library and so keeps none of its code
- [yamatanooroti](https://github.com/aycabta/yamatanooroti) — `screen-test/` takes its idea, not its design, and so does not take its name
- [reline](https://github.com/ruby/reline) — the original goal of this project, now an open question

The reference implementations are checked out as submodules under `references/`.
They are not part of PhPty and remain under their own licences.
