# ScreenTest

Starts a Subject on a Pty, feeds its output into a VTerm, and asserts on the
resulting Screen. It answers a question no ordinary test can: *what would a user
actually have seen?* Comparing raw bytes cannot answer it, because escape
sequences only become a display after something interprets them.

This is the first milestone, and the reason is no longer "Reline needs it": PsySH
cannot verify its own rendering today, and this module is what would let it. See
[ADR-0007](../docs/adr/0007-harness-first-reline-undecided.md). Unix only for
now — see [ADR-0006](../docs/adr/0006-unix-only-first-milestone.md).

## Naming and lineage

This module reimplements the idea of aycabta's
[yamatanooroti](https://github.com/aycabta/yamatanooroti), not its code — the
design is rebuilt on PHPUnit ([ADR-0005](../docs/adr/0005-port-fidelity-varies-by-layer.md)),
so claiming the upstream name would overstate the kinship. "yamatanooroti" always
means the Ruby gem in this repository; **ScreenTest** always means this module.

The name says what distinguishes it: it asserts on a *Screen*, not on a stream.
That is exactly what a stream-grepping harness cannot do.

## Language

**Subject**:
The child process under test, started on a Pty and driven through its Controller. Upstream leaves this unnamed, calling it `command`.
_Avoid_: command, target, program, SUT

**Rendering**:
A Screen flattened into lines of text as a human would read them: fullwidth characters collapsed to one character, trailing spaces stripped. What assertions compare against. Upstream calls this `result`, which does not say what it is a result of.
_Avoid_: result, output, text, snapshot

**Sync**:
Draining the Controller into the VTerm until it would block, so that the Screen reflects everything the Subject has emitted so far. Every read and every write is bracketed by one.
_Avoid_: flush, drain, settle, wait

**Startup message**:
Output emitted before the Subject is ready, discarded rather than asserted against. Matched as a prefix or a pattern.
_Avoid_: banner, preamble, noise

### Two kinds of nothing in a Cell

A Cell holds two distinguishable absences, and conflating them corrupts a
Rendering. A **null character** is the second Cell of a fullwidth character —
the character belongs to the Cell before it, and this Cell contributes nothing.
An **empty character** is a Cell the cursor moved past without writing, and
contributes a space. Upstream distinguishes these in `result`; a port that
treats both as "blank" will silently mis-render every line containing Japanese
text.

### Sync writes back

Sync is not only a read loop. Bytes read *from* the VTerm are written back to the
Controller, because a VTerm answers queries — a cursor-position report, for
instance — exactly as a real Tty would. A Sync that only reads will hang against
any Subject that asks its terminal a question.
