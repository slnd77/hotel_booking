<?php

namespace App\Services;

use App\Events\AccountDataPurged;
use App\Events\AccountDataPurging;
use App\Jobs\ExportAccountData;
use App\Models\AccountDataRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Manage Account → Data & privacy: everything the account holds, exported as one ZIP (a CSV
 * per table plus its uploaded files), and the account's deletion: scheduled after a grace
 * period, then every row and file of the account is removed.
 *
 * It works on the account's own rows only: with several business accounts, the queries are
 * limited to the current one.
 */
class AccountData
{
    /** Days an account deletion waits, cancellable, before it runs. */
    public const GRACE_DAYS = 30;

    /** Days an export can be downloaded. */
    public const EXPORT_DAYS = 7;

    public const EXPORT_DIRECTORY = 'account-exports';

    /**
     * Uploaded files the app keeps per account: disk => directories.
     *
     * @var array<string, list<string>>
     */
    public const FILE_DIRECTORIES = [
        'local' => ['booking-attachments', 'web-checkins', 'invoice-settings'],
        'public' => [PropertyBranding::IMAGE_DIRECTORY, 'room-category-images'],
    ];

    /**
     * Reference data every account reads (countries, currencies…): neither exported nor deleted.
     *
     * @var list<string>
     */
    public const REFERENCE_TABLES = [
        'countries', 'states', 'cities', 'currencies', 'languages', 'meta_fields', 'form_fields',
        'amenities', 'amenity_categories', 'supported_metasearch_platforms', 'supported_third_party_ad_trackings',
        'supported_third_party_analytics', 'roles', 'permissions',
    ];

    /**
     * Columns never exported, beyond each model's hidden ones.
     *
     * @var list<string>
     */
    private const SECRET_COLUMNS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function requestExport(User $user): AccountDataRequest
    {
        if (AccountDataRequest::query()->where('type', AccountDataRequest::EXPORT)->where('status', AccountDataRequest::QUEUED)->exists()) {
            throw ValidationException::withMessages(['export' => 'An export is already being prepared.']);
        }

        $request = AccountDataRequest::query()->create(['type' => AccountDataRequest::EXPORT, 'status' => AccountDataRequest::QUEUED, 'user_id' => $user->id]);
        ExportAccountData::dispatch($request);

        return $request;
    }

    /**
     * Builds the export ZIP: data/{table}.csv for every table of the account, files/{disk}/…
     * for its uploads, and manifest.json.
     */
    public function export(AccountDataRequest $request): void
    {
        $path = self::EXPORT_DIRECTORY.'/account-data-'.now()->format('Y-m-d').'-'.Str::random(24).'.zip';
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::EXPORT_DIRECTORY);
        $temporaryFiles = [];

        try {
            $zip = new ZipArchive;

            if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export file could not be created.');
            }

            $tables = [];

            foreach ($this->models() as $model => $table) {
                $temporaryFiles[] = $csv = tempnam(sys_get_temp_dir(), 'export');
                $tables[$table] = $this->writeCsv($model, $csv);
                $zip->addFile($csv, "data/{$table}.csv");
            }

            $files = 0;

            foreach (self::FILE_DIRECTORIES as $diskName => $directories) {
                $source = Storage::disk($diskName);

                foreach ($directories as $directory) {
                    foreach ($source->allFiles($directory) as $file) {
                        $zip->addFromString("files/{$diskName}/{$file}", (string) $source->get($file));
                        $files++;
                    }
                }
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'exported_at' => now()->toIso8601String(),
                'application' => config('app.name'),
                'tables' => $tables,
                'files' => $files,
                'notes' => 'One CSV per table (JSON columns as JSON text, times in UTC). Passwords, two-factor secrets and encrypted settings (API keys, SMTP passwords) are left out.',
            ], JSON_PRETTY_PRINT));
            $zip->close();

            $request->update([
                'status' => AccountDataRequest::READY,
                'file_path' => $path,
                'file_size' => $disk->size($path),
                'completed_at' => now(),
                'expires_at' => now()->addDays(self::EXPORT_DAYS),
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            $request->update(['status' => AccountDataRequest::FAILED, 'error' => Str::limit($exception->getMessage(), 500)]);

            report($exception);
        } finally {
            array_map(fn (string $file) => @unlink($file), $temporaryFiles);
        }
    }

    public function expireExport(AccountDataRequest $request): void
    {
        if ($request->file_path !== null) {
            Storage::disk('local')->delete($request->file_path);
        }

        $request->update(['status' => AccountDataRequest::EXPIRED, 'file_path' => null]);
    }

    /**
     * Schedules the account's deletion after the grace period.
     */
    public function scheduleDeletion(User $user): AccountDataRequest
    {
        if (AccountDataRequest::scheduledDeletion() !== null) {
            throw ValidationException::withMessages(['confirmation' => 'The account is already scheduled for deletion.']);
        }

        return AccountDataRequest::query()->create([
            'type' => AccountDataRequest::DELETION,
            'status' => AccountDataRequest::SCHEDULED,
            'user_id' => $user->id,
            'scheduled_for' => now()->addDays(self::GRACE_DAYS),
        ]);
    }

    public function cancelDeletion(AccountDataRequest $request, User $user): void
    {
        $request->update(['status' => AccountDataRequest::CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
    }

    /**
     * Removes every row and uploaded file of the account. The deletion request itself stays,
     * completed, as the record that it happened.
     */
    public function purge(AccountDataRequest $request): void
    {
        if ($request->status !== AccountDataRequest::SCHEDULED || $request->scheduled_for?->isFuture()) {
            return;
        }

        // Listeners may stop it (an installed package may refuse a purge it doesn't allow).
        AccountDataPurging::dispatch($request);

        foreach (self::FILE_DIRECTORIES + ['local' => []] as $diskName => $directories) {
            foreach ([...$directories, ...($diskName === 'local' ? [self::EXPORT_DIRECTORY] : [])] as $directory) {
                Storage::disk($diskName)->deleteDirectory($directory);
            }
        }

        $models = collect($this->models())->except(AccountDataRequest::class)->all();

        $this->deleteUsersSharedRows();
        $this->deletePivotRows($models);
        $this->deleteRows($models);

        $request->update(['status' => AccountDataRequest::COMPLETED, 'completed_at' => now()]);

        AccountDataPurged::dispatch($request);
    }

    /**
     * The app's models holding account data, model => table, parents before children.
     *
     * @return array<class-string<Model>, string>
     */
    public function models(): array
    {
        $models = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! in_array($table, self::REFERENCE_TABLES, true) && Schema::hasTable($table)) {
                $models[$class] = $table;
            }
        }

        ksort($models);

        return $models;
    }

    /**
     * @param  class-string<Model>  $model
     * @return int rows written
     */
    private function writeCsv(string $model, string $file): int
    {
        $instance = new $model;
        $skip = [...$instance->getHidden(), ...self::SECRET_COLUMNS, ...array_keys(array_filter($instance->getCasts(), fn (string $cast): bool => str_starts_with($cast, 'encrypted')))];
        $handle = fopen($file, 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not write {$file}.");
        }

        $columns = null;
        $count = 0;

        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        foreach ($query->toBase()->orderBy($instance->getKeyName())->cursor() as $row) {
            $row = array_diff_key((array) $row, array_flip($skip));
            $columns ??= array_keys($row);

            if ($count === 0) {
                fputcsv($handle, $columns, escape: '');
            }

            fputcsv($handle, array_map(fn (mixed $value): mixed => is_array($value) || is_object($value) ? json_encode($value) : $value, array_values($row)), escape: '');
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Users' rows in the shared permission, token, passkey and session tables.
     */
    private function deleteUsersSharedRows(): void
    {
        $userIds = User::query()->select('id');
        $morph = (new User)->getMorphClass();

        foreach (['model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id'] as $table => $column) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('model_type', $morph)->whereIn($column, $userIds)->delete();
            }
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_type', $morph)->whereIn('tokenable_id', $userIds)->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        }
    }

    /**
     * Rows of tables without a model (pivots, passkeys…) pointing at the account's rows.
     *
     * @param  array<class-string<Model>, string>  $models
     */
    private function deletePivotRows(array $models): void
    {
        $tableModels = array_flip($models);

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (isset($tableModels[$table]) || in_array($table, self::REFERENCE_TABLES, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $tableModels[$foreignKey['foreign_table']] ?? null;

                if ($parent !== null && count($foreignKey['columns']) === 1) {
                    DB::table($table)->whereIn($foreignKey['columns'][0], $parent::query()->select($foreignKey['foreign_columns'][0]))->delete();
                }
            }
        }
    }

    /**
     * Deletes each model's rows, children before the parents they reference; a table still
     * referenced is tried again after the others.
     *
     * @param  array<class-string<Model>, string>  $models
     */
    private function deleteRows(array $models): void
    {
        $pending = $models;
        $errors = [];

        for ($pass = 0; $pending !== [] && $pass <= count($models); $pass++) {
            foreach ($pending as $model => $table) {
                try {
                    DB::transaction(function () use ($model): void {
                        $query = $model::query();

                        // forceDelete() would skip the global scopes (the account's own rows only):
                        // toBase() keeps them.
                        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                            $query->withoutGlobalScope(SoftDeletingScope::class);
                        }

                        $query->toBase()->delete();
                    });

                    unset($pending[$model]);
                } catch (QueryException $exception) {
                    // Still referenced by a table not deleted yet.
                    $errors[$table] = $exception->getMessage();
                }
            }
        }

        if ($pending !== []) {
            throw new RuntimeException('Some account data could not be deleted: '.collect($pending)->map(fn (string $table): string => "{$table} (".Str::limit($errors[$table] ?? '', 300).')')->implode(', '));
        }
    }
}
