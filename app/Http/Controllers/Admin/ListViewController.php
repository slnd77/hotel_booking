<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Set your default list view" on the admin tables: which columns the signed-in user sees,
 * saved per table so it follows them to any browser.
 */
class ListViewController extends Controller
{
    /** A user keeps at most this many tables' choices. */
    private const MAX_TABLES = 100;

    public function update(Request $request, string $table): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9-]{1,64}$/', $table) === 1, 404);

        $request->validate([
            'columns' => ['present', 'array', 'max:200'],
            'columns.*' => ['boolean'],
        ]);

        $columns = $request->collect('columns')
            ->filter(fn (mixed $shown, int|string $key): bool => is_string($key) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) === 1)
            ->map(fn (mixed $shown): bool => (bool) $shown)
            ->all();

        $user = $request->user();
        $views = [...($user->list_views ?? []), $table => $columns];

        abort_if(count($views) > self::MAX_TABLES, 422, 'Too many saved list views.');

        $user->forceFill(['list_views' => $views])->save();

        return response()->json(['saved' => true]);
    }
}
