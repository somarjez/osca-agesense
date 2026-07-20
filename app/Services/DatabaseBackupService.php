<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * In-app equivalent of backup-database.ps1 — dumps the app's MySQL database via
 * mysqldump into database/backups/, keeps only the latest N app-created backups,
 * and exposes them for download/delete from the admin UI.
 *
 * Deliberately mirrors the PowerShell script's behavior (same mysqldump flags,
 * same password-via-env-var handling, same loud-failure-on-empty-dump guard) so
 * the two paths produce interchangeable output. Uses a distinct filename prefix
 * (`osca_backup_`) so rotation never touches the pre-existing manual/defense
 * dumps or the PowerShell script's own `osca_db_backup_*` files.
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
     * Run mysqldump and write a new timestamped backup file. Rotates old
     * app-created backups afterward. Returns the created filename.
     *
     * @throws \RuntimeException on any failure — never leaves a partial/empty file behind.
     */
    public function create(int $keep = self::DEFAULT_KEEP): string
    {
        if (config('database.default') !== 'mysql') {
            throw new \RuntimeException(
                "Unsupported DB_CONNECTION ('".config('database.default')."') — only mysqldump-based backups are supported."
            );
        }

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

        $mysqldump = $this->locateMysqldump();
        if (! $mysqldump) {
            throw new \RuntimeException(
                'mysqldump not found on PATH or in common Laragon/XAMPP/MySQL install locations. '
                .'Install MySQL client tools or add mysqldump.exe\'s folder to PATH, then retry.'
            );
        }

        $dir = $this->backupsDir();
        $filename = self::PREFIX.now()->format('Ymd_His').'.sql';
        $outputFile = $dir.DIRECTORY_SEPARATOR.$filename;

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

        if (! is_file($outputFile) || filesize($outputFile) === 0) {
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }

            throw new \RuntimeException('mysqldump produced no output or a 0-byte file. Check DB connectivity/credentials and try again.');
        }

        $this->rotate($keep);

        return $filename;
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

    private function locateMysqldump(): ?string
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
