# Contributing

There is no `CONTRIBUTING.md` in the repository; this page states what the history and the code
imply.

## Expected flow

1. Understand the area first — the documentation in `/doc` mirrors the code, and
   `backend/tests/README.md` describes the suites.
2. Make the smallest change that solves the problem; the codebase avoids refactoring that is
   not required by the change.
3. Add or extend a test that fails without your change.
4. Run the full suite; it must stay green and leave the database untouched.
5. Update the documentation touched by the change (endpoint, schema, security control).
6. Commit with a conventional-commit message, referencing the ticket if there is one.

## What a security change needs

| Requirement | Why |
|---|---|
| A test that fails on the vulnerable code | Proves the test detects the flaw, not just the fix |
| Evidence in the commit or the pull request | The history of this project records measurements, not claims |
| No behaviour change outside the finding | Keeps the change reviewable |
| No secret in the diff | Verified by reading the diff before committing |

## Review checklist

- Layer boundaries respected (controller / service / repository).
- Prepared statements only; no dynamic column name outside an allow-list.
- Errors generic to the client, detailed in the exception.
- `#[\SensitiveParameter]` on any new parameter carrying a secret or an identifier.
- Documentation updated: `/doc`, and `backend/tests/README.md` for a new suite.
