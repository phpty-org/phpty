# Context Map

PhPty is a monorepo. Each module is developed here and distributed as its own
repository, split out with a tool in the manner of [splitsh/lite](https://github.com/splitsh/lite).
Every module owns its `composer.json` and its `LICENSE` — all PhPty-authored
modules ship under MPL-2.0 uniformly
([ADR-0014](./docs/adr/0014-mpl-2.0-uniform-module-licence.md)).
The PHP floor does not: all modules are developed on modern PHP and shipped as
7.4, downgraded by Rector at release
([ADR-0003](./docs/adr/0003-php-version-uniform-dev-modern-ship-74.md),
[ADR-0009](./docs/adr/0009-downgrade-on-release-with-rector.md)).

## Contexts

The first milestone — VTerm and ScreenTest, the rendering-verification harness
([ADR-0007](./docs/adr/0007-harness-first-reline-undecided.md)) — shipped as
v0.1.1. The second is the Reline port
([ADR-0015](./docs/adr/0015-port-reline-as-milestone-2.md)).

- [VTerm](./vterm/CONTEXT.md) — interprets a byte stream into a Screen
- [ScreenTest](./screen-test/CONTEXT.md) — drives a Subject through a Pty and asserts on the Screen it renders
- [Pty](./pty/CONTEXT.md) — creates pseudo-terminal pairs and starts child processes on them
- [Tty](./tty/CONTEXT.md) — reads and changes the state of the Tty this process is attached to. The first work item of milestone 2 ([ADR-0016](./docs/adr/0016-tty-io-console-shaped.md)); Reline is its only planned consumer
- Reline — a port of Ruby's reline (gem 0.6.3, pinned), built in tiers per the survey in [docs/porting/](./docs/porting/reline-architecture-map.md)

## Relationships

- **ScreenTest → Pty**: ScreenTest starts the Subject on a Pty and holds the Controller end
- **ScreenTest → VTerm**: ScreenTest feeds bytes read from the Controller into a VTerm, then asserts on its Screen
- **Reline → Tty**: Reline is the only runtime consumer of Tty; its Ansi gate enters Raw mode and reads Winsize through it
- **Pty ↔ Tty**: no dependency. Both bind libc through FFI, but they share no code until there is real duplication to remove

## Project-wide language

These terms mean the same thing in every context.

**Tty**:
The real terminal device a process is attached to. Also the name of the module that manipulates it.
_Avoid_: terminal, console, terminal emulator

**Pty**:
A pseudo-terminal pair, consisting of a Controller end and a Device end.
_Avoid_: terminal, tty

**VTerm**:
An in-memory terminal emulator that interprets a byte stream into a Screen.
_Avoid_: terminal, emulator

**Screen**:
The grid of Cells a VTerm has rendered — the state you assert against.
_Avoid_: buffer, display, output

**Module**:
A directory in this monorepo that becomes one Composer package and one split repository. The three are always one-to-one.
_Avoid_: package, component, subrepo

**Port**:
A reimplementation in PHP of an upstream Ruby project. How closely a port follows its upstream is decided per layer, not per project — see [ADR-0005](./docs/adr/0005-port-fidelity-varies-by-layer.md).
_Avoid_: rewrite, clone, binding

### On "terminal"

"Terminal" is never used on its own. In a single sentence of this domain it can
mean the Tty a developer sits at, a Pty pair, a VTerm, or the abstract device a
line editor writes escape sequences to. Every use names which one.

"Console" is likewise unavailable — `Symfony\Component\Console` and
`Psy\Readline\Hoa\Console` already mean two different things in an embedding
host. See [tty/CONTEXT.md](./tty/CONTEXT.md).

**Scope of the ban.** It applies to prose written for people who have this
glossary: code, ADRs, `CONTEXT.md` files, commit messages, issues. It does not
apply to the README or anything else addressed to a reader who has never seen
this file — to them, "terminal" is the ordinary and correct word, and refusing it
would buy precision they cannot use at the price of sense they can. The ban
exists to stop *internal* prose from equivocating, not to make the project
unable to introduce itself.
