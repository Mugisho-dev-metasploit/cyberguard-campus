# Git workflow

## Observed practice

| Item | Value |
|---|---|
| Branch | `main` only; no other branch in the repository |
| Remote | `origin` on GitHub |
| Tags | None |
| Commits | 24 at the analysed point |
| Message style | Conventional-commit prefixes: `feat(scope):`, `security(scope):`, `test(scope):`, `chore:` |
| Ticket references | Ticket identifiers appear in messages (`APP-07.3.2`, `APP-07.4`) |

Example messages from the history:

```text
feat(api): add incidents read endpoint
security(auth): harden login CSRF, password rehash and HTTP surface
test(security): validate authorization and incident input controls
```

## Conventions worth keeping

- One subject per commit: a security change, its tests and its documentation belong together,
  but two unrelated fixes do not.
- Mention the ticket when one exists; the security history reads well because of it.
- Never commit `.env` (it is ignored) and never paste a credential into a tracked file — the
  working copy of `README.md` currently does, and it must be cleaned before committing.

## Before committing

```bash
git status --short
git diff --check
/opt/lampp/bin/php backend/tests/run.php
```

## Not in place

No pull-request template, no CODEOWNERS, no branch protection, no CI check, no release process
or tag (`PLANNED`).
