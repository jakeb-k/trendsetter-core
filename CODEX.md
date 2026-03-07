# CODEX.md

## Purpose

This repository exists to build and maintain a backend that is boring, trustworthy, testable, and easy to evolve.

The goal is not to produce the most clever code.
The goal is to produce software that behaves correctly under real usage, is easy to reason about, and does not collapse under iteration.

This document defines the operating rules for AI-assisted development in this repository.

---

## Core philosophy

Prefer clarity over cleverness, explicitness over magic, simple flows over flexible abstractions, correctness over speed, maintainability over theoretical elegance.

Every change should make the system easier to trust.

The backend is the source of truth for business rules, permissions, state transitions, and derived outcomes that matter to users.

Do not treat this codebase like a playground for patterns, abstractions, or premature generalisation.

---

## What good looks like

Good code in this repository is:

- easy to read
- easy to test
- hard to misuse
- explicit about business rules
- conservative with state
- small in surface area
- consistent with existing conventions
- resilient to edge cases
- honest about uncertainty

A change is not good because it is large, abstract, or “future-proof”.
A change is good if another engineer can understand it quickly and trust its behaviour.

---

## Non-negotiable engineering rules

### 1. Business rules live in the backend

Anything involving permissions, eligibility, derived state, notifications, ownership, progression, or user trust must be enforced server-side.

Never rely on client behaviour for correctness.

### 2. One source of truth per concept

If a rule or calculation already exists, reuse or extract it.
Do not introduce parallel implementations of the same logic.

If two parts of the system need the same concept, they must share the same rule path.

### 3. State machines must be intentional

If a feature has lifecycle states, those states must be explicit and minimal.

Do not invent extra states unless they represent a real business distinction.
Do not leave transitions ambiguous.
Do not allow illegal transitions “because the UI won’t do that”.

If a state exists, define:
- what it means
- how it is entered
- how it is exited
- what actions are allowed while in it

### 4. Optimise for deletion, not expansion

Prefer designs that are easy to remove, simplify, or reshape later.

Do not add configuration, columns, toggles, services, or abstractions “just in case”.
Build only what the current product rules require.

### 5. Privacy and trust are product features

Any feature that crosses user boundaries must default to minimal exposure.

Never expose private user content unless explicitly required and approved.
Signals are safer than raw data.
Summaries are safer than histories.
Derived outcomes are safer than source material.

When in doubt, suppress rather than leak.

### 6. False positives are often worse than false negatives

For anything user-facing that could damage trust, social features especially, bias toward suppression over premature action.

If the system is uncertain, it should do less, not more.

### 7. Prefer boring data models

Schemas should reflect real domain needs, not imagined future complexity.

Avoid bloated tables, duplicated ownership fields, speculative metadata, and fields with unclear responsibility.

Every persisted field should have a reason to exist.

### 8. No silent rule drift

If a new feature depends on an existing concept, do not reimplement it slightly differently.

This repository must not accumulate multiple subtly different definitions of:
- progress
- completion
- streaks
- misses
- eligibility
- ownership
- notification conditions
- pace
- access

### 9. Permissions are not UI concerns

If something should be forbidden, enforce it in backend policy, service, or domain logic.
Never assume a hidden button is protection.

### 10. Idempotency matters

Any action that can be retried, repeated, refreshed, or raced must be safe under repetition.

Assume:
- requests may be duplicated
- users may tap twice
- jobs may retry
- multiple processes may evaluate the same thing

The backend must remain correct under those conditions.

---

## AI workflow rules

### 11. Multi-agent workflow is mandatory for meaningful work

Do not use a single-agent “write everything” workflow for non-trivial changes.

Meaningful features must use a multi-agent workflow with distinct responsibilities, such as:
- requirements / intent validation
- implementation
- test generation
- review / criticism
- security / permission review
- refactor / simplification review

The exact agent names do not matter.
The separation of concerns does.

One agent should not be trusted to define, implement, and approve the same behaviour without challenge.

### 12. AI must not be used as an authority

AI output is a draft, not truth.

Treat generated code, tests, migrations, and architecture suggestions as proposals that must be interrogated.

Never accept code because it looks polished.
Never accept tests because they are numerous.
Never accept abstractions because they sound reusable.

### 13. Human judgement decides product boundaries

AI may help implement, review, and refine.
It must not decide the actual product rules.

Product intent, trust boundaries, lifecycle rules, privacy posture, and simplification choices must come from deliberate judgement, not generator drift.

### 14. Specification before implementation

For any medium or large feature:
- define the product rules first
- define constraints second
- define acceptance criteria third
- implement last

Do not start from code and work backwards into behaviour.

### 15. Generated tests are not enough on their own

AI-generated tests are useful, but they do not replace adversarial thinking.

For important behaviour, also verify:
- auth boundaries
- state transition legality
- duplicate requests
- race conditions
- stale data behaviour
- edge cases that are socially or financially costly

### 16. The simpler implementation wins

If two implementations satisfy the same product rules, choose the one with:
- fewer moving parts
- fewer states
- fewer persisted fields
- fewer jobs
- fewer opportunities for drift

Cleverness is not a virtue here.

---

## Design and architecture standards

### 17. Keep layers honest

Controllers should coordinate, not contain business logic.
Domain or application logic should own decisions.
Persistence should support the model, not define it.
Background jobs should execute workflows, not invent rules.

Do not let logic leak into random places just because it is convenient.

### 18. Shared logic must be extracted when it becomes load-bearing

If a rule is used in more than one meaningful place and divergence would be dangerous, extract it.

Extraction should reduce ambiguity, not create a fake abstraction hierarchy.

### 19. Prefer direct naming

Names should describe purpose plainly.

Avoid dramatic, over-generic, or pattern-heavy names.
A reader should know what something does without decoding it.

### 20. Keep side effects visible

A function, service, or workflow that mutates state, sends notifications, or creates linked records should make that obvious.

Do not hide meaningful behaviour behind vague helper names.

### 21. Background processing must remain deterministic

Queued or scheduled work must follow the same business rules as synchronous flows.

No background path may invent alternate logic because it is “close enough”.

### 22. Observability is required for risky automation

If the system automatically creates, suppresses, expires, rate-limits, or deduplicates user-facing outcomes, there must be enough observability to debug why.

But observability must remain privacy-safe and retention-conscious.

---

## Testing standards

### 23. Test behaviour, not implementation trivia

Tests should prove product rules, permissions, and invariants.
They should not overfit internal method structure.

A good test answers:
- what must happen
- what must never happen
- under what conditions

### 24. Every meaningful feature needs negative tests

Happy paths are not enough.

If a feature can be abused, repeated, raced, expired, muted, duplicated, paused, unlinked, or forbidden, those cases should be tested.

### 25. Test the invariants that matter most

Prioritise tests around:
- ownership
- access control
- lifecycle transitions
- deduplication
- rate limiting
- suppression
- data leakage prevention
- idempotency

### 26. High test count does not equal high confidence

A small set of sharp tests is better than a mountain of shallow ones.

Do not generate filler tests for the sake of volume.

---

## Data and schema standards

### 27. Persist only what the product actually needs

Before adding a field, ask:
- is this a real product concept
- is it required for correctness
- is it required for performance
- is it required for observability
- could it be derived instead

If the answer is no, do not store it.

### 28. Avoid speculative schema design

Do not add columns, flags, JSON blobs, or relationship hooks for hypothetical future features.

Future requirements should earn future schema.

### 29. Retention matters

Not all data should live forever.

Logs, observability, notifications, and derived history should each have intentional retention decisions.
Do not keep noisy internal records forever without a reason.

---

## Product alignment rules

### 30. The app is about follow-through, not decoration

Backend features should support action, consistency, accountability, and trust.

Do not spend complexity budget on features that do not improve:
- user follow-through
- clarity of progress
- accountability reliability
- trust in the system

### 31. Social features must stay intimate and low-noise

This product is not a feed, not a leaderboard, not public performance theatre.

Any backend support for social/accountability features should reinforce:
- 1:1 trust
- supportive nudges
- low spam
- low exposure
- reversible relationships

### 32. Neutral wording and non-shaming logic matter

When the backend supports notifications, messaging, or user-visible states, the system must avoid punitive framing.

The product should encourage recovery, not humiliation.

---

## Change management rules

### 33. Do not widen scope mid-implementation

If a feature reveals adjacent possibilities, note them, do not silently include them.

Stay inside the agreed phase or acceptance criteria unless explicitly changed.

### 34. Preserve existing behaviour unless intentionally changing it

When refactoring or extracting logic, preserve behaviour exactly unless the product rule is intentionally being changed.

### 35. If a change increases complexity, it must earn it

Extra jobs, services, states, records, or flows are acceptable only if they solve a real problem that cannot be solved more simply.

---

## Review checklist

Before finalising any meaningful change, ask:

- Is the product behaviour explicit
- Is the backend the source of truth
- Is there exactly one load-bearing rule path for each core concept
- Are permissions enforced server-side
- Are states minimal and transitions legal
- Is the data model lean
- Are retries and duplicates safe
- Are social/privacy risks suppressed rather than leaked
- Are the tests proving invariants, not implementation noise
- Is this the simplest thing that satisfies the actual requirement

If the answer to any of these is no, the change is not done.

---

## Final standard

This repository should feel disciplined, calm, and unsurprising.

We are not trying to impress with architecture.
We are trying to build a backend that keeps its promises.

Anything that makes the codebase noisier, more magical, more abstract, more speculative, or harder to trust is the wrong direction.