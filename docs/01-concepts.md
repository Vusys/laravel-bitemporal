# Concepts

This package models facts that change over time, and that you sometimes need to correct *after the fact* without losing what you previously believed. That requires two independent time axes.

## Two time axes

**Valid time** — *when a fact is true in the real world.* A product's price of £12.00 is valid from 1 February. This is the business-effective date; it has nothing to do with when you typed it into the database.

**Recorded time** — *when your system believed a fact.* You might not record that £12.00 price until 5 February, and you might later discover it was wrong and record a correction on the 10th. Recorded time is the audit axis: it lets you ask "what did we think was true, as of the moment we ran that report?"

A model that tracks only valid time is **effective-dated**. A model that tracks both axes is **bitemporal**. This package supports both — a model is effective-dated by default and becomes bitemporal once it carries the recorded-time columns (you can opt a model out of recorded time with `protected bool $tracksRecordedTime = false;`).

The four questions this unlocks:

- What was true on this business date? → `validAt($date)`
- What did we believe was true on that date, *as of some later audit moment*? → `validAt($date)->knownAt($auditMoment)`
- What do we believe now? → `currentKnowledge()`
- What changed between two points of knowledge? → diff the two with `diffKnowledge()` / `diffTimelines()`.

## Spells

A *spell* is this package's name for a half-open time interval `[from, to)` — the lower bound is included, the upper bound is excluded. A `null` upper bound means "open-ended" (valid until further notice); a `null` lower bound means "since the beginning of time".

Half-open intervals are deliberate: they tile without gaps or overlaps. A price valid `[Feb 1, Mar 1)` and one valid `[Mar 1, Apr 1)` meet exactly at midnight on 1 March with no ambiguity about which one owns that instant — the second does.

Every temporal row therefore has a **valid spell** (`valid_from`, `valid_to`) and, when bitemporal, a **recorded spell** (`recorded_from`, `recorded_to`). The value object that represents a spell in PHP is [`Spell`](../src/Spell.php); a sequence of non-overlapping segments is a [`Timeline`](../src/Timeline.php).

## Corrections never destroy history

The central guarantee: a correction does not overwrite a row. Instead, the writer **closes** the recorded spell of the old row (sets its `recorded_to` to "now") and **inserts** a new row carrying the corrected value with an open recorded spell. The old row is still there, still queryable with `knownAt()`. You can reconstruct exactly what the system believed at any past instant.

## Anti-rows (retractions)

Sometimes the correct statement is "this fact was *never* true for this window" — not "it changed to a new value". That is a **retraction**, and it is recorded as an *anti-row*: a row with `is_retraction = true` that punches a hole in the timeline for a valid window. Retractions participate in knowledge history exactly like ordinary rows, so a retraction can itself be corrected later.

## Why not just query scopes?

You could bolt `validAt()` onto a normal model with a scope. The hard part is *writing* safely: splitting an existing row when a correction only covers part of its window, closing the right recorded spells in the right order, preventing two overlapping facts from ever both being current, and doing it all atomically under concurrency. That write engine — not the read scopes — is the reason this package exists.

Next: [Installation](02-installation.md).
