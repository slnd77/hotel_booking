<?php

namespace App\Services\Channex;

use App\Models\ChannexConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Channex API (https://docs.channex.io). Uses the connection's own key
 * when it has one, else CHANNEX_API_KEY. Every failure becomes a ChannexException.
 */
class ChannexClient
{
    public function __construct(private ?string $apiKey = null) {}

    public static function for(?ChannexConnection $connection = null): self
    {
        return new self($connection?->api_key);
    }

    public static function isConfigured(?ChannexConnection $connection = null): bool
    {
        return filled($connection?->api_key) || filled(config('services.channex.api_key'));
    }

    /**
     * @return list<array{id: string, title: string, currency: string|null}>
     */
    public function properties(): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['attributes']['title'] ?? '',
            'currency' => $row['attributes']['currency'] ?? null,
        ], $this->get('properties', ['pagination[limit]' => 100])['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProperty(array $attributes): string
    {
        return $this->post('properties', ['property' => $attributes])['data']['id'];
    }

    /**
     * @return list<array{id: string, title: string, count_of_rooms: int, occ_adults: int}>
     */
    public function roomTypes(string $propertyId): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['attributes']['title'] ?? '',
            'count_of_rooms' => (int) ($row['attributes']['count_of_rooms'] ?? 0),
            'occ_adults' => (int) ($row['attributes']['occ_adults'] ?? 0),
        ], $this->get('room_types', ['filter[property_id]' => $propertyId, 'pagination[limit]' => 100])['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRoomType(array $attributes): string
    {
        return $this->post('room_types', ['room_type' => $attributes])['data']['id'];
    }

    /**
     * @return list<array{id: string, title: string, room_type_id: string|null, currency: string|null}>
     */
    public function ratePlans(string $propertyId): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['attributes']['title'] ?? '',
            'room_type_id' => $row['relationships']['room_type']['data']['id'] ?? null,
            'currency' => $row['attributes']['currency'] ?? null,
        ], $this->get('rate_plans', ['filter[property_id]' => $propertyId, 'pagination[limit]' => 100])['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRatePlan(array $attributes): string
    {
        return $this->post('rate_plans', ['rate_plan' => $attributes])['data']['id'];
    }

    /**
     * @param  list<array<string, mixed>>  $values  {property_id, room_type_id, date_from, date_to, availability}
     */
    public function updateAvailability(array $values): void
    {
        foreach (array_chunk($values, 1000) as $chunk) {
            $this->post('availability', ['values' => $chunk]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $values  {property_id, rate_plan_id, date_from, date_to, rate, …}
     */
    public function updateRestrictions(array $values): void
    {
        foreach (array_chunk($values, 1000) as $chunk) {
            $this->post('restrictions', ['values' => $chunk]);
        }
    }

    /**
     * Booking revisions not acknowledged yet, oldest first.
     *
     * @return list<array<string, mixed>> each with id and attributes
     */
    public function bookingFeed(string $propertyId): array
    {
        return $this->get('booking_revisions/feed', ['filter[property_id]' => $propertyId])['data'] ?? [];
    }

    public function acknowledge(string $revisionId): void
    {
        $this->post("booking_revisions/{$revisionId}/ack");
    }

    public function createWebhook(string $propertyId, string $callbackUrl): string
    {
        return $this->post('webhooks', ['webhook' => [
            'property_id' => $propertyId,
            'callback_url' => $callbackUrl,
            'event_mask' => 'booking',
            'is_active' => true,
            'send_data' => false,
        ]])['data']['id'];
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->send('delete', "webhooks/{$webhookId}");
    }

    /**
     * A one-time token for opening Channex's own screens (e.g. connecting OTA channels).
     */
    public function oneTimeToken(string $propertyId, string $username): string
    {
        return $this->post('auth/one_time_token', ['one_time_token' => ['property_id' => $propertyId, 'username' => $username]])['data']['token'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data = []): array
    {
        return $this->send('post', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = []): array
    {
        $key = $this->apiKey ?: config('services.channex.api_key');

        if (blank($key)) {
            throw new ChannexException('No Channex API key is set (CHANNEX_API_KEY).');
        }

        try {
            $response = $this->http((string) $key)->{$method}($path, $data);
        } catch (ConnectionException $exception) {
            throw new ChannexException('Channex could not be reached: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new ChannexException($this->errorMessage($response));
        }

        return $response->json() ?? [];
    }

    private function http(string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.channex.base_url'), '/'))
            ->withHeaders(['user-api-key' => $key])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(2, 500, fn ($exception): bool => $exception instanceof ConnectionException, throw: false);
    }

    private function errorMessage(Response $response): string
    {
        $errors = $response->json('errors');
        $detail = is_array($errors)
            ? trim(($errors['title'] ?? '').' '.json_encode($errors['details'] ?? ($errors['detail'] ?? '')))
            : $response->body();

        return "Channex replied {$response->status()}: ".mb_strimwidth($detail ?: $response->reason(), 0, 500, '…');
    }
}
