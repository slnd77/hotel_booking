<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubBookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyRoomCategoryRequest;
use App\Http\Requests\Admin\UpdatePropertyRoomCategoryRequest;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\User;
use App\Services\DormBeds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PropertyRoomCategoryController extends Controller
{
    /** Prefix of the activity entries Rooms Setup → Activity Logs lists. */
    public const LOG_PREFIX = 'Room category';

    /** Occupancy of a category sold by the bed: one adult per bed. */
    private const DORM_OCCUPANCY = [
        'base_occupancy' => 1,
        'ideal_occupancy' => 1,
        'max_adults' => 1,
        'max_children' => 0,
        'default_number_of_children' => 0,
        'total_number_of_guests' => 1,
    ];

    public function store(StorePropertyRoomCategoryRequest $request, Property $property): RedirectResponse
    {
        $roomCategory = $property->roomCategories()->create([
            ...$request->validated(),
            'display_order' => $property->roomCategories()->count() + 1,
        ]);

        $this->log($property, $request->user(), "{$roomCategory->name} created.");

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room category created.');
    }

    public function update(UpdatePropertyRoomCategoryRequest $request, Property $property, RoomCategory $roomCategory, DormBeds $dormBeds): RedirectResponse
    {
        $data = $request->validated();
        $sellByBedChanged = array_key_exists('sell_by_bed', $data) && (bool) $data['sell_by_bed'] !== (bool) $roomCategory->sell_by_bed;

        // Stays sit on whole rooms or on beds: switching with guests booked would strand them.
        if ($sellByBedChanged && SubBooking::query()->where('room_category_id', $roomCategory->id)->whereIn('status', SubBookingStatus::occupying())->whereDate('check_out_date', '>=', $property->today())->exists()) {
            throw ValidationException::withMessages(['sell_by_bed' => 'This category has current or upcoming stays. Move or finish them before switching between selling by room and by bed.']);
        }

        // A dorm bed is one guest: occupancy is fixed, so a bed is always priced at the Single rate.
        if ($data['sell_by_bed'] ?? $roomCategory->sell_by_bed) {
            $data = [...$data, ...self::DORM_OCCUPANCY];
        }

        $roomCategory->update($data);

        if ($sellByBedChanged) {
            $dormBeds->syncCategory($roomCategory);
        }

        $this->log($property, $request->user(), "{$roomCategory->name} updated.");

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room category updated.');
    }

    public function destroy(Request $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        $roomCategory->delete();

        $this->log($property, $request->user(), "{$roomCategory->name} deleted.");

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room category deleted.');
    }

    /**
     * Drag-and-drop on the category table: the order categories show in (Smart Chart, lists).
     */
    public function reorder(Request $request, Property $property): RedirectResponse
    {
        $ids = $this->validatedOrder($request, $property);

        DB::transaction(function () use ($property, $ids): void {
            foreach ($ids as $index => $id) {
                $property->roomCategories()->whereKey($id)->update(['display_order' => $index + 1]);
            }
        });

        $this->log($property, $request->user(), 'display order changed to: '.$this->names($property, $ids).'.');

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room category order updated.');
    }

    /**
     * The upsell ladder: categories from the highest price (1) to the lowest.
     */
    public function upsellOrder(Request $request, Property $property): RedirectResponse
    {
        $ids = $this->validatedOrder($request, $property);

        DB::transaction(function () use ($property, $ids): void {
            foreach ($ids as $index => $id) {
                $property->roomCategories()->whereKey($id)->update(['upsell_order' => $index + 1]);
            }
        });

        $this->log($property, $request->user(), 'upsell order (highest price first) changed to: '.$this->names($property, $ids).'.');

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Upsell order updated.');
    }

    /**
     * Every one of the property's categories, each once, in the new order.
     *
     * @return list<int>
     */
    private function validatedOrder(Request $request, Property $property): array
    {
        $categoryIds = $property->roomCategories()->pluck('id');

        $ids = $request->validate([
            'category_ids' => ['required', 'array', 'size:'.$categoryIds->count()],
            'category_ids.*' => ['integer', 'distinct', Rule::in($categoryIds->all())],
        ], [
            'category_ids.size' => 'Order every room category.',
        ])['category_ids'];

        return array_map('intval', $ids);
    }

    /**
     * @param  list<int>  $ids
     */
    private function names(Property $property, array $ids): string
    {
        $names = $property->roomCategories()->pluck('name', 'id');

        return implode(', ', array_map(fn (int $id): string => (string) $names[$id], $ids));
    }

    private function log(Property $property, ?User $user, string $message): void
    {
        ActivityLog::create([
            'user_id' => $user?->id,
            'property_id' => $property->id,
            'action' => self::LOG_PREFIX.' '.$message,
            'date' => now(),
        ]);
    }
}
