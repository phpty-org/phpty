# phpty/vterm

Interpret a terminal byte stream into an inspectable screen by binding libghostty-vt through FFI.

> **Read-only mirror.** The canonical development repository is
> [phpty-org/phpty](https://github.com/phpty-org/phpty), a monorepo. This
> `phpty/vterm` repository is split out from it for distribution and is
> read-only: issues and pull requests are disabled, and any pull request opened
> here is closed automatically. Please contribute upstream.

## Install

```console
composer require phpty/vterm
```

## Requirements

- PHP `^7.4 || ^8.0`, with the `ffi` and `mbstring` extensions
- **libghostty-vt** available at runtime, its directory pointed to by the
  `PHPTY_LIBGHOSTTY_VT` environment variable

## License

MIT. See [LICENSE](LICENSE) and, for how licensing works across PhPty,
[the monorepo's LICENSE](https://github.com/phpty-org/phpty/blob/main/LICENSE).
