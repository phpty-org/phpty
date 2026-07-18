# Tty is io-console-shaped and small; escape logic belongs to Reline

Reline's platform needs divide cleanly in upstream: io-console (a C extension)
supplies raw mode and winsize; everything else — escape emission, DSR parsing,
pushback buffering, bracketed paste, signal traps, the dumb-tty fallback — is
plain string and signal handling inside Reline's own IO gate
(`docs/porting/reline-io-contract.md` has the full contract with call sites).
The Tty module copies that split: **Tty is io-console, nothing more.**

## The API

Four operations, on this process's own Tty only (the no-descriptor constraint
and its reason — `stty` cannot be pointed elsewhere — are in
[tty/CONTEXT.md](../../tty/CONTEXT.md)):

- `isTty(): bool` — `stream_isatty`, no Backend involved.
- `withRawMode(callable $fn, ?RawOptions $opts): mixed` — enter Raw mode, run
  `$fn`, restore the prior state on the way out, exception-safe. `RawOptions`
  carries `intr` (keep ISIG so Ctrl-C still signals — upstream's
  `raw(intr: true)`), `vmin`, `vtime` (upstream's `raw(min: 0, time: 0)`
  non-blocking peek). Scoped-only, no bare `enterRaw()`/`exitRaw()` pair: every
  upstream use is block-shaped, and an unpaired enter is exactly the bug a line
  editor must never ship (a wedged cooked-less shell).
- `getWinsize(): Winsize` / `setWinsize(int $rows, int $cols): void`.

Nothing else. No `getc`, no `select`, no cursor addressing: byte reads are
`stream_select` + `fread` on already-open streams, escape sequences are
strings — neither differs by Backend, so neither belongs behind the Backend
seam. Signals are `pcntl_signal`, likewise.

## The two Backends

Chosen at runtime, FFI preferred ([ADR-0001](./0001-ffi-for-terminal-primitives.md)):

- **Ffi**: `tcgetattr`/`tcsetattr(TCSANOW)` — clear `ICANON|ECHO`, clear
  `ISIG` unless `intr`, set `c_cc[VMIN]`/`c_cc[VTIME]` — and
  `ioctl(TIOCGWINSZ/TIOCSWINSZ)`. Struct layouts differ between Linux and
  macOS (termios `c_cc` size and constant values); both are known quantities,
  handled the way Pty already handles them.
- **Stty**: `stty -g` to save an opaque state string, `stty raw -echo` (plus
  `intr`-preserving variants and `min`/`time`) to enter, replay the saved
  string to restore; `stty size` / `stty rows R cols C` for Winsize.

The Backends are equivalent in outcome but not in cost: Stty pays a
fork+exec per state change, which makes upstream's per-keystroke
`min:0,time:0` peek (used only for macOS Terminal.app's ^V non-ASCII escape,
`ansi.rb:116-137`) three subprocesses per keystroke on the Stty Backend.
That is correct and slow, and acceptable: the Stty Backend is the fallback
for FFI-less installs, not the recommended path, and the peek is rare.

## Testing

Raw mode can only be observed from a process whose stdin *is* a Tty, so the
interesting tests run a small PHP Subject on a Pty via ScreenTest and assert
from both sides: the Subject reports what `withRawMode` returned, the test
asserts the Device end's termios actually changed (and was restored) and that
typed bytes stop echoing. Winsize tests drive `TIOCSWINSZ` from the Controller
end and read `getWinsize` in the Subject. This is milestone 1 testing
milestone 2's foundation, which is the point of having built it.

## Consequences

- Tty stays small enough that carrying two Backends is cheap, which is what
  makes the `stty` fallback honest rather than aspirational.
- Reline's IO gate (the ANSI/Dumb pair it will need) is a single
  backend-agnostic implementation in the reline module, consuming Tty. No
  third module appears between them.
- The `RawOptions` vmin/vtime surface exists for one upstream call site; if
  upstream drops the ^V peek, `RawOptions` shrinks to `intr` alone.
- Pty and Tty still share no code ([CONTEXT-MAP](../../CONTEXT-MAP.md)): both
  carry termios FFI declarations, and that duplication is tolerated until it
  hurts, per the existing decision.
