<?php

namespace App\Support;

/**
 * Named queues. Heavy/bulk work runs on its own queues + Supervisor worker pools
 * so latency-sensitive, user-is-waiting jobs are never starved behind a long
 * crawl or data sync. See /etc/supervisor/conf.d/ebq.conf for the worker pools.
 *
 *  - INTERACTIVE: user is actively waiting (guest tools, "check rank now", live audits)
 *  - DEFAULT:     emails, notifications, misc
 *  - SYNC:        scheduled/background bulk data syncs (GA/GSC/keywords)
 *  - CRAWL:       the site-crawl page-fetch pipeline (high volume, ≤300s/job) —
 *                 runs on the AUTOSCALED ephemeral worker boxes
 *  - CRAWL_FINALIZE: the long post-crawl analysis (AnalyzeSiteJob, ≤1200s) —
 *                 runs ONLY on the pinned permanent box so a scale-down drain can
 *                 never interrupt a finalize. See infra/crawler/autoscaling.md.
 *  - FLEET:       node provisioning / bootstrap / tenant moves (admin-triggered).
 *                 Runs ONLY on the pinned WEB box as ROOT (ebq-queue-fleet in
 *                 ebq.conf) because it SSHes/rsyncs to new boxes using root's key.
 *                 Long (≤1800s) — kept off the request thread so the admin UI
 *                 never hits the FPM 120s timeout.
 */
final class Queues
{
    public const INTERACTIVE = 'interactive';

    public const DEFAULT = 'default';

    public const SYNC = 'sync';

    public const CRAWL = 'crawl';

    /** Tier-1.5 link crawler — dedicated queue so it neither starves nor is
     *  starved by the latency-sensitive site-audit `crawl` queue; served by
     *  the same crawl workers (added to $crawlPool, after `crawl`). */
    public const LINK_CRAWL = 'link-crawl';

    public const CRAWL_FINALIZE = 'crawl-finalize';

    /** Content Autopilot production line (brief→write→score→revise, images,
     *  publish). Long LLM jobs (≤1800s) — served by the heavy pool
     *  (redis-long, retry_after 3900) so a revise loop is never re-reserved
     *  mid-run and never starves user-facing queues. */
    public const CONTENT = 'content';

    public const FLEET = 'fleet';
}
