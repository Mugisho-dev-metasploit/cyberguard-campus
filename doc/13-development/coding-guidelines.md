# Coding guidelines

Conventions observed throughout the existing code. Follow them rather than introducing new
styles.

## PHP

- `declare(strict_types=1);` at the top of every file; PSR-4 under `CyberGuard\Campus\`.
- Classes are `final`; dependencies are `private readonly` constructor-promoted properties.
- Named arguments at call sites where it clarifies (`authenticationService: …`).
- No framework, no container, no facade: wiring is explicit in `backend/public/index.php`.
- Layer rules: controllers do HTTP, services do rules, repositories do SQL. No crossing.
- Every SQL statement is prepared; dynamic column names come from an allow-list only.
- Sensitive parameters (`$password`, `$identifier`, hashes) carry `#[\SensitiveParameter]`.
- Errors that reach a client are generic; internal detail stays in the exception.
- Comments explain **why**, not what: the existing files show the expected density.

## JavaScript (frontend)

- ES modules, no bundler, no dependency.
- Build DOM with the helpers in `format.js`; never assemble HTML strings.
- All HTTP goes through `api.js`; never call `fetch` from a page module.
- No `localStorage`, `sessionStorage` or cookie writing.
- Keep pure derivations separate from rendering (`monitoring-data.js` is the model).

## SQL and migrations

- One `CREATE TABLE` per migration file, numbered in order.
- Explicit engine, charset and collation; declare indexes and foreign keys in the same file.
- Enums and check constraints carry the invariants that matter.

## Tests

- One suite per subject, named `<Subject>Test.php`, runnable on its own.
- Use `check(name, condition, detail)`; make the name state the expected behaviour.
- Create data with the `tk_*` helpers so cleanup and the before/after comparison work.
- Never write a fixed password; generate one per run.
- A security test should fail when the control is removed — verify that before trusting it.

## Naming

- Endpoints: plural nouns (`/api/incidents`), with the id as a path segment.
- JSON fields mirror the database columns (`snake_case`).
- Audit actions: `auth.<area>.<event>`.
