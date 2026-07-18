# The development environment is a Nix flake, used by both the devShell and CI

PhPty's hardest dependency cannot be installed. libghostty-vt has no
distribution package and no tagged release; obtaining it means a Zig toolchain
and a source build at a commit you chose
([ADR-0002](./0002-vterm-binds-libghostty-vt.md)). Pinning an untagged,
in-flux dependency and reproducing its build is precisely what a flake input is
for, and Ghostty's own `flake.nix` already exposes `libghostty-vt` as a package
and an overlay — so the whole cost collapses into one input.

Everything else was verified present before deciding: nixpkgs carries PHP 8.2
through 8.5 (8.1 has since gone EOL and nixpkgs refuses it), its PHP extension
set includes `ffi` along with `posix`, `pcntl`, `mbstring`, `intl` and
`sockets`, and its `zig` is 0.15.2 — the same version
Ghostty pins through zig-overlay.

CI uses the same flake as the devShell rather than `setup-php` plus a hand-rolled
Zig step. Two definitions would drift, and the thing they would drift on is the
libghostty-vt commit — the exact failure Nix is here to prevent.

**Revised in [ADR-0013](./0013-hybrid-ci-setup-php-plus-nix-libghostty.md).** CI's
matrix now takes PHP from setup-php and only libghostty from the flake — after
this all-flake CI proved slow and unable to run EOL PHP. The drift objection
above still holds for the *library*, and that is exactly what the revision keeps
on nix; only the interpreter moves to setup-php.

## Why this matters more than usual here

This project's claim is that you cannot know what a terminal renders without
rendering it, and its product is a harness other people are meant to trust. A
harness whose own toolchain floats is a ruler that changes length. Reproducibility
is not hygiene here; it is the thing being sold.

## Verification status

The flake was built and run before this ADR was written, on aarch64-darwin:
`nix develop` yields PHP 8.4.23 with FFI, Composer, and a libghostty-vt built
from the pinned commit (`0.1.0-dev+73534c4`), exposing
`libghostty-vt.dylib` — the unversioned symlink FFI needs. PHP then loaded it and
`ghostty_unicode_codepoint_width()` returned 2 for `日` and 1 for `→`, confirming
[ADR-0002](./0002-vterm-binds-libghostty-vt.md) against the binary rather than
against its header comments. Nix reuses the one libghostty-vt build across every
PHP version in the matrix.

**Linux is unverified.** The flake declares four systems; one has been run. CI
exists partly to close that gap, and its first honest job is to tell us the flake
is a lie on some platform.

## Consequences

- **The dev floor rises as nixpkgs drops EOL PHP; the ship floor does not.**
  7.4 and 8.0 were never in nixpkgs; 8.1 went EOL and nixpkgs now refuses it, which
  first surfaced as a red CI job. So development is on 8.2–8.5 today and will climb
  as 8.2 ages out. None of this touches what we *ship*: every module is written in
  modern PHP ([ADR-0003](./0003-php-version-uniform-dev-modern-ship-74.md)) and
  shipped as 7.4. That 7.4 artifact must still be tested on a real 7.4, which
  nixpkgs cannot give us; the escape this ADR once named as hypothetical is now
  taken ([ADR-0009](./0009-downgrade-on-release-with-rector.md)): the release
  pipeline's 7.4 validation leg uses `setup-php`, outside the flake. The flake is
  development truth; setup-php is the ship inspection.
- Contributors need Nix. The floor was already higher than that — without it they
  need Zig and a source build — but it is still a PHP project asking for a tool
  most PHP developers do not have.
- CI is slower than `setup-php` unless a binary cache is configured.
- `nix flake update` becomes a deliberate, reviewable act. Given that
  libghostty-vt's API is expected to move under us, that is the point.
