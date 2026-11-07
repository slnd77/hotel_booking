<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The property an admin page is about, as the header's property picker shows it: the page's
 * own property (its {property} route parameter, a bound record's property or ?property=),
 * else the one last used in this session, else the first in the dropdown order. Resolved on
 * the server so Inertia's SSR markup and the hydrating browser show the same property.
 */
class CurrentProperty
{
    public const SESSION_KEY = 'admin.current_property_id';

    private const REQUEST_ATTRIBUTE = 'currentProperty.fromRequest';

    private const RESOLVED_ATTRIBUTE = 'currentProperty.resolved';

    public function __construct(private ApplicationSettings $settings) {}

    /**
     * The page's own property, when the request names one (loaded with its currency).
     */
    public function fromRequest(Request $request): ?Property
    {
        if (! $request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $recordProperty = collect($request->route()?->parameters() ?? [])
                ->first(fn ($parameter): bool => $parameter instanceof Model && ! $parameter instanceof Property && $parameter->getAttribute('property_id') !== null)
                ?->getAttribute('property_id');
            $property = $request->route('property') ?? $recordProperty ?? $request->query('property');

            $request->attributes->set(self::REQUEST_ATTRIBUTE, match (true) {
                $property instanceof Property => $property->loadMissing('currency'),
                is_numeric($property) => Property::query()->with('currency')->find($property),
                default => null,
            });
        }

        return $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    /**
     * The picker's property: the page's own one (remembered for later pages), else the
     * remembered one, else the first. `scoped` says the page itself is about that property.
     *
     * @param  EloquentCollection<int, Property>|null  $properties  the dropdown's properties, when already loaded
     * @return array{id: int|null, scoped: bool}
     */
    public function resolve(Request $request, ?EloquentCollection $properties = null): array
    {
        if ($request->attributes->has(self::RESOLVED_ATTRIBUTE)) {
            return $request->attributes->get(self::RESOLVED_ATTRIBUTE);
        }

        $properties ??= $this->settings->orderedProperties(['id']);
        $session = $request->hasSession() ? $request->session() : null;
        $own = $this->fromRequest($request)?->id;

        if ($own !== null && $properties->contains('id', $own)) {
            $session?->put(self::SESSION_KEY, $own);
            $resolved = ['id' => $own, 'scoped' => true];
        } else {
            $remembered = $session?->get(self::SESSION_KEY);
            $id = is_int($remembered) && $properties->contains('id', $remembered) ? $remembered : $properties->first()?->id;
            $resolved = ['id' => $id, 'scoped' => false];
        }

        $request->attributes->set(self::RESOLVED_ATTRIBUTE, $resolved);

        return $resolved;
    }

    /**
     * For a page listing one property's records: the ?property= property, else the
     * remembered one, else the first — the same property the header picker shows.
     *
     * @param  EloquentCollection<int, Property>  $properties
     */
    public function selected(Request $request, EloquentCollection $properties): ?Property
    {
        return $properties->firstWhere('id', $this->resolve($request, $properties)['id']);
    }
}
