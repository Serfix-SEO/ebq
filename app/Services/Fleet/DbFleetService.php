<?php

namespace App\Services\Fleet;

use App\Models\DbNode;
use App\Support\DbFleetConfig;
use App\Support\ShardManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Orchestration over the database-node fleet — the DbNode equivalent of
 * {@see WorkerFleetService}. Provisions/bootstraps/destroys MariaDB boxes via
 * {@see HetznerClient}, runs the schema on them from the web box, and supports
 * registering an already-existing node (the pinned primary, or a local box used
 * to validate moves). Stateless; resolve per call.
 *
 * DB nodes are PURE MariaDB (no app code): bootstrap configures the server +
 * opens the firewall, then the web box runs `migrate` over the network against
 * the node's registered connection. Holds no secrets — nodes use the shared app
 * DB credential the web box already knows.
 */
class DbFleetService
{
    public function __construct(private HetznerClient $hetzner) {}

    /**
     * Reject node addresses that only resolve on ONE box. `db_nodes.private_ip`
     * becomes a live DB connection host on EVERY box that runs jobs (see
     * ShardManager), so loopback/link-local addresses silently kill all
     * anchored jobs on the worker fleet while working fine on the box that
     * registered them — incident 2026-07-06: the primary was registered as
     * 127.0.0.1 and every anchored crawl job died on the worker box with
     * "Connection refused" for three days.
     */
    private function assertReachableFromAllBoxes(string $privateIp): void
    {
        $ip = trim($privateIp);
        if ($ip === '' || in_array(strtolower($ip), ['localhost', 'localhost.localdomain'], true)) {
            throw new \InvalidArgumentException(
                "db_node address '{$privateIp}' is not valid — use an address reachable from every box (e.g. the 10.0.0.x private-net IP)."
            );
        }
        $packed = @inet_pton($ip);
        if ($packed !== false) {
            $isLoopback = str_starts_with($ip, '127.') || $ip === '::1';
            $isLinkLocal = str_starts_with($ip, '169.254.') || stripos($ip, 'fe80:') === 0;
            if ($isLoopback || $isLinkLocal) {
                throw new \InvalidArgumentException(
                    "db_node address '{$privateIp}' is loopback/link-local — it would only resolve on the registering box. Use the private-net IP (e.g. 10.0.0.2)."
                );
            }
        }
    }

    /** Record the existing primary (Box A) as the pinned node. Idempotent. */
    public function registerPrimary(string $privateIp, string $dbName): DbNode
    {
        $this->assertReachableFromAllBoxes($privateIp);

        return DbNode::updateOrCreate(
            ['is_pinned' => true],
            [
                'name' => 'ebq-db-primary',
                'role' => DbNode::ROLE_PRIMARY,
                'status' => DbNode::STATUS_ACTIVE,
                'private_ip' => $privateIp,
                'db_name' => $dbName,
                'is_healthy' => true,
                'last_seen_at' => now(),
                'provisioned_at' => now(),
            ],
        );
    }

    /** Register an already-running MariaDB box as a node (no Hetzner provision). */
    public function registerExisting(string $name, string $role, string $privateIp, string $dbName, ?int $port = null): DbNode
    {
        $this->assertReachableFromAllBoxes($privateIp);

        $node = DbNode::create([
            'name' => $name,
            'role' => $role,
            'status' => DbNode::STATUS_ACTIVE,
            'private_ip' => $privateIp,
            'db_name' => $dbName,
            'is_healthy' => true,
            'last_seen_at' => now(),
            'provisioned_at' => now(),
            'labels' => $port !== null ? ['port' => $port] : null,
        ]);
        ShardManager::flush();

        return $node;
    }

    /** Create a Hetzner MariaDB server + tracking row (status=provisioning). */
    public function provision(string $role = DbNode::ROLE_TENANT): DbNode
    {
        $node = DbNode::create([
            'name' => 'ebq-db-pending',
            'role' => $role,
            'status' => DbNode::STATUS_PROVISIONING,
            'server_type' => DbFleetConfig::serverType(),
            'db_name' => (string) config('database.connections.global.database', 'ebq'),
            'provisioned_at' => now(),
        ]);
        $node->update(['name' => "ebq-db-{$role}-{$node->id}"]);

        $image = DbFleetConfig::snapshotId();
        if (! $image) {
            $node->update([
                'status' => DbNode::STATUS_FAILED,
                'last_error' => 'No DB snapshot configured. Build a MariaDB snapshot, then set HCLOUD_DB_IMAGE (or snapshot_id at /admin/db-fleet).',
            ]);

            return $node->refresh();
        }

        $result = $this->hetzner->createServer($node->name, [
            'server_type' => DbFleetConfig::serverType(),
            'image' => $image,
            'labels' => ['role' => 'ebq-db-node', 'db-role' => $role],
        ]);
        if (! $result['ok']) {
            $node->update(['status' => DbNode::STATUS_FAILED, 'last_error' => $result['error']]);
            Log::error('DbFleet: provision failed', ['node' => $node->id, 'error' => $result['error']]);

            return $node->refresh();
        }

        $node->update([
            'hetzner_server_id' => $result['server_id'],
            'private_ip' => $result['private_ip'],
            'public_ip' => $result['public_ip'],
        ]);
        ShardManager::flush();
        Log::info('DbFleet: provisioned', ['node' => $node->id, 'server' => $result['server_id']]);

        return $node->refresh();
    }

    /**
     * Configure MariaDB on the box (app db + user + grants + firewall), then run
     * the schema from the web box against the node connection. Idempotent.
     */
    public function bootstrap(DbNode $node): bool
    {
        if (! $node->private_ip || ! $node->db_name) {
            return false;
        }
        $ip = $node->private_ip;
        // Ephemeral cloud boxes recycle private IPs, so a previously-seen IP can
        // come back with a different host key. Pin nothing (UserKnownHostsFile=
        // /dev/null) so a recycled IP never trips "REMOTE HOST IDENTIFICATION
        // HAS CHANGED" and blocks bootstrap.
        $ssh = 'ssh -i /root/.ssh/id_ed25519_worker -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10';
        if (! $this->waitForSsh($ip, $ssh)) {
            $node->update(['last_error' => 'box not SSH-reachable within timeout']);

            return false;
        }

        // Make MariaDB remote-reachable + binlog-enabled here (not just baked into
        // the snapshot) so bootstrap is self-sufficient on any MariaDB box and
        // survives a snapshot that ships the distro default (bind 127.0.0.1).
        // server_id = private-IP last octet → unique per node (needed for binlog/
        // replication). Config pushed via stdin to avoid nested shell quoting.
        $serverId = max(1, (int) substr((string) strrchr($ip, '.'), 1));
        $cnf = "[mysqld]\n"
            ."bind-address            = 0.0.0.0\n"
            ."server_id               = {$serverId}\n"
            ."log_bin                 = /var/log/mysql/mysql-bin\n"
            ."log_bin_index           = /var/log/mysql/mysql-bin.index\n"
            ."binlog_format           = ROW\n"
            ."expire_logs_days        = 7\n"
            ."innodb_buffer_pool_size = 512M\n"
            ."max_connections         = 200\n";
        $cfgOk = $this->runWithInput("{$ssh} root@{$ip} 'cat > /etc/mysql/mariadb.conf.d/99-ebq.cnf'", $cnf)
            && $this->run("{$ssh} root@{$ip} 'mkdir -p /var/log/mysql && chown mysql:mysql /var/log/mysql && systemctl restart mariadb'");
        if (! $cfgOk) {
            $node->update(['last_error' => 'MariaDB server-config (bind/binlog) step failed']);

            return false;
        }

        $dbUser = (string) config('database.connections.global.username');
        $dbPass = (string) config('database.connections.global.password');
        $db = $node->db_name;
        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s`; "
            ."CREATE USER IF NOT EXISTS '%s'@'10.0.0.%%' IDENTIFIED BY '%s'; "
            ."GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'10.0.0.%%'; FLUSH PRIVILEGES;",
            $db, $dbUser, addslashes($dbPass), $db, $dbUser
        );
        // Pipe SQL via stdin to remote `mysql` (not `mysql -e "…"`) so backticks and
        // the password never pass through a nested ssh/shell quoting layer. The
        // snapshot allows local root via unix_socket. Firewall opened separately.
        $r1 = $this->runWithInput("{$ssh} root@{$ip} mysql", $sql);
        $this->run("{$ssh} root@{$ip} 'ufw allow from 10.0.0.0/24 to any port 3306 proto tcp || true'");

        ShardManager::flush();
        $migrated = $this->migrateNode($node);

        $ok = $r1 && $migrated;
        $node->update($ok
            ? ['status' => DbNode::STATUS_ACTIVE, 'is_healthy' => true, 'last_seen_at' => now(), 'last_error' => null]
            : ['last_error' => 'bootstrap (mysql/migrate) failed']);
        Log::info('DbFleet: bootstrap '.($ok ? 'ok' : 'FAILED'), ['node' => $node->id, 'ip' => $ip]);

        return $ok;
    }

    /**
     * Run the schema on a node from the web box over its registered connection.
     * Both tiers run the full migration set (cross-central FKs are dropped, so
     * the tables stand alone); unused central tables are harmless.
     */
    public function migrateNode(DbNode $node): bool
    {
        $conn = $node->connectionName();
        if (! config("database.connections.{$conn}")) {
            (new ShardManager)->register();
        }
        // During bootstrap the node is still 'provisioning' (not in
        // REGISTERABLE_STATUSES), so ShardManager skips it. Register its
        // connection directly from its own IP/db so we can migrate before active.
        if (! config("database.connections.{$conn}") && $node->private_ip && $node->db_name) {
            $labels = $node->labels ?? [];
            $port = is_array($labels) && isset($labels['port']) ? (int) $labels['port'] : null;
            config(["database.connections.{$conn}" => (new ShardManager)->buildConfig($node->private_ip, $node->db_name, $port)]);
            DB::purge($conn);
        }
        if (! config("database.connections.{$conn}")) {
            $node->update(['last_error' => "connection {$conn} not registered (no private_ip/db_name?)"]);

            return false;
        }
        try {
            Artisan::call('migrate', ['--database' => $conn, '--force' => true]);

            return true;
        } catch (\Throwable $e) {
            $node->update(['last_error' => mb_substr('migrate failed: '.$e->getMessage(), 0, 250)]);
            Log::error('DbFleet: migrate failed', ['node' => $node->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function drain(DbNode $node): void
    {
        $node->update(['status' => DbNode::STATUS_DRAINING, 'drain_started_at' => now()]);
        ShardManager::flush();
    }

    /** Destroy a node — only if empty + not pinned. Move its tenants/sites off first. */
    public function destroy(DbNode $node): bool
    {
        if ($node->is_pinned) {
            return false;
        }
        if (! $node->isEmpty()) {
            $node->update(['last_error' => 'refusing to destroy a node that still hosts tenants/sites — move them off first']);

            return false;
        }
        if ($node->hetzner_server_id) {
            $this->hetzner->deleteServer($node->hetzner_server_id);
        }
        $node->delete();
        ShardManager::flush();
        Log::info('DbFleet: destroyed', ['node' => $node->id]);

        return true;
    }

    private function waitForSsh(string $ip, string $ssh): bool
    {
        for ($i = 0; $i < 30; $i++) {
            if ($this->run("{$ssh} root@{$ip} 'true'")) {
                return true;
            }
            sleep(5);
        }

        return false;
    }

    private function run(string $cmd): bool
    {
        try {
            return Process::timeout(600)->run($cmd)->successful();
        } catch (\Throwable $e) {
            Log::warning('DbFleet: command failed', ['cmd' => $cmd, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Like {@see run()} but feeds $input to the command's stdin (e.g. piping SQL to mysql). */
    private function runWithInput(string $cmd, string $input): bool
    {
        try {
            return Process::input($input)->timeout(600)->run($cmd)->successful();
        } catch (\Throwable $e) {
            Log::warning('DbFleet: command failed', ['cmd' => $cmd, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
