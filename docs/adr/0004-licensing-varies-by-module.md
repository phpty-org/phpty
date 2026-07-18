# Licensing varies by module, driven by upstream

> **Superseded by [ADR-0014](./0014-mpl-2.0-uniform-module-licence.md)** — every
> PhPty-authored module now ships under MPL-2.0 uniformly; the reline exception
> below carries over.

Each module is a derivative of a differently-licensed upstream, so a single project-wide licence is not available to us. A port is a derivative work and cannot be relicensed at will.

- **ScreenTest** — modelled on `yamatanooroti` (MIT © 2020 aycabta) closely enough in behaviour that we retain aycabta's notice rather than argue about whether reimplementing an idea makes a derivative work. An MIT notice costs nothing to keep and the credit is owed regardless.
- **VTerm** — original work, MIT, our copyright alone. It is *not* a port of `vterm-gem`: that gem binds libvterm, and we bind libghostty-vt ([ADR-0002](./0002-vterm-binds-libghostty-vt.md)), so there is no aycabta code or design left in it to attribute. libghostty-vt (MIT © 2024 Mitchell Hashimoto and Ghostty contributors) is `dlopen`ed at runtime and never redistributed by us, so it is a dependency and not an upstream.
- **Reline** — a port of `reline`, whose gemspec declares `license = 'Ruby'`: dual Ruby-licence/2-clause BSDL, © Yukihiro Matsumoto. It **cannot** be MIT. Reline also vendors `license_of_rb-readline` (BSD, © 2009 Park Heesob), so that notice carries through as well.
- **Tty**, **Pty** — written against POSIX and termios documentation rather than transliterated from `io-console` or Ruby's `PTY`, so they are original work and are licensed MIT. See [ADR-0005](./0005-port-fidelity-varies-by-layer.md), on which this depends.

The root `LICENSE` describes this split and points at each module's own `LICENSE`, so that every repository produced by the split carries the correct one.

## Consequences

Tty and Pty stay MIT only for as long as they are implemented from specifications. Transliterating from `io-console` at any later point would pull them into the Ruby licence and silently invalidate this decision.
