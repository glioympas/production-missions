# Production Missions

A personal training ground to explore things & learn.

Each "mission" is a small, self-contained project — finishable in a day or two — that teaches one specific "production" concept properly.

## How each mission works

Every mission is its own folder with:

- **A business or technical case** — a concrete problem, not a made-up one.
- **A working implementation** in PHP / Laravel — self-contained, runnable, if code needed.
- **A README** that explains the concept the way I'd explain it to a teammate/

The README is the actual point. Code you can copy. Understanding the trade-off is the thing you keep.

## The missions

Roughly ordered: correctness → resilience → data → distributed systems → observability → security → architecture. Later ones lean on earlier ones. But it's a suggestion, not a rule — if something grabs you, do it.

### Part I — API Design & Correctness
1. **Idempotency keys** — safe retries for writes (no double charges)
2. **Cursor pagination** — why OFFSET dies at scale, and the fix

## Status

This is a work in progress — I'm going through them one at a time, not all at once. Each folder gets filled in as I get to it. The point isn't to speedrun the list; it's to actually understand each one well enough.

## A note on the stack

Everything's in PHP / Laravel because that's what I work in, and the concepts are what matter — an idempotency key or a circuit breaker is the same idea in any language. If you're reading this in a different stack, the READMEs should still make sense; just mentally swap the syntax.
