# No native enums; hand-rolled value classes instead

Modules ship as PHP 7.4, downgraded by Rector ([ADR-0009](./0009-downgrade-on-release-with-rector.md)).
A native PHP enum used **as a type** — stored in a property, compared with `===`,
constructed from a value with `from()` — does not survive that downgrade. So we
do not use native enums for value types. Where a native enum is the obvious
modern choice, we write a `final class` with a private constructor and singleton
static instances instead. It runs identically on 7.4 and modern PHP, so Rector
leaves it alone and identity comparison holds.

## What was verified

Rector's only enum downgrade, `DowngradeEnumToConstantListClassRector`, turns an
enum into a class of `const` integers. It does **not** synthesise the implicit
backed-enum methods (`from()`, `cases()`, `tryFrom()`) — they are not in the
source, so there is nothing to copy — and it has no representation for an enum
*instance*. A downgraded `Wide` enum kept its cases as `const`s but lost `from()`,
while the call site `Wide::from($value)` remained. That passes `php -l` (a syntax
check) and then fatals at runtime on 7.4 with an undefined method — the worst
kind of failure, invisible until the shipped code runs.

The hand-rolled replacement (`vterm/src/Wide.php`) downgrades with no hazards
left — no `enum` keyword, no `::from(`, and all files parse — and its singleton
instances make `$a === Wide::spacerTail()` work on both PHP versions.

## Scope

This applies to value enums in every module. A native enum used *only* as a bag
of integer constants (never instantiated, never `from()`-ed) would in fact
downgrade — but rather than police that line case by case, we standardise on the
hand-rolled class for anything enum-shaped.

This is a constraint on enums specifically. The other modern features do
downgrade and stay encouraged: `readonly`, constructor promotion, `match`, arrow
functions, typed properties, null-coalescing assignment.

## Consequences

- More boilerplate than `enum` — named static factories, a private constructor,
  and a small instance cache so `===` compares identity.
- The `enum` keyword does not appear in shipped modules. A reviewer expecting one
  should find the hand-rolled pattern and know why.
- The release pipeline's PHP 7.4 leg ([ADR-0009](./0009-downgrade-on-release-with-rector.md))
  is what would catch a regression here, since `php -l` alone would not.
