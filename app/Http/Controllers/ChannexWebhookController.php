<?php

namespace App\Http\Controllers;

use App\Jobs\PullChannexBookings;
use App\Models\ChannexConnection;
use Illuminate\Http\JsonResponse;

/**
 * Channex calls this on every booking change of a connected property; the secret token in
 * the URL names the connection. The bookings themselves are fetched from the revision feed.
 */
class ChannexWebhookController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $connection = ChannexConnection::query()->where('webhook_token', $token)->where('is_active', true)->first();

        abort_if($connection === null, 404);

        PullChannexBookings::dispatch($connection->id);

        return response()->json(['ok' => true]);
    }
}
