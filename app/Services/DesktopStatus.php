<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class DesktopStatus
{
    public const APPLICATION = 'mission-control';

    /** @return array<string, mixed> */
    public function health(): array
    {
        $database = ['ready' => false, 'message' => 'The Mission Control database is unavailable or its migrations are incomplete.'];
        $activeTasks = $activeRuns = $pendingJobs = $reservedJobs = null;
        try {
            $connection = $this->connection();
            $queueConnection = config('queue.connections.database.connection');
            $jobs = $queueConnection ? $this->connection($queueConnection) : $connection;
            $activeTasks = $connection->table('mission_tasks')->whereIn('status', ['queued', 'running'])->count();
            $activeRuns = $connection->table('agent_runs')->whereIn('status', ['queued', 'running'])->count();
            $query = $jobs->table(config('queue.connections.database.table', 'jobs'))->whereIn('queue', ['missions', 'default']);
            $pendingJobs = (clone $query)->count();
            $reservedJobs = (clone $query)->whereNotNull('reserved_at')->count();
            $database = ['ready' => true, 'message' => 'The Mission Control database is ready.'];
        } catch (Throwable) {
            $activeTasks = $activeRuns = $pendingJobs = $reservedJobs = null;
        }

        return [
            'application' => self::APPLICATION,
            'repo_root' => hash('sha256', realpath(base_path()) ?: base_path()),
            'ready' => $database['ready'],
            'database' => $database,
            'active_tasks' => $activeTasks,
            'active_runs' => $activeRuns,
            'pending_jobs' => $pendingJobs,
            'reserved_jobs' => $reservedJobs,
        ];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->health();
        $required = [
            'artisan' => base_path('artisan'),
            'bootstrap/app.php' => base_path('bootstrap/app.php'),
            'vendor/autoload.php' => base_path('vendor/autoload.php'),
            'public/build/manifest.json' => public_path('build/manifest.json'),
            'worker/dist/index.js' => config('mission.worker_path'),
            'worker/dist/preview-cli.js' => config('mission.preview_cli_path'),
            'worker/node_modules/@openai/codex-sdk/package.json' => base_path('worker/node_modules/@openai/codex-sdk/package.json'),
        ];
        $missing = [];
        foreach ($required as $label => $path) {
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                $missing[] = $label;
            }
        }
        $preview = $this->previewDatabase();

        return [
            ...$health,
            'repository_path' => realpath(base_path()) ?: base_path(),
            'ready' => $health['ready'] && $missing === [],
            'files' => ['ready' => $missing === [], 'missing' => $missing],
            'preview_database' => $preview,
            'api_key_present' => is_string(config('mission.openai_api_key')) && trim(config('mission.openai_api_key')) !== '',
        ];
    }

    /** @return array{ready: bool, driver: ?string, host: ?string, port: ?int, message: string} */
    public function previewDatabase(): array
    {
        $configuration = config('mission.preview_database', []);
        $driver = $configuration['driver'] ?? null;
        $host = $configuration['host'] ?? null;
        $port = $configuration['port'] ?? null;
        $local = in_array($host, ['/tmp', 'localhost', '127.0.0.1', '::1'], true);
        $validPort = is_int($port) && $port > 0 && $port < 65536;
        $result = ['ready' => false, 'driver' => in_array($driver, ['sqlite', 'pgsql'], true) ? $driver : null, 'host' => $driver === 'pgsql' && $local ? $host : null, 'port' => $driver === 'pgsql' && $validPort ? $port : null, 'message' => 'The preview database is unavailable or its local configuration is invalid.'];
        try {
            if ($driver === 'sqlite' && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                return [...$result, 'ready' => true, 'message' => 'SQLite preview support is ready.'];
            }
            $username = $configuration['username'] ?? null;
            if ($driver !== 'pgsql' || ! $local || ! $validPort || ! is_string($username) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $username)) {
                return $result;
            }
            $connection = $this->postgres([
                'driver' => 'pgsql', 'host' => $host, 'port' => $port, 'database' => 'postgres',
                'username' => $username, 'password' => $configuration['password'] ?? '', 'search_path' => 'public',
            ]);
            $role = $connection->selectOne('select (rolcreatedb or rolsuper) as can_create_database from pg_roles where rolname = current_user');
            if ($role && in_array($role->can_create_database, [true, 1, '1', 't'], true)) {
                return [...$result, 'ready' => true, 'message' => 'The local preview database is ready.'];
            }

            return [...$result, 'message' => 'The local preview database role needs permission to create disposable databases.'];
        } catch (Throwable) {
            return $result;
        }
    }

    protected function connection(?string $name = null): Connection
    {
        $connection = DB::connection($name);
        if ($connection->getDriverName() === 'pgsql') {
            return $this->postgres($connection->getConfig());
        }
        if ($connection->getDriverName() === 'sqlite') {
            return $connection;
        }

        throw new RuntimeException('Unsupported desktop database driver.');
    }

    /** @param array<string, mixed> $configuration */
    protected function postgres(array $configuration): PostgresConnection
    {
        $configuration['server_options'] = [...($configuration['server_options'] ?? []), 'default_transaction_read_only' => 'on', 'statement_timeout' => 2000, 'lock_timeout' => 2000];
        $connector = new class extends PostgresConnector
        {
            protected function getDsn(array $config): string
            {
                return parent::getDsn($config).';connect_timeout=2';
            }
        };

        return new PostgresConnection($connector->connect($configuration), $configuration['database'], $configuration['prefix'] ?? '', $configuration);
    }
}
