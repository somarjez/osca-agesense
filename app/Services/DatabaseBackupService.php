<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dumps the app's database into database/backups/, keeps only the latest N
 * app-created backups, and exposes them for download/delete from the admin UI.
 * Uses a distinct filename prefix (`osca_backup_`) so rotation never touches
 * the pre-existing manual/defense dumps or backup-database.ps1's own
 * `osca_db_backup_*` files.
 *
 * MySQL (local dev, matches backup-database.ps1's behavior — same mysqldump
 * flags, same password-via-env-var handling, same loud-failure-on-empty-dump
 * guard) shells out to mysqldump. Postgres/SQLite (production — Neon has no
 * mysqldump-equivalent binary available on Render) use a portable pure-PHP
 * data-only dump instead: no CREATE TABLE statements, restoring assumes an
 * already-migrated empty schema, same as the documented mysqldump restore
 * flow. Both paths produce the same osca_backup_*.sql naming convention, so
 * list()/rotate()/resolvePath()/delete() work identically for either.
 */
class DatabaseBackupService
{
    public const PREFIX = 'osca_backup_';

    public const DEFAULT_KEEP = 3;

    /**
     * Absolute path to the backups directory, created on demand.
     */
    public function backupsDir(): string
    {
        $dir = database_path('backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Write a new timestamped backup file (mysqldump on MySQL, a portable
     * pure-PHP dump on Postgres/SQLite). Rotates old app-created backups
     * afterward. Returns the created filename.
     *
     * @throws \RuntimeException on any failure — never leaves a partial/empty file behind.
     */
    public function create(int $keep = self::DEFAULT_KEEP): string
    {
        $driver = config('database.default');
        $dir = $this->backupsDir();
        $filename = self::PREFIX.now()->format('Ymd_His').'.sql';
        $outputFile = $dir.DIRECTORY_SEPARATOR.$filename;

        if ($driver === 'mysql') {
            $this->createMysqldumpBackup($outputFile);
        } elseif (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->createViaPortableDump($outputFile, $driver);
        } else {
            throw new \RuntimeException("Unsupported DB_CONNECTION ('{$driver}') — no backup method available.");
        }

        if (! is_file($outputFile) || filesize($outputFile) === 0) {
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }

            throw new \RuntimeException('Backup produced no output or a 0-byte file. Check DB connectivity and try again.');
        }

        $this->rotate($keep);

        return $filename;
    }

    /**
     * MySQL backup via mysqldump — local-dev only (Windows binary search).
     *
     * @throws \RuntimeException on any failure — never leaves a partial/empty file behind.
     */
    private function createMysqldumpBackup(string $outputFile): void
    {
        $conn = config('database.connections.mysql');
        $database = $conn['database'] ?? null;
        $username = $conn['username'] ?? null;

        if (blank($database)) {
            throw new \RuntimeException('DB_DATABASE is not configured — nothing to back up.');
        }
        if (blank($username)) {
            throw new \RuntimeException('DB_USERNAME is not configured.');
        }

        $host = $conn['host'] ?: '127.0.0.1';
        $port = $conn['port'] ?: '3306';
        $password = $conn['password'] ?? '';

        $mysqldump = $this->findMysqldumpBinary();
        if (! $mysqldump) {
            throw new \RuntimeException(
                'mysqldump not found on PATH or in common Laragon/XAMPP/MySQL install locations. '
                .'Install MySQL client tools or add mysqldump.exe\'s folder to PATH, then retry.'
            );
        }

        $process = new Process([
            $mysqldump,
            "--host={$host}",
            "--port={$port}",
            "--user={$username}",
            '--routines',
            '--triggers',
            '--single-transaction',
            '--default-character-set=utf8mb4',
            "--result-file={$outputFile}",
            $database,
        ]);

        // Password via env var (never a CLI argument) so it never appears in
        // process listings or shell history — same rationale as the PS script.
        //
        // SystemRoot/windir/TEMP/TMP are forced explicitly (with safe fallbacks)
        // because mysqldump.exe needs SystemRoot to initialize Winsock. When
        // php artisan serve is launched from a stripped environment (e.g. a
        // background/VBS launcher instead of an interactive shell), Symfony
        // Process's default env merge picks up PHP's own getenv() — which is
        // then also missing SystemRoot — and mysqldump fails with "Can't
        // create TCP/IP socket (10106)" even though MySQL itself is fine.
        $process->setEnv([
            'MYSQL_PWD' => $password,
            'SystemRoot' => getenv('SystemRoot') ?: getenv('windir') ?: 'C:\\Windows',
            'windir' => getenv('windir') ?: getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
        ]);
        $process->setTimeout(600);

        $process->run();

        if (! $process->isSuccessful()) {
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }

            throw new \RuntimeException(
                'mysqldump failed (exit code '.$process->getExitCode().'). '
                .'Check that MySQL is running and the credentials in .env are correct. '
                .trim($process->getErrorOutput())
            );
        }
    }

    /**
     * Laravel-infrastructure tables — not OSCA business data, never
     * restorable in a meaningful way (sessions/cache expire, jobs are
     * transient, the migrations table is auto-managed by `migrate`).
     */
    private const EXCLUDED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /**
     * Portable data-only dump for pgsql/sqlite: no CREATE TABLE statements —
     * restoring assumes an already-migrated (empty) schema, same as the
     * documented mysqldump restore flow (restore into a freshly-created
     * database). Foreign-key checks are disabled for the whole file so
     * insert order across tables never matters.
     */
    private function createViaPortableDump(string $outputFile, string $driver): void
    {
        $fh = fopen($outputFile, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Could not open {$outputFile} for writing.");
        }

        try {
            fwrite($fh, "-- OSCA AgeSense portable backup\n");
            fwrite($fh, '-- Generated: '.now()->toDateTimeString()." (driver: {$driver})\n\n");

            if ($driver === 'pgsql') {
                fwrite($fh, "SET session_replication_role = 'replica';\n\n");
            } else {
                fwrite($fh, "PRAGMA foreign_keys = OFF;\n\n");
            }

            $pdo = DB::connection()->getPdo();
            $quoteIdent = fn (string $name) => '"'.str_replace('"', '""', $name).'"';

            $tables = collect(Schema::getTables())
                ->pluck('name')
                ->reject(fn (string $t) => in_array($t, self::EXCLUDED_TABLES, true))
                ->values();

            foreach ($tables as $table) {
                $columnTypes = collect(Schema::getColumns($table))
                    ->pluck('type_name', 'name');

                fwrite($fh, "-- Table: {$table}\n");

                // No ordering — foreign-key checks are disabled for the whole
                // file (see above), so insert order never matters, and not
                // every table has an "id" column (e.g. spatie/permission's
                // pivot tables use composite primary keys only).
                DB::table($table)->cursor()->each(function ($row) use ($fh, $table, $columnTypes, $pdo, $quoteIdent) {
                    $rowArray = (array) $row;
                    $columns = array_map($quoteIdent, array_keys($rowArray));

                    $values = [];
                    foreach ($rowArray as $col => $value) {
                        $values[] = $this->formatDumpValue($value, $columnTypes->get($col), $pdo);
                    }

                    fwrite($fh, sprintf(
                        "INSERT INTO %s (%s) VALUES (%s);\n",
                        $quoteIdent($table),
                        implode(', ', $columns),
                        implode(', ', $values)
                    ));
                });

                fwrite($fh, "\n");
            }

            fwrite($fh, $driver === 'pgsql'
                ? "SET session_replication_role = 'origin';\n"
                : "PRAGMA foreign_keys = ON;\n");
        } finally {
            fclose($fh);
        }
    }

    /**
     * Format one column value as a SQL literal for the portable dump. Column
     * type name (from Schema::getColumns()) disambiguates booleans, since a
     * PHP bool cast to string is '1'/'' (the empty string is not valid
     * boolean input) and driver-returned representations vary ('t'/'f' vs
     * true/false vs '1'/'0' depending on PDO config).
     */
    private function formatDumpValue($value, ?string $typeName, \PDO $pdo): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($typeName !== null && str_contains(strtolower($typeName), 'bool')) {
            $truthy = $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';

            return $truthy ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value) && $typeName !== null
            && preg_match('/int|numeric|decimal|float|double|real/i', $typeName)) {
            return $value;
        }

        return $pdo->quote((string) $value);
    }

    /**
     * Delete app-created backups beyond the newest $keep. Only ever touches
     * files matching self::PREFIX — manual dumps and other .sql files are
     * never deleted here.
     */
    public function rotate(int $keep = self::DEFAULT_KEEP): void
    {
        $files = $this->appBackupFiles();

        foreach (array_slice($files, $keep) as $stale) {
            @unlink($stale['path']);
        }
    }

    /**
     * The latest $limit app-created backups, newest first, with display metadata.
     *
     * @return array<int, array{name: string, size_human: string, created_at: Carbon}>
     */
    public function list(int $limit = self::DEFAULT_KEEP): array
    {
        return array_map(
            fn (array $f) => [
                'name' => $f['name'],
                'size_human' => $this->humanSize($f['size']),
                'created_at' => Carbon::createFromTimestamp($f['mtime']),
            ],
            array_slice($this->appBackupFiles(), 0, $limit)
        );
    }

    /**
     * Resolve a user-supplied filename to an absolute path inside the backups
     * directory, guarding against path traversal. Aborts 404 if the name
     * doesn't match the expected pattern or the file doesn't exist.
     */
    public function resolvePath(string $file): string
    {
        $safeName = basename($file);

        if (! preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{8}_\d{6}\.sql$/', $safeName)) {
            abort(404);
        }

        $path = $this->backupsDir().DIRECTORY_SEPARATOR.$safeName;

        if (! is_file($path)) {
            abort(404);
        }

        return $path;
    }

    public function delete(string $file): bool
    {
        $path = $this->resolvePath($file);

        return @unlink($path);
    }

    /**
     * All self::PREFIX-matching backups in the directory, newest first.
     *
     * @return array<int, array{name: string, path: string, size: int, mtime: int}>
     */
    private function appBackupFiles(): array
    {
        $matches = glob($this->backupsDir().DIRECTORY_SEPARATOR.self::PREFIX.'*.sql') ?: [];

        $files = array_map(fn (string $path) => [
            'name' => basename($path),
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
        ], $matches);

        usort($files, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $files;
    }

    private function findMysqldumpBinary(): ?string
    {
        $onPath = (new ExecutableFinder)->find('mysqldump');
        if ($onPath) {
            return $onPath;
        }

        $candidatePatterns = [
            getenv('USERPROFILE').'\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'D:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server*\\bin\\mysqldump.exe',
        ];

        foreach ($candidatePatterns as $pattern) {
            $found = glob($pattern) ?: [];
            if (! empty($found)) {
                return $found[0];
            }
        }

        return null;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }
}
