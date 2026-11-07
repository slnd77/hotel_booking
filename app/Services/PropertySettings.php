<?php

namespace App\Services;

use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyMetaField;
use Closure;

/**
 * A property's settings (property_meta_fields), read once per request: one query for the meta
 * field ids, one per property for all its values. Decoded JSON and values derived from it are
 * kept for the rest of the request; saving or deleting a setting clears that property.
 *
 * Bound as a scoped singleton, so every request and queued job starts empty.
 */
class PropertySettings
{
    /** @var array<string, int>|null meta field key => id */
    private ?array $metaFieldIds = null;

    /** @var array<int, array<int, array{data: string|null, enabled: bool}>> property id => meta field id => value */
    private array $values = [];

    /** @var array<int, array<string, mixed>> property id => name => cached value */
    private array $remembered = [];

    public function metaFieldId(string $key): ?int
    {
        $this->metaFieldIds ??= MetaField::query()->pluck('id', 'key')->map(fn (mixed $id): int => (int) $id)->all();

        return $this->metaFieldIds[$key] ?? null;
    }

    /**
     * The setting's stored text (JSON or rich text), or null when it was never saved.
     */
    public function raw(Property $property, string $key): ?string
    {
        return $this->value($property, $key)['data'] ?? null;
    }

    public function enabled(Property $property, string $key): bool
    {
        return $this->value($property, $key)['enabled'] ?? false;
    }

    /**
     * The setting decoded from JSON; null when it is unset, empty or not a JSON object/array.
     *
     * @return array<mixed>|null
     */
    public function json(Property $property, string $key): ?array
    {
        return $this->remember($property, "json:{$key}", function () use ($property, $key): ?array {
            $raw = $this->raw($property, $key);
            $decoded = filled($raw) ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : null;
        });
    }

    /**
     * A value derived from the property's settings, computed once per request (until a setting
     * of the property changes).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(Property $property, string $name, Closure $callback): mixed
    {
        $id = (int) $property->getKey();

        if (! array_key_exists($id, $this->remembered) || ! array_key_exists($name, $this->remembered[$id])) {
            $this->remembered[$id][$name] = $callback();
        }

        return $this->remembered[$id][$name];
    }

    /**
     * Drop what was read for one property (or everything): after a setting is saved or deleted.
     */
    public function forget(Property|int|null $property = null): void
    {
        if ($property === null) {
            $this->values = [];
            $this->remembered = [];

            return;
        }

        $id = (int) ($property instanceof Property ? $property->getKey() : $property);

        unset($this->values[$id], $this->remembered[$id]);
    }

    /**
     * A meta field was added or renamed: look the ids up again.
     */
    public function forgetMetaFields(): void
    {
        $this->metaFieldIds = null;
        $this->values = [];
        $this->remembered = [];
    }

    /**
     * @return array{data: string|null, enabled: bool}|null
     */
    private function value(Property $property, string $key): ?array
    {
        $fieldId = $this->metaFieldId($key);

        if ($fieldId === null) {
            return null;
        }

        $id = (int) $property->getKey();

        $this->values[$id] ??= PropertyMetaField::query()
            ->where('property_id', $property->getKey())
            ->get(['meta_fields_key_id', 'data', 'enabled'])
            ->mapWithKeys(fn (PropertyMetaField $row): array => [(int) $row->meta_fields_key_id => ['data' => $row->data, 'enabled' => (bool) $row->enabled]])
            ->all();

        return $this->values[$id][$fieldId] ?? null;
    }
}
