# CI's PHP comes from setup-php; only libghostty comes from the flake

The CI matrix gets its PHP from `shivammathur/setup-php` and its libghostty-vt
from the flake, built once per OS and passed to the test jobs as an artifact.
`nix develop` remains the development shell, unchanged. This revises
[ADR-0008](./0008-nix-flake-for-dev-and-ci.md), which had CI use the flake for
everything.

## What forced the reconsideration

Two failures on the first real CI run, both inherent to using nixpkgs for the PHP
matrix:

- **Slow.** Every one of ten matrix cells built libghostty-vt — and, it appears,
  PHP-with-FFI — from source, with no binary cache. The run ground on for many
  minutes doing the same builds ten times over.
- **No EOL PHP.** nixpkgs refuses end-of-life versions. PHP 8.1 went EOL and the
  `php81` jobs died with `error: php81 is EOL`. nixpkgs will keep dropping
  versions as they age; setup-php keeps them, and provides 7.4 and 8.0 besides.

setup-php answers both: prebuilt PHP for every version, in seconds. And the two
jobs that only resolve or transform PHP — `module-deps` and `downgrade` — need no
libghostty at all, so they drop nix entirely and run as plain setup-php.

## Why ADR-0008's drift objection does not return

ADR-0008 kept CI on the flake precisely to avoid setup-php: "two definitions would
drift, and the thing they would drift on is the libghostty-vt commit." That
objection is answered rather than ignored. The library — the untagged, in-flux
thing that would drift — still comes from `nix build .#libghostty-vt`, pinned by
`flake.lock`, in both the dev shell and CI. Only the PHP *interpreter* differs
between them, and a released PHP at a given version is interchangeable in a way a
commit of an unreleased C library is not. The split runs along the right seam:
nix owns what must be pinned, setup-php owns what is already standardised.

## Shape

- One `libghostty` job per OS builds the library and uploads its `lib/` (store
  symlinks dereferenced with `cp -L`) as an artifact — two slow builds, not ten.
- The `test` matrix (PHP 8.2–8.5 × Linux, macOS) downloads that artifact, sets
  `PHPTY_LIBGHOSTTY_VT` to it, and runs on setup-php PHP.
- `module-deps` and `downgrade` are setup-php only.

## Consequences

- CI now uses two toolchains. That is the cost ADR-0008 avoided; it is paid
  because the seam is clean (interpreter vs pinned native library) and the drift
  it warned of is prevented at the library, where it matters.
- A binary cache (Cachix) would still speed the two libghostty builds. It is no
  longer urgent — they run twice, not ten times — but remains the next lever.
- The dev experience is untouched: `nix develop` still gives PHP, FFI, Composer,
  and the pinned libghostty in one command.
