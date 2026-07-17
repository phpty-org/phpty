# Release by downgrading on a release branch, then splitting to per-module repos

Modules are developed in modern PHP and distributed as PHP 7.4 packages. Rector
performs the downgrade at release time; the modern source is never distributed
and the downgraded code is never hand-edited. This is the established pattern from
PHPStan, Rector, and ECS — with the `.72`-style version suffix retired, so the
distributed package simply *is* the downgraded one, at the same version number.

## The flow

Releases are cut by tagging the monorepo. A tag fans out to every split repo at
the same version — versioning is **lockstep** (see below). On a tag, GitHub
Actions:

1. Downgrades the whole tree with Rector (`DowngradeLevelSetList::DOWN_TO_PHP_74`).
2. Commits the result to a `release` branch.
3. Validates the downgraded tree on **PHP 7.4** — see below.
4. Runs splitsh over the `release` branch, once per module, pushing each module's
   subtree to its own repository (`phpty-org/vterm`, `phpty-org/pty`,
   `phpty-org/screen-test`) with the same tag.
5. Packagist updates each split repo by webhook.

Development stays on `main` in modern PHP; the `release` branch holds only
generated, downgraded code.

## Why a release branch, and not straight to the split repos

splitsh reads a subtree out of a **monorepo ref** — it cannot split code that
does not exist in the monorepo's history. So the downgraded code must be
committed to a monorepo branch before splitsh can act on it. The release branch
is not a stylistic choice; it is splitsh's input. (Rector's own build pushes
straight to its single distribution repo precisely because it does not split —
it is one package, and we are several.)

## Why lockstep versioning

The milestone-1 modules are tightly coupled — ScreenTest depends on VTerm and
Pty, and all three move together. Independent version numbers would be machinery
serving no one at this stage, and would make inter-module constraints (VTerm
requires Pty) a thing to maintain rather than "same version, always". One
monorepo tag becomes the same tag in every split repo. This is Symfony's model,
and it is the right default until a module actually stabilises ahead of the
others.

## Why PHP 7.4 validation runs outside the flake

The project's own rule is to render before believing — so shipping 7.4 code that
was only ever run on 8.x would be the exact untested assumption this project
exists to reject. The downgraded tree is therefore tested on a real 7.4. nixpkgs
has no 7.4 ([ADR-0008](./0008-nix-flake-for-dev-and-ci.md)), so this one CI leg
uses `shivammathur/setup-php` at 7.4 with FFI. Two environment systems, with
clean roles: the flake is development truth, setup-php is the ship inspection.

## Consequences

- **Modules must be written to survive downgrade.** Rector's 7.4 set handles
  enums, `readonly`, first-class callables, `never`, and the like, but not
  everything (fibers, some `WeakMap` uses). What a module may use is bounded by
  what Rector can lower, and that bound is now a coding constraint, checked by the
  7.4 validation leg.
- **Prerequisites exist before any of this runs**: the split repos must be
  created, registered on Packagist, and given push credentials (a deploy key or
  token per repo). None of that exists yet.
- Generated downgraded code lives on the `release` branch. It is noise on the
  branch list, accepted because it is splitsh's required input and never touches
  `main`.
- `phpty-org/phpty` is the development repo of record; the split repos are
  read-only distribution mirrors, and a pull request against one belongs upstream
  (see [issue-tracker.md](../agents/issue-tracker.md)).
