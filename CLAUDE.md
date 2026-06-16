# ⛔ READ FIRST — DATABASE SAFETY (production server, no backups)

This repo is deployed on a **production server** whose default DB connection is
**live MySQL `ebq`**. Binary logging is OFF and there are no DB backups, so any
data loss is **permanent**. On 2026-06-07 a `php artisan test` run wiped the
production database — `RefreshDatabase` ran `migrate:fresh` on MySQL because a
**cached config** (`bootstrap/cache/config.php`, written by `php artisan optimize`)
overrode the `phpunit.xml` sqlite setting. Never again.

**Hard rules — follow before running ANY command or editing test/DB code:**

1. **NEVER run `php artisan test` / `pest` / `phpunit` without first confirming
   the test DB is safe.** Run `php artisan config:clear`, then verify
   `config('database.default')` resolves to **sqlite `:memory:`** (NOT mysql).
   A cached config silently overrides `phpunit.xml` — assume it may be cached.
   - A code guard now enforces this: `tests/TestCase::setUpTraits()` aborts the
     suite if the resolved connection isn't sqlite `:memory:` or a `*test*` DB.
     **Do not remove or weaken that guard.**
2. **NEVER run destructive DB commands** on this server without explicit,
   per-command user confirmation: `migrate:fresh`, `migrate:refresh`,
   `migrate:rollback`, `db:wipe`, `ebq:demo-data seed` ("wipe + regenerate"),
   `ebq:demo-data clear --force` ("remove everything"), or raw `DROP`/`TRUNCATE`.
3. **Safe DB writes** are `updateOrCreate`-based seeders (e.g. `PlanSeeder`) and
   plain `migrate --force` (additive). Prefer those.
4. When in doubt about whether a command can lose data, **stop and ask first.**

## 📚 Knowledge base — read & maintain `infra/main.md`

**`infra/main.md` is the engineering knowledge entry point for this whole application.**
Before exploring code or starting any non-trivial task, **read `infra/main.md`** and the
linked subsystem doc(s) under `infra/<area>/` — it maps every subsystem, the invariants, and
the deployment.

**You must keep it current.** This is a hard rule, not a nicety:
- When you **change code / schema / config / architecture**, update the affected
  `infra/<area>/…` doc *in the same change*.
- When you **learn something non-obvious** (a gotcha, a runtime fact, a *why*, an incident),
  write it into the right doc.
- When you **add a subsystem**, create its `infra/<area>/` docs and add it to the System Map
  in `infra/main.md`, plus a Knowledge Changelog line.
- Keep docs **code-grounded** (cite `file:line`), concise, deduplicated. A stale doc is worse
  than no doc. If a task leaves the docs wrong or incomplete, the task is not done.

See the full protocol at the top of `infra/main.md`.

<!-- code-review-graph MCP tools -->
## MCP Tools: code-review-graph

**IMPORTANT: This project has a knowledge graph. ALWAYS use the
code-review-graph MCP tools BEFORE using Grep/Glob/Read to explore
the codebase.** The graph is faster, cheaper (fewer tokens), and gives
you structural context (callers, dependents, test coverage) that file
scanning cannot.

### When to use graph tools FIRST

- **Exploring code**: `semantic_search_nodes` or `query_graph` instead of Grep
- **Understanding impact**: `get_impact_radius` instead of manually tracing imports
- **Code review**: `detect_changes` + `get_review_context` instead of reading entire files
- **Finding relationships**: `query_graph` with callers_of/callees_of/imports_of/tests_for
- **Architecture questions**: `get_architecture_overview` + `list_communities`

Fall back to Grep/Glob/Read **only** when the graph doesn't cover what you need.

### Key Tools

| Tool | Use when |
|------|----------|
| `detect_changes` | Reviewing code changes — gives risk-scored analysis |
| `get_review_context` | Need source snippets for review — token-efficient |
| `get_impact_radius` | Understanding blast radius of a change |
| `get_affected_flows` | Finding which execution paths are impacted |
| `query_graph` | Tracing callers, callees, imports, tests, dependencies |
| `semantic_search_nodes` | Finding functions/classes by name or keyword |
| `get_architecture_overview` | Understanding high-level codebase structure |
| `refactor_tool` | Planning renames, finding dead code |

### Workflow

1. The graph auto-updates on file changes (via hooks).
2. Use `detect_changes` for code review.
3. Use `get_affected_flows` to understand impact.
4. Use `query_graph` pattern="tests_for" to check coverage.
