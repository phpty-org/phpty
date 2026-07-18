# Tty

Reads and changes the state of the Tty this process is attached to: raw mode,
size, and whether a stream is a Tty at all. This is the capability PHP lacks and
Ruby gets from `io-console`.

This module is the first work item of milestone 2, the Reline port
([ADR-0015](../docs/adr/0015-port-reline-as-milestone-2.md)); Reline is its
only planned consumer. Its API is deliberately io-console-shaped and small —
see [ADR-0016](../docs/adr/0016-tty-io-console-shaped.md) for the design and
the reasoning.
It alone carries an `stty` fallback — see
[ADR-0001](../docs/adr/0001-ffi-for-terminal-primitives.md). Its PHP floor is not
special: every module ships 7.4
([ADR-0003](../docs/adr/0003-php-version-uniform-dev-modern-ship-74.md)).

## Language

**Backend**:
An interchangeable implementation of every operation in this module against one mechanism. There are two — one over FFI, one shelling out to `stty` — and they are chosen at runtime.
_Avoid_: driver, adapter, strategy, provider

**Raw mode**:
The Tty state in which input is delivered a byte at a time, unbuffered, unechoed, and with no signal interpretation. The state a line editor needs, and the reason this module exists.
_Avoid_: cbreak, non-canonical mode

**Winsize**:
The row and column count of a Tty. Named after the `winsize` struct because both Backends ultimately report the same thing.
_Avoid_: size, dimensions, geometry

## Scope: this process's Tty only

The API operates on the Tty this process is attached to and takes no file
descriptor. This is not a simplification but a consequence of the fallback:
`stty` acts on its own stdin and cannot be pointed elsewhere, so an API taking
arbitrary descriptors would be one the `stty` Backend could not honour, and the
two Backends would stop being equivalent.

Operating on *another* terminal is Pty's job, which is FFI-only and can `ioctl`
any descriptor. The boundary falls out of the fallback requirement: **Tty is your
own terminal; Pty is one you made for someone else.**

## Neighbouring vocabulary

The word "console" is unavailable to us, and "terminal" is worse. Inside a PsySH
process both already mean several things:

| Name                                 | Belongs to | Means                                                       |
| ------------------------------------ | ---------- | ----------------------------------------------------------- |
| `Symfony\Component\Console`          | Symfony    | A CLI application framework — nothing to do with Tty state   |
| `Symfony\Component\Console\Terminal` | Symfony    | Width and height only                                        |
| `Psy\Readline\Hoa\Console`           | PsySH      | Hoa's terminal control, in the legacy readline path          |
| `Psy\Readline\Interactive\Terminal`  | PsySH      | Terminal control — the closest counterpart to this module    |
| `Psy\Util\Tty`                       | PsySH      | Tty detection helpers; a subset of this module               |

Naming this module `Console` would have added a third meaning of "console" to a
codebase that already carries two.

`Psy\Readline\Interactive\Terminal` does this module's job over `stty` alone, and
is worth reading before writing anything here.
