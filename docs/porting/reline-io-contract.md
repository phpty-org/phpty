# The terminal-I/O contract Reline requires

Working notes surveying upstream, for the Tty design (ADR-0016) and the port's
IO layer. Upstream vocabulary applies here, per the scope note in
`CONTEXT-MAP.md`. Surveyed at submodule commit `edf8d6b`, gem 0.6.3.

Layout note: this version has no `lib/reline/io/general.rb`; the non-tty
fallback is `Reline::Dumb` in `lib/reline/io/dumb.rb` (a `Reline::GeneralIO`
alias instance exists for embedding, `reline.rb:524`).

## 1. The IOGate interface

All calls go through `Reline::IOGate` (`= Reline::IO.decide_io_gate`,
`reline.rb:521`), an instance of `Reline::ANSI` or `Reline::Dumb` (or Windows,
out of scope). Base class `Reline::IO` supplies `dumb?`, `win?`,
`reset_color_sequence`, `read_single_char` (composes `getc` into one
valid-encoding character, `io.rb:39-51`).

| Method | Signature | Semantics | Called from |
|---|---|---|---|
| `encoding` | `() -> Encoding` | input's external encoding | `reline.rb:81`, `line_editor.rb:85`, `config.rb:65` |
| `input=` / `output=` | writers | swap the underlying IO objects | `reline.rb:178-186` |
| `get_screen_size` | `() -> [rows, cols]` | query | `reline.rb:208`, `line_editor.rb:142,176,1819` |
| `set_screen_size` | `(rows, cols)` | mutate (ANSI only) | public API/tests only |
| `with_raw_input` | `(&block)` | raw mode around the whole read loop | `reline.rb:256,278` |
| `win?` / `dumb?` | `() -> bool` | capability flags | `reline.rb:295,330,414`; `line_editor.rb:150` |
| `set_default_key_bindings` | `(config)` | platform escape-sequence bindings | `reline.rb:305` |
| `prep` | `() -> otio` | enter session mode (enable bracketed paste); opaque state | `reline.rb:307` |
| `deprep` | `(otio)` | leave session mode | `reline.rb:368` |
| `read_bracketed_paste` | `() -> String` | drain until `ESC[201~` | `reline.rb:347` |
| `read_single_char` | `(timeout) -> String?` | one char with `intr` disabled (quoted-insert) | `reline.rb:349` |
| `in_pasting?` | `() -> bool` | buffered paste bytes still in flight | `reline.rb:352` |
| `getc` | `(timeout) -> Integer?` | main read primitive; nil on timeout/EOF | `reline.rb:383` |
| `ungetc` | `(byte)` | pushback, LIFO | `reline.rb:401,512` |
| `move_cursor_column` | `(x)` | absolute column, 0-based | `reline.rb:363,416,425`; `line_editor.rb:193,415,425,431,550` |
| `move_cursor_up` / `down` | `(n)` | relative row move | `line_editor.rb:179,539,552` |
| `hide_cursor` / `show_cursor` | `()` | during redraw | `line_editor.rb:527,548` |
| `erase_after_cursor` | `()` | clear to end of line | `reline.rb:426`; `line_editor.rb:432,543` |
| `scroll_down` | `(n)` | scroll viewport | `line_editor.rb:192,530` |
| `clear_screen` | `()` | clear + home | `line_editor.rb:1818` |
| `cursor_pos` | `() -> CursorPos{x,y}` 0-based | query real cursor position | `reline.rb:423`; `line_editor.rb:144,180` |
| `set_winch_handler` | `(&handler)` | SIGWINCH (and SIGCONT) trap | `line_editor.rb:208` |
| `write` | `(string)` | raw output, buffered if active | `line_editor.rb:416,426,469` |
| `buffered_output` | `(&block)` | coalesce writes into one flush | `line_editor.rb:462,513` |
| `disable_auto_linewrap` | `(bool)` | Windows-only, guarded by `win?` | `line_editor.rb:522,555` |
| `reset_color_sequence` | `() -> String` | `"\e[0m"` (ANSI) / `""` (Dumb) | `line_editor.rb:416,426` |

## 2. Raw mode details (`io/ansi.rb`)

- `with_raw_input` (`ansi.rb:108-114`): `@input.raw(intr: true) { }` only if
  `@input.tty?`. `intr: true` keeps ISIG — Ctrl-C/Ctrl-Z still signal; echo and
  canonical mode off.
- `read_single_char` (`ansi.rb:293-298`) re-enters raw with `intr: false` for
  one quoted-insert read (C-c as a literal byte).
- `inner_getc` (`ansi.rb:116-137`): byte `0x16` (^V, macOS Terminal.app
  non-ASCII escape) triggers `@input.raw(min: 0, time: 0, &:getbyte)` — a
  **non-blocking single read with VMIN=0/VTIME=0**.
- `cursor_pos_internal` (`ansi.rb:189-206`) wraps its own raw block around
  writing `\e[6n` and parsing the reply.
- SIGCONT handler (`ansi.rb:283-288`) re-asserts raw mode after job-control
  resume.

io-console's `raw` maps to termios: clear `ICANON|ECHO` (and `ISIG` unless
`intr:`), `VMIN=1,VTIME=0` by default or per the `min:`/`time:` args.

## 3. Escape sequences

Emitted (`ansi.rb` unless noted): column `\e[{x+1}G` (:234), up `\e[{n}A`
(:239), down `\e[{n}B` (:247), hide/show cursor `\e[?25l`/`\e[?25h`
(:254,:258), erase `\e[K` (:262), scroll via literal `"\n" * n` — deliberately
not `CSI S`, see ruby/reline#576/#577 (:270), clear `\e[2J` + `\e[1;1H`
(:274-275), DSR `\e[6n` (:192), bracketed paste `\e[?2004h`/`l` (:302,:308)
gated on config + both-tty, probe glyph `▽` U+25BD written via output directly
(`reline.rb:418`).

Parsed: CPR `\e\[(\d+);(\d+)R` (`ansi.rb:198`), non-matching bytes pushed back
to `@buf` (:203). Bracketed-paste start `\e[200~` registered as a key binding
(`ansi.rb:56-60,139`); terminator `\e[201~` scanned by `read_bracketed_paste`
(:141-150). Generic CSI/SS3 classification lives in
`KeyStroke#match_unknown_escape_sequence` (`key_stroke.rb:80-114`), not the IO
layer.

## 4. Timeouts / nonblocking reads

- `inner_getc(timeout)` polls `@buf` first, else loops `wait_readable(0.01)` in
  10ms slices, calling `line_editor.handle_signal` between slices (deferred
  signal servicing), then one blocking `getbyte`.
- ESC disambiguation is one layer up (`reline.rb:378-406`): infinite timeout
  until `MATCHING_MATCHED`, then `keyseq_timeout`; leftover bytes pushed back
  via `ungetc` in reverse (LIFO).
- `in_pasting?` = `!empty_buffer?` (`ansi.rb:157-166`).

## 5. Signals

- **SIGWINCH**: ANSI traps it (`ansi.rb:278-291`), chains previous trap;
  handler just sets `@resized = true` (`line_editor.rb:207-210`); real work in
  `handle_resized` from the poll loop.
- **SIGCONT**: ANSI traps, re-asserts raw mode + re-render.
- **SIGINT**: trapped by LineEditor itself (`line_editor.rb:211-213`),
  deferred handling (`handle_interrupted`, :185-205); restored in `finalize`.
- **SIGTSTP**: not trapped; `intr: true` lets it through, recovery via SIGCONT.
- Dumb's `set_winch_handler` is a no-op.

## 6. Ambiguous-width probing (`reline.rb:408-427`)

Width forced to 1 when gate is dumb or either stdio is not a tty. Otherwise:
`move_cursor_column(0)`; write `▽`; `cursor_pos.x == 2 ? 2 : 1`; rehome; erase.
`Encoding::UndefinedConversionError` (LANG=C) forces 1.

## 7. The Dumb fallback (`io/dumb.rb`)

Chosen when `TERM=dumb` (`io.rb:6-8`). All screen-control methods no-ops;
`get_screen_size` returns a settable fixed `[24, 80]`; `cursor_pos` always
`(0,0)`; `with_raw_input` just yields; `getc` polls `wait_readable(0.1)` +
`read(1)`, still servicing signals, still supports `ungetc`. This is the shape
the PHP port needs for piped stdin and ScreenTest-driven runs.

## 8. What io-console provides that the PHP Tty module must supply

- `raw`/`raw!`: `tcgetattr`/`tcsetattr(TCSANOW)`, clear `ICANON|ECHO`
  (`|ISIG` unless intr), set `c_cc[VMIN]`/`c_cc[VTIME]` from `min:`/`time:`.
- `winsize`/`winsize=`: `ioctl(TIOCGWINSZ/TIOCSWINSZ)`.
- `tty?`: PHP has `stream_isatty` natively — no FFI needed.
- Byte reads + readiness: PHP `stream_select`/non-blocking reads suffice —
  not a Tty capability.

The `stty` fallback can express raw/cooked (`stty raw -echo` / restore via
`stty -g` saved string) and winsize (`stty size`, `stty rows R cols C`). It
**cannot** express an atomic per-call `VMIN=0/VTIME=0` peek without mutating
global state around every read (fork+exec per keystroke class) — the seam
ADR-0001 predicted. Pixel winsize fields are unrepresentable but unused.

Everything in §3–§7 (CSI/DSR emission and parsing, pushback, bracketed paste,
signal traps, the Dumb gate) is Reline-port logic **consuming** Tty, not Tty
itself — it is string I/O over an already-open stream, has no FFI/stty
equivalence problem, and stays a single backend-agnostic implementation.

## 9. Draft PHP Tty API mapping

| Ruby capability | Proposed Tty method | Kind |
|---|---|---|
| `$stdin.tty?` | `Tty::isTty()` (via `stream_isatty`) | query |
| `IO#raw { }` | `withRawMode(callable $fn, RawOptions $opts)` — scoped, auto-restoring; `$opts` carries `intr`, `vmin`, `vtime` | scoped state change |
| `raw(min:0, time:0)` peek | same `withRawMode` with vmin/vtime | scoped state change |
| `IO#winsize` | `getWinsize(): Winsize` | query |
| `IO#winsize=` | `setWinsize(int $rows, int $cols)` | state change |
| backend choice | one Tty facade choosing an Ffi or Stty Backend at runtime | factory |
| signal traps | not Tty — `pcntl_signal` in the Reline port | — |
| select/read/pushback | not Tty — stream functions in the Reline port | — |
