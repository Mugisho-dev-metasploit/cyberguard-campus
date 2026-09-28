# Value proposition

## What the product actually offers today

| Promise | Backed by |
|---|---|
| One place to read events, alerts, devices and incidents | The API and the six operational pages |
| An incident lifecycle that cannot be short-circuited | Server-side state machine, one step at a time, closing reserved to administrators |
| A defensible trail of who did what | `incident_history` for cases, `audit_logs` for authentication |
| A sign-in path hardened against the usual attacks | Throttling, timing equalisation, CSRF protection, input bounds, session integrity — each with its own test suite |
| No black box | No framework, no external service, readable code, tests that state the expected behaviour |

## What it does not promise

- It does not detect anything: detection happens upstream.
- It does not collect anything by itself yet.
- It does not isolate several customers.

## Differentiators worth claiming (and testable)

1. **Security work that is evidenced.** Each control was audited, fixed and covered by a test
   that fails on the vulnerable code — that is unusual for a project of this size and is visible
   in the history.
2. **No dependency sprawl.** One Composer package, zero frontend dependencies: a small attack
   surface and a long shelf life.
3. **Readable by a new engineer.** Explicit wiring, no magic, documentation mirroring the code.

## Claims to avoid

Anything about scale, detection quality, compliance or automation: none of it is supported by
the repository.
