# VTerm

Interprets a byte stream — escape sequences and all — into a Screen that can be
read back and asserted against. VTerm is what makes terminal output testable:
without it, a test can only compare raw bytes, which says nothing about what a
user would have seen.

VTerm binds **libghostty-vt** through FFI and emulates nothing itself. A pure-PHP
emulator behind the same interface is a long-term goal — see
[ADR-0002](../docs/adr/0002-vterm-binds-libghostty-vt.md).

Despite the name, this module is not a port of `vterm-gem`: that gem binds
libvterm, and binding a different library means inheriting a different design.
The name survives because "vterm" is what the thing is — and because the library
we bind is itself called libghostty-**vt**.

## Language

**Cell**:
One position in a Screen: a character together with its attributes. Reading a Cell that is outside the Screen is an error, not an empty Cell.
_Avoid_: character, glyph, position, tile

**Write**:
Feeding bytes into a VTerm for interpretation. The direction is from the Subject towards the Screen — the opposite of writing to a Tty, where bytes go towards a display.
_Avoid_: feed, push, input

**Grapheme cluster**:
What occupies a Cell, as of mode 2027 — not a codepoint. An emoji ZWJ sequence is many codepoints and one cluster, and measuring it per-codepoint gives the wrong answer. libghostty-vt models this; libvterm does not, which is part of why it is not the library we bind.
_Avoid_: character, glyph, rune

### On direction

VTerm inverts the usual sense of read and write, and this reliably confuses.
`write` puts a program's *output* into the VTerm; `read` takes back what the
VTerm wants to send *upstream*, such as responses to cursor-position queries.
Neither has anything to do with the Subject's stdin.

### What VTerm cannot tell you

A VTerm decides how many Cells a character occupies, and for the East Asian
**Ambiguous** class it decides wrong for some users — 1, always, with no way to
ask for 2. This is not a libghostty-vt defect so much as a property of every
width table: `ghostty_unicode_codepoint_width()` takes a codepoint and nothing
else, and the width of an ambiguous character is not a property of the character.
It is a property of the terminal.

So a Screen produced here models a terminal that draws ambiguous characters
narrow. That is most terminals, and not the ones this project most wants to
serve. Anything asserted about `→ ① ※ α ─ ±` is asserted about that terminal
only. See [ADR-0007](../docs/adr/0007-harness-first-reline-undecided.md).
