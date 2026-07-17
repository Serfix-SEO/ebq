<?php

use Illuminate\Support\Str;

// Supervisor option sets, reused across environments. IMPORTANT: Horizon merges
// `defaults` into EVERY environment's plan (array_replace_recursive), so a supervisor
// placed in `defaults` runs on EVERY box. To scope supervisors per box we keep
// `defaults` EMPTY and list each supervisor only inside the environment(s) that
// should run it (see `environments` below). The `fleet` queue is intentionally never
// here — it runs as a root queue:work, not under Horizon.
$webPool = [
    'connection' => 'redis',
    'queue' => ['interactive', 'default'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'maxProcesses' => 3,
    'balanceMaxShift' => 1,
    'balanceCooldown' => 3,
    'maxTime' => 3600,
    'maxJobs' => 0,
    'memory' => 256,
    'tries' => 3,
    'timeout' => 300,
    'nice' => 0,
];
$crawlPool = [
    'connection' => 'redis',
    // `crawl` first = site audits (user-facing) win on ties; `link-crawl`
    // (Tier-1.5 background) shares the same I/O-bound workers and Horizon's
    // auto-balance shifts capacity to whichever queue has backlog — so link
    // crawl saturates spare capacity without starving audits.
    'queue' => ['crawl', 'link-crawl'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'size',
    // Crawling is I/O-bound (workers spend ~all their page-cycle WAITING on network/DB,
    // not on CPU — boxes idle at load ~0.5/2 vCPU). So run many more workers than cores:
    // 16/box fills that wait time with concurrent fetches. Tune via CRAWLER_MAX_PROCESSES.
    'maxProcesses' => (int) env('CRAWLER_MAX_PROCESSES', 16),
    'balanceMaxShift' => 4,
    'balanceCooldown' => 3,
    'maxTime' => 3600,
    'maxJobs' => 0,
    'memory' => 2048,
    'tries' => 3,
    'timeout' => 300,
    'nice' => 0,
];
$heavyPool = [
    // redis-long: retry_after 3900 > the 3600 finalize timeout below, so a long
    // AnalyzeSiteJob is never re-reserved mid-run (see config/queue.php).
    'connection' => 'redis-long',
    'queue' => ['sync', 'crawl-finalize'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'size',
    'maxProcesses' => 4,
    'balanceMaxShift' => 1,
    'balanceCooldown' => 5,
    'maxTime' => 4200,   // > timeout so a worker never recycles mid-finalize
    'maxJobs' => 0,
    'memory' => 1536,    // finalize ini_set ceiling is 1024M; headroom so no post-job restart churn
    'tries' => 3,
    'timeout' => 3600,   // a ~168k-page / ~1.5M-edge finalize runs many minutes
    'nice' => 0,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'horizon',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:interactive' => 30,
        'redis:default' => 60,
        'redis:crawl' => 300,
        // sync + crawl-finalize run on the redis-long connection ($heavyPool).
        'redis-long:sync' => 600,
        'redis-long:crawl-finalize' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    // EMPTY on purpose — see the note by $webPool above. Supervisors are defined
    // per-environment so each box runs ONLY its own pools.
    'defaults' => [],

    // Which supervisors run on which box, keyed by that box's APP_ENV. A supervisor
    // runs ONLY in the environment(s) that list it. Pools are split by timeout/connection so
    // they never share: crawl (redis, ≤300s) vs crawl-finalize/sync (redis-long, ≤3600s;
    // retry_after=3900 stays above).
    'environments' => [
        // Web box (APP_ENV=local): user-facing + notification queues ONLY.
        'local' => [
            'web' => $webPool,
        ],
        // If the web box is ever flipped to APP_ENV=production, same role, more procs.
        'production' => [
            'web' => array_replace($webPool, ['maxProcesses' => 6]),
        ],
        // Pinned worker box B (10.0.0.3) — APP_ENV=worker: crawl + sync + finalize.
        'worker' => [
            'worker-crawl' => $crawlPool,
            'worker-heavy' => $heavyPool,
        ],
        // Autoscaled ephemeral boxes — APP_ENV=worker-ephemeral: crawl ONLY (never
        // finalize/sync, so a scale-down drain can't interrupt a long job).
        'worker-ephemeral' => [
            'worker-crawl' => $crawlPool,
        ],
        // Staging box (10.0.0.4, APP_ENV=staging): the WHOLE pipeline on one
        // small box so QA covers every queue — web + crawl + heavy, tiny pools.
        // Its own Redis/DB; never shares prod queues.
        'staging' => [
            'web' => array_replace($webPool, ['maxProcesses' => 2]),
            'worker-crawl' => array_replace($crawlPool, ['maxProcesses' => 2]),
            'worker-heavy' => array_replace($heavyPool, ['maxProcesses' => 1]),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
