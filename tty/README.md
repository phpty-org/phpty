# phpty/tty

Reads and changes the state of the Tty this process is attached to: raw mode,
size, and whether a stream is a Tty at all. This is the capability PHP lacks and
Ruby gets from `io-console`.

> **Read-only mirror.** The canonical development repository is
> [phpty-org/phpty](https://github.com/phpty-org/phpty), a monorepo. This
> `phpty/tty` repository is split out from it for distribution and is
> read-only: issues and pull requests are disabled, and any pull request opened
> here is closed automatically. Please contribute upstream.

## Install

```console
composer require phpty/tty
```

## Requirements

- PHP `^7.4 || ^8.0`, on a Unix host (macOS or Linux)
- The `ffi` extension is optional: with it, Tty uses the faster Ffi Backend;
  without it, Tty falls back to shelling out to `stty`, which works everywhere
  but pays a fork+exec per state change.

## Usage

```php
use PhPty\Tty\Tty;
use PhPty\Tty\RawOptions;

$tty = new Tty();

if ($tty->isTty()) {
    $size = $tty->getWinsize();
    echo "{$size->rows()}x{$size->cols()}\n";

    // Enter raw mode for the duration of the callback, restoring the prior
    // state on the way out — even if the callback throws. RawOptions keeps
    // signals on by default; pass one to change that or the read thresholds.
    $byte = $tty->withRawMode(static function () {
        return \fread(\STDIN, 1);
    });
}
```

## License

MPL-2.0. See [LICENSE](LICENSE) and, for how licensing works across PhPty,
[the monorepo's LICENSE](https://github.com/phpty-org/phpty/blob/main/LICENSE).
