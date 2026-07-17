# ScreenTest targets Unix only in the first milestone

Upstream yamatanooroti has two backends: vterm (121 lines) and Windows (560
lines, driving a real conhost through Win32 Console APIs via Fiddle). We take the
vterm one only. Windows is deferred, not rejected — PsySH runs there and reline
has a Windows IO backend, so it matters eventually.

The reason to defer is not only the five-fold difference in size. Upstream's
class structure exists *because* of the two backends: `TestCase.inherited`
detects available environments at runtime, builds an anonymous subclass per
environment with `Class.new`, and relocates test methods into it via
`method_added` and `remove_method`. None of that has a PHP equivalent. Targeting
one environment removes the need for environment detection altogether, so
dropping Windows also drops the least portable part of the design.

## Windows will not look like upstream's Windows

Upstream drives a real conhost on Windows because it had no emulator there —
libvterm was not an option, so the whole backend had to be rebuilt against Win32.
libghostty-vt targets Windows ([ADR-0002](./0002-vterm-binds-libghostty-vt.md)),
which removes that reason. A Windows port here would keep the *same* VTerm and
the same assertions, and differ only in how the pty is made: ConPTY instead of
`openpty`. That is a change confined to Pty, not a second backend.

So the obstacle is no longer the emulator. It is Pty, and it is much smaller than
the 560 lines upstream needed.

## Consequences

Adding Windows later needs ConPTY support in Pty and a way to select it, which is
ordinary work. What it no longer needs is a mechanism for running one test
against several rendering environments — there is only one.
