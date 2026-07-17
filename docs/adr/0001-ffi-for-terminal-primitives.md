# Reach terminal primitives through FFI, with an `stty` fallback in Tty

PHP exposes no equivalent of Ruby's `io-console`: there is no `posix_openpt`, and `proc_open` yields pipes rather than a tty, so `termios`/`ioctl`/`openpty` are unreachable from core PHP. We reach them through FFI, which is verified to work in the CLI SAPI even under the default `ffi.enable=preload`, so only the presence of the extension matters — not its configuration.

Because FFI is a separate package in some distributions (Alpine's `php-ffi`), making it mandatory would narrow PsySH's reach. So the strategy is layered: **Tty** ships two interchangeable backends (FFI first, shelling out to `stty` as a fallback) to preserve reach for the PsySH runtime, while **Pty**, **VTerm**, and **ScreenTest** require FFI unconditionally — they are development tooling, where an install requirement is acceptable.

## Consequences

- Tty pays for an abstraction seam and two implementations of every operation, each needing its own tests.
- PTY creation has no `stty` fallback and never will; nothing that needs a PTY can run without FFI.

## Considered options

- **FFI everywhere, no fallback** — simpler and faster, and truer to "The Native Terminal Foundation", but PsySH breaks wherever FFI is unavailable.
- **`stty` only** — what PsySH and Symfony Console do today. Zero dependencies, but PTY creation is impossible, so ScreenTest could not exist.
- **A PECL extension** — fastest and most correct, but cannot be installed by Composer alone, which contradicts the goal of embedding in PsySH.
