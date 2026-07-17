# The harness comes first; porting Reline is not yet decided

PhPty began as "reimplement reline in PHP and embed it in PsySH". Examining
PsySH at close range changed the premise, on four points of evidence:

1. **PsySH is building its own interactive readline.** `src/Readline/Interactive/`
   landed on 2026-02-24 and has been actively developed since (34 commits), with
   a renderer, a soft-wrap-aware layout engine, actions, autosuggestion, history
   search, and a pager. It is opt-in behind `useExperimentalReadline()`, but it
   is plainly where PsySH is going. A Reline port would compete with the
   maintainer's own in-flight work.
2. **`Psy\Readline\Interactive\Terminal` already is what our Tty module would
   be**, implemented entirely over `shell_exec('stty …')`, with no FFI.
3. **PsySH measures character width correctly, except for ambiguous width.**
   `DisplayString::width()` delegates to `Symfony\Component\Console\Helper::width()`,
   which reaches `symfony/string` and its East Asian width tables. Measured:
   `日本語` → 6, `こんにちは` → 10, `あa` → 3, `🎉` → 2 — all correct.

   The gap is narrower and more specific. For the East Asian **Ambiguous** class
   of UAX #11 — `→ ① ※ α ─ ±` — Symfony returns 1 unconditionally, while these
   render two columns wide in an East Asian context. Reline does not guess: it
   writes `▽` (U+25BD), asks the terminal where the cursor landed, and takes the
   answer (`reline.rb:414-423`; ambiguous characters are marked `-1` in its width
   table and resolved with the measured value at `unicode.rb:78`). A user whose
   terminal draws ambiguous characters wide — iTerm2's "Treat ambiguous-width
   characters as double width", widely enabled among Japanese users — should see
   PsySH misplace the cursor on any line containing them, and Reline not.
4. **PsySH cannot test its own rendering.** Its only real-terminal test is
   `test/smoketest-pty.sh`: 301 lines of bash driving util-linux `script -qefc`,
   Linux-only (skipped entirely on macOS), asserting on normalised text. With no
   terminal emulator it cannot ask what a user would actually see.

So the first milestone stands unchanged — VTerm and ScreenTest — but its purpose
is re-stated. It is not scaffolding on the way to Reline. It is the
rendering-verification harness PsySH demonstrably lacks today, and it is valuable
on its own terms, to a project that is not competing with it.

Whether to port Reline at all is deliberately left open. Three outcomes are live:
port it, contribute ambiguous-width measurement to PsySH's own readline instead,
or do neither. Point 3 makes the middle option look considerably more attractive
than it did — the gap is one probe, not a line editor.

## On how point 3 was reached

Point 3 first read "PsySH has no East Asian width handling at all", concluded
from grepping the source for `east.?asian|wcwidth|fullwidth`, which found
nothing outside the legacy `Hoa/Ustring.php`. That was **wrong**: the handling is
there, delegated to Symfony, and a keyword search cannot see through a
delegation. Running the code took one command and produced the opposite answer,
plus a real and narrower finding underneath it.

This is recorded because it is the same claim the whole project rests on — that
you cannot know what a terminal renders without rendering it — arriving one
level up, uninvited. It is also why point 3's remaining claim is still hedged
with "should": the ambiguous-width mismatch is verified in the width function,
but its effect on a real screen has not been observed — and, per the consequences
below, will not be for some time.

## Consequences

- Tty is no longer on any critical path, since only Reline would consume it.
  It waits until the Reline question is answered.
- The first milestone must stand on its own merit, without "Reline needs it" as a
  justification.
- **Point 3 cannot be settled by this milestone, and possibly not by any
  binding.** Confirming it on a real screen needs a VTerm that draws ambiguous
  characters wide, and no available emulator does: libvterm hardcodes 1,
  libghostty-vt hardcodes 1, `symfony/string` hardcodes 1. They all tabulate, and
  the width of an ambiguous character is not in any table — it is a property of
  the terminal, which is exactly why Reline asks instead of looking up. A harness
  built on a library that hardcodes 1 would not merely fail to show the
  divergence; it would agree with PsySH and certify the behaviour as correct.
  Settling point 3 waits on a configurable emulator, which today means the
  pure-PHP VTerm of [ADR-0002](./0002-vterm-binds-libghostty-vt.md).
