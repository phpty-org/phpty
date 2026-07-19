# phpty/screen-test

Drive a program through a pty and assert on the screen it renders.

> **Read-only mirror.** The canonical development repository is
> [phpty-org/phpty](https://github.com/phpty-org/phpty), a monorepo. This
> `phpty/screen-test` repository is split out from it for distribution and is
> read-only: issues and pull requests are disabled, and any pull request opened
> here is closed automatically. Please contribute upstream.

## Install

```console
composer require phpty/screen-test
```

## Requirements

- PHP `^7.4 || ^8.0`, with the `ffi`, `mbstring`, `pcntl` and `posix` extensions
- A Unix host, and libghostty-vt (via `phpty/vterm`) as for VTerm above

## License

MPL-2.0. See [LICENSE](LICENSE) and, for how licensing works across PhPty,
[the monorepo's LICENSE](https://github.com/phpty-org/phpty/blob/main/LICENSE.md).
