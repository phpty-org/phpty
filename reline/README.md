# phpty/reline

A port of Ruby's [Reline](https://github.com/ruby/reline) line editor: Unicode
width, grapheme and key-input handling for PHP terminal programs.

> **Read-only mirror.** The canonical development repository is
> [phpty-org/phpty](https://github.com/phpty-org/phpty), a monorepo. This
> `phpty/reline` repository is split out from it for distribution and is
> read-only: issues and pull requests are disabled, and any pull request opened
> here is closed automatically. Please contribute upstream.

## Status

**Port complete** against Reline gem 0.6.3. The incremental tier plan (tiers 0-7)
is finished: Unicode width and grapheme measurement, the IO gates, the full
single- and multi-line editor with the upstream-shape renderer, history and
incremental search, completion and the dialog UI, both vi keymaps and the `vi_*`
commands, Face SGR theming, and the inputrc parser.

A handful of upstream surfaces are deliberately **not** ported, consistent with
the unix/UTF-8-first milestone: the right prompt (`rprompt`), `auto_indent_proc` /
`output_modifier_proc`, the upstream-undefined `vi_alias` / `vi_comment_out`, the
Windows IO gate, and non-UTF-8 encodings (SJIS/EUC-JP). See
[`CONTEXT.md`](CONTEXT.md) for the exhaustive list.

See [`CONTEXT.md`](CONTEXT.md) and the monorepo's
[`docs/porting/`](https://github.com/phpty-org/phpty/tree/main/docs/porting) for
the tier plan and the porting decisions.

## Install

```console
composer require phpty/reline
```

## Requirements

- PHP `^7.4 || ^8.0`, with the `mbstring` extension

Grapheme clustering is done with PCRE's `\X` (the bundled PCRE2), so `ext-intl`
is **not** required.

## License

Reline is a port and keeps its upstream terms rather than the MPL-2.0 the rest of
PhPty ships under. Upstream Reline is offered under a dual grant — the Ruby
license or the 2-clause BSD license (© Yukihiro Matsumoto) — and vendors
rb-readline under a 2-clause BSD notice (© 2009 Park Heesob). All three travel
with this module:

- [`COPYING`](COPYING) — the Ruby license
- [`BSDL`](BSDL) — the 2-clause BSD alternative
- [`license_of_rb-readline`](license_of_rb-readline) — the rb-readline BSD notice

The `composer.json` `license` field expresses this as the SPDX expression
`(Ruby OR BSD-2-Clause)`. For how licensing works across PhPty as a whole — and
why this module is the one exception to the uniform MPL-2.0 — see
[the monorepo's LICENSE](https://github.com/phpty-org/phpty/blob/main/LICENSE.md).
