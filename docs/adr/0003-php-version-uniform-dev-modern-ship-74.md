# PHP version is uniform: develop on modern PHP, ship PHP 7.4

Every module is developed on modern PHP — 8.1+, the nixpkgs floor
([ADR-0008](./0008-nix-flake-for-dev-and-ci.md)) — and distributed as PHP 7.4,
downgraded by Rector at release ([ADR-0009](./0009-downgrade-on-release-with-rector.md)).
The floor does not vary between modules. Two levels, one of each: 8.1+ to write,
7.4 to ship.

7.4 is PsySH's floor (`^8.0 || ^7.4`), and PsySH is the reason every module
reaches that far — directly for Tty and a future Reline, which would be embedded
in it, and indirectly for the harness, explained below.

## What this corrects

An earlier version of this decision had the floor *vary*: Tty and Reline at 7.4
to match PsySH, but Pty, VTerm, and ScreenTest on modern PHP only. The argument
was that ScreenTest starts the subject under test as a child process, so the
harness's PHP version is decoupled from the subject's — a harness on modern PHP
can drive a `php7.4` child.

That argument was **incomplete**. It decoupled the subject's *runtime*, but said
nothing about ScreenTest's *installability*. ScreenTest is a test framework — a
`require-dev` of the projects that use it, exactly as yamatanooroti is a dev
dependency of reline and irb. If PsySH is to use ScreenTest in its own suite,
Composer must resolve ScreenTest on PsySH's 7.4 CI leg — and a package requiring
`^8.1` cannot be installed there. So ScreenTest must reach 7.4, and because it
depends on VTerm and Pty, they must too. With Tty and Reline already at 7.4, the
floor collapses to a single value.

Downgrade is what makes this affordable: the modules are still *written* in
modern PHP, enums and all. Only the shipped artifact is 7.4.

## Consequences

- **Development runs on 8.1+, tests of the shipped artifact run on 7.4.** Unit
  tests execute under the nixpkgs PHP; the downgraded 7.4 build is exercised in a
  dedicated release leg on `setup-php`
  ([ADR-0009](./0009-downgrade-on-release-with-rector.md)).
- Modern-only syntax is fine to write but bounded by what Rector can lower to 7.4
  — a constraint enforced at release, not in the editor.
- What still varies between modules is licence ([ADR-0004](./0004-licensing-varies-by-module.md))
  and port fidelity ([ADR-0005](./0005-port-fidelity-varies-by-layer.md)) — not
  PHP version. The monorepo holds packages that differ in provenance but agree on
  platform.
