# EBQ engineering docs — `infra/`

👉 **The entry point is [main.md](./main.md).** Start there. It's the knowledge spine: it maps
every subsystem, links every doc, states the invariants that must never break, and defines the
discipline for keeping all of it current.

This `README.md` is just a directory landing — the authoritative index lives in `main.md`.

## Areas

- [main.md](./main.md) — **knowledge entry point** (read first)
- [crawler/](./crawler/README.md) — the shared single-crawl store (biggest subsystem)
- [data-sources/](./data-sources/README.md) — Google (GSC/GA) + Microsoft
- [keywords/](./keywords/README.md) — keyword finder, research, rank tracking
- [competitive/](./competitive/README.md) — backlinks, competitive, SERP
- [reports/](./reports/README.md) — report insights, action queue, growth reports
- [ai/](./ai/README.md) — AI tools, writer, LLM (Mistral)
- [audits/](./audits/README.md) — page audit, Lighthouse, live score, topical authority
- [wordpress-plugin/](./wordpress-plugin/README.md) — plugin connect/embed + HQ API
- [guest-tools/](./guest-tools/README.md) — public lead-gen tools
- [billing/](./billing/README.md) — Stripe, plans, feature gating, usage
- [accounts/](./accounts/README.md) — auth, onboarding, teams
- [admin/](./admin/README.md) — the admin panel
- [deployment-and-queues.md](./deployment-and-queues.md) — topology, queues, deploy procedure
- [server-deployment.md](./server-deployment.md) — live inventory of both production boxes

> Safety: production server, **no DB backups** (binary logging off — data loss is permanent).
> Never run destructive DB commands without per-command confirmation; tests must run on sqlite
> `:memory:`. See `CLAUDE.md` at the repo root and [main.md](./main.md) invariants.
