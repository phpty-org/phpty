# reline sample REPL

A tiny interactive PHP REPL that exists to show off `phpty/reline`'s line
editor. It is a standalone demo living at `sample/` in the monorepo — not a
module (no `composer.json`, no split/replace entry, no test suite, no Rector
downgrade).

## Run it

```
nix develop
php sample/repl.php
```

from the repo root. It also runs on a plain PHP install with `ext-mbstring`
and no `nix develop` at all: reline's `Tty` layer tries FFI + termios first
and falls back to shelling out to `stty` when FFI isn't available, so FFI is
not strictly required to get real raw-mode line editing. That fallback path
is itself worth trying — e.g. run under a PHP build without `ext-ffi` and
the sample still behaves like a normal terminal REPL.

## What to try

- `41 + 1` — naive eval, prints `42`.
- `$name = 'PhPty'` then `"Hello, $name"` — variables persist across lines.
- Press `↑` / `↓` to recall previous lines from history.
- Press `Tab` after typing `str` to complete to `strlen`, or `$na` to
  complete to `$name` (your own variables are completion candidates too).
- Press `C-r` to incrementally search history.
- `exit`, `quit`, or `Ctrl-D` to leave.

## Not a real REPL

The evaluator runs your input through PHP's `eval()` with no sandboxing
whatsoever — this is a demonstration of the line editor, not a safe or
production-worthy code evaluator, so only run it against input you trust.

## Using reline in your own project

This sample runs against the monorepo's working tree via the root
`vendor/autoload.php` (which maps `PhPty\Reline\` and `PhPty\Tty\`). To use
reline outside this repo, install the released package instead:

```
composer require phpty/reline
```
