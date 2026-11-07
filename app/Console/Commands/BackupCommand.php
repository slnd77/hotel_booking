<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

#[Signature('ops:backup {--connection= : The database connection to dump (default: the app\'s)} {--no-files : Skip the uploaded files}')]
#[Description('Back up the database (mysqldump / pg_dump / SQLite copy, gzipped) and the uploaded files to the backup disk, and remove backups older than BACKUP_KEEP_DAYS')]
class BackupCommand extends Command
{
    public function handle(): int
    {
        $stamp = now()->utc()->format('Y-m-d-His');
        $workDir = storage_path('app/backup-tmp');
        File::ensureDirectoryExists($workDir);
        $disk = Storage::disk(config('ops.backup.disk'));

        try {
            $connectionName = $this->option('connection') ?: config('database.default');
            $dump = "{$workDir}/{$stamp}-{$connectionName}.sql";
            $this->dumpDatabase((string) $connectionName, $dump);
            $this->upload($disk, $this->gzip($dump), "backups/{$stamp}-{$connectionName}.sql.gz");

            if (config('ops.backup.include_files') && ! $this->option('no-files') && ($zip = $this->zipFiles("{$workDir}/{$stamp}-files.zip")) !== null) {
                $this->upload($disk, $zip, "backups/{$stamp}-files.zip");
            }
        } catch (Throwable $exception) {
            Log::critical('Backup failed: '.$exception->getMessage());
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDir);
        }

        $pruned = $this->prune($disk);
        $this->info('Backed up to the "'.config('ops.backup.disk')."\" disk as backups/{$stamp}-*; removed {$pruned} old file(s).");

        return self::SUCCESS;
    }

    private function dumpDatabase(string $connectionName, string $target): void
    {
        $connection = config("database.connections.{$connectionName}") ?? throw new RuntimeException("No database connection {$connectionName}.");
        $binaryPath = rtrim((string) config('ops.backup.dump_binary_path'), '/\\');
        $binary = fn (string $name): string => $binaryPath === '' ? $name : "{$binaryPath}/{$name}";

        match ($connection['driver']) {
            'mysql', 'mariadb' => $this->runDump([
                $binary('mysqldump'), '--single-transaction', '--quick', '--routines', '--no-tablespaces',
                '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'],
                '--result-file='.$target, $connection['database'],
            ], ['MYSQL_PWD' => (string) $connection['password']]),
            'pgsql' => $this->runDump([
                $binary('pg_dump'), '--no-owner', '--no-privileges', '--host='.$connection['host'], '--port='.$connection['port'],
                '--username='.$connection['username'], '--file='.$target, $connection['database'],
            ], ['PGPASSWORD' => (string) $connection['password']]),
            'sqlite' => $connection['database'] === ':memory:'
                ? throw new RuntimeException('An in-memory SQLite database cannot be backed up.')
                : File::copy($connection['database'], $target),
            default => throw new RuntimeException("Backing up a {$connection['driver']} database isn't supported."),
        };

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('The database dump is empty.');
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function runDump(array $command, array $environment): void
    {
        $result = Process::timeout(3600)->env($environment)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "{$command[0]} exited with code {$result->exitCode()}.");
        }
    }

    private function gzip(string $source): string
    {
        $target = "{$source}.gz";
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);

        return $target;
    }

    /**
     * The local disk's files (ID documents, attachments, logos), without the backups
     * themselves. Null when there is nothing to back up or no zip extension.
     */
    private function zipFiles(string $target): ?string
    {
        $root = rtrim((string) config('filesystems.disks.local.root'), '/\\');

        if (! class_exists(ZipArchive::class) || ! is_dir($root)) {
            $this->warn('Uploaded files not backed up (no zip extension or no files).');

            return null;
        }

        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $added = 0;

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_starts_with($relative, 'backups/') || str_starts_with($relative, 'backup-tmp/') || $file->getFilename() === '.gitignore') {
                continue;
            }

            $zip->addFile($file->getPathname(), $relative);
            $added++;
        }

        $zip->close();

        return $added > 0 ? $target : null;
    }

    private function upload(Filesystem $disk, string $source, string $path): void
    {
        $stream = fopen($source, 'rb');
        $disk->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->line("Wrote {$path} (".number_format(filesize($source) / 1024, 0).' KB).');
    }

    private function prune(Filesystem $disk): int
    {
        $cutoff = now()->subDays(config('ops.backup.keep_days'))->timestamp;
        $old = collect($disk->files('backups'))->filter(fn (string $path): bool => $disk->lastModified($path) < $cutoff)->values();
        $disk->delete($old->all());

        return $old->count();
    }
}
