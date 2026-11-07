<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\BlockReason;
use App\Models\Booking;
use App\Models\ChartNote;
use App\Models\InventoryUnit;
use App\Models\Property;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Services\ApplicationSettings;
use App\Services\CurrentProperty;
use App\Services\Housekeeping;
use App\Services\RoomInventory;
use App\Services\WeekendHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $properties = app(ApplicationSettings::class)->orderedProperties();

        $selectedProperty = app(CurrentProperty::class)->selected($request, $properties);

        $smartChart = $selectedProperty
            ? $this->getSmartChartData($selectedProperty, $request->input('start_date'))
            : null;

        $roomCategories = $smartChart ? $smartChart['categories'] : [];

        return Inertia::render('admin/Dashboard', [
            'properties' => $properties,
            'selectedPropertyId' => $selectedProperty?->id,
            'smartChart' => $smartChart,
            'roomCategories' => $roomCategories,
        ]);
    }

    public function chartData(Request $request): JsonResponse
    {
        $propertyId = $request->integer('property_id');
        $property = Property::query()->find($propertyId) ?? app(ApplicationSettings::class)->orderedProperties(['*'])->first();

        if (! $property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $data = $this->getSmartChartData($property, $request->input('start_date'));

        return response()->json($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSmartChartData(Property $property, ?string $startDateStr = null): array
    {
        $housekeepingStatuses = app(Housekeeping::class)->statuses($property);

        // The chart's days are the property's calendar days: "today" is its local date.
        $today = CarbonImmutable::parse($property->today());
        $start = $startDateStr ? CarbonImmutable::parse($startDateStr)->startOfDay() : $today;

        $dates = [];
        $dateStrings = [];
        $days = app(ApplicationSettings::class)->smartChartDays();
        $weekendHolidays = app(WeekendHolidays::class);
        $weekendHolidaySettings = $weekendHolidays->get($property);

        for ($i = 0; $i < $days; $i++) {
            $d = $start->addDays($i);
            $ds = $d->toDateString();
            $dateStrings[] = $ds;
            $dates[] = [
                'date' => $ds,
                'day_name' => $d->format('D'),
                'day_number' => $d->format('d'),
                'month_name' => $d->format('M'),
                'formatted' => $d->format('D d M'),
                'is_today' => $d->equalTo($today),
                'is_past' => $d->lt($today),
                'year' => (int) $d->format('Y'),
                ...$weekendHolidays->marker($weekendHolidaySettings, $d),
            ];
        }

        $startDateStr = $dateStrings[0];
        $endDateStr = $start->addDays($days)->toDateString();

        // Header icons: open enquiries arriving that day, and the day's notes.
        $enquiriesByDate = Booking::query()
            ->where('property_id', $property->id)
            ->where('status', BookingStatus::Enquiry)
            ->whereDate('check_in', '>=', $startDateStr)
            ->whereDate('check_in', '<', $endDateStr)
            ->pluck('check_in')
            ->countBy(fn ($checkIn): string => CarbonImmutable::parse($checkIn)->toDateString());
        $notesByDate = ChartNote::query()
            ->where('property_id', $property->id)
            ->whereDate('date', '>=', $startDateStr)
            ->whereDate('date', '<', $endDateStr)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ChartNote $note): string => $note->date->toDateString());

        foreach ($dates as $index => $date) {
            $dates[$index]['enquiries_count'] = (int) ($enquiriesByDate[$date['date']] ?? 0);
            $dates[$index]['notes'] = ($notesByDate[$date['date']] ?? collect())->map(fn (ChartNote $note): array => $note->toChartArray())->values()->all();
        }

        $categories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->withCount('rooms')
            ->with(['rooms' => fn ($query) => $query->orderBy('display_order')->orderBy('name')])
            ->orderBy('display_order')
            ->get();

        // Rooms, or beds for a dormitory: what each category sells, and each dorm room's beds.
        $capacity = app(RoomInventory::class)->capacityByCategory($property->id);
        $bedsByRoom = app(RoomInventory::class)->activeBedsByRoom(null, $property->id);

        // One bar per room stay (sub-booking), on the room's own dates — an early
        // check-out ends that room's bar while the rest of the booking carries on, and a
        // guest still in house past the check-out date (an overstay) keeps it through today.
        $businessDate = $property->today();
        $stays = app(RoomInventory::class)
            ->occupiedStays($property->id, $startDateStr, $endDateStr)
            ->select('sub_bookings.*', 'inventory_units.room_id as unit_room_id', 'inventory_units.unit_type as unit_type', 'inventory_units.name as unit_name')
            ->with(['booking' => fn ($query) => $query->withCount('rooms'), 'booking.guests'])
            ->orderBy('sub_bookings.id')
            ->get()
            ->map(function (SubBooking $stay) use ($businessDate): array {
                $b = $stay->booking;
                $guest = $b->guests->first();
                $overstay = $stay->status === SubBookingStatus::CheckedIn && $stay->check_out_date->toDateString() <= $businessDate;
                // In house / departed is the room's own status; otherwise the booking's.
                $status = in_array($stay->status, [SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut], true)
                    ? BookingStatus::from($stay->status->value)
                    : $b->status;

                return [
                    'room_id' => $stay->unit_room_id ? (int) $stay->unit_room_id : null,
                    'room_category_id' => $stay->room_category_id,
                    'entry' => [
                        // A dorm stay's bed number: its lane in the dorm room's row.
                        'bed' => $stay->unit_type === InventoryUnit::TYPE_BED ? (int) preg_replace('/\D+/', '', (string) $stay->unit_name) : null,
                        'booking_id' => $b->id,
                        'sub_booking_id' => $stay->id,
                        // Dragging one room of a multi-room booking asks: just this room, or all?
                        'booking_rooms_count' => $b->rooms_count,
                        'rooms_split' => (bool) $b->rooms_split,
                        'booking_ref_id' => $b->booking_ref_id,
                        'guest_name' => $guest ? trim($guest->first_name.' '.$guest->last_name) : ($b->copy_to[0]['name'] ?? 'Guest #'.$b->id),
                        'check_in' => $stay->check_in_date->toDateString(),
                        'check_out' => $overstay ? CarbonImmutable::parse($businessDate)->addDay()->toDateString() : $stay->check_out_date->toDateString(),
                        'booked_check_out' => $stay->check_out_date->toDateString(),
                        'overstay' => $overstay,
                        'status' => $status?->value ?? 'confirmed',
                        'status_label' => $status?->label() ?? 'Confirmed',
                        'check_in_status' => $b->check_in_status?->value,
                        'type' => $b->type,
                        'is_emergency' => str_contains(strtolower($b->type ?? ''), 'emergency') || str_contains(strtolower($b->special_instructions ?? ''), 'emergency'),
                        'is_blocked' => in_array(strtolower($b->type ?? ''), ['blocked', 'maintenance', 'emergency', 'hold']),
                        'total' => (float) $b->total,
                        'outstanding' => (float) $b->outstanding,
                    ],
                ];
            });

        $blocks = RoomBlock::query()
            ->where('property_id', $property->id)
            ->overlapping($startDateStr, $endDateStr)
            ->with(['user:id,name', 'blockReason'])
            ->get();

        $roomBlocks = $blocks->groupBy('room_id')->map(fn ($roomBlockGroup) => $roomBlockGroup->map(fn (RoomBlock $block): array => [
            'id' => $block->id,
            'block_reason_id' => $block->block_reason_id,
            'reason_label' => $block->blockReason?->name ?? 'Blocked',
            'is_maintenance' => (bool) $block->blockReason?->isMaintenance(),
            'name' => $block->name,
            'remarks' => $block->remarks,
            'start_date' => $block->start_date->toDateString(),
            'end_date' => $block->end_date->toDateString(),
            'blocked_by' => $block->user?->name,
            'blocked_at' => $block->created_at?->toIso8601String(),
        ])->values()->all());

        $roomBookings = [];
        foreach ($categories as $cat) {
            foreach ($cat->rooms as $room) {
                $roomBookings[$room->id] = [];
            }
        }

        // 1. Stays on an assigned room.
        foreach ($stays as $stay) {
            if ($stay['room_id'] && isset($roomBookings[$stay['room_id']])) {
                $roomBookings[$stay['room_id']][] = $stay['entry'];
            }
        }

        // 2. Stays without a room yet: shown on the first free room of their category.
        foreach ($stays as $stay) {
            if ($stay['room_id'] || ! $stay['room_category_id']) {
                continue;
            }

            foreach ($categories->firstWhere('id', $stay['room_category_id'])?->rooms ?? collect() as $catRoom) {
                $overlaps = collect($roomBookings[$catRoom->id] ?? [])->filter(fn (array $existing): bool => $stay['entry']['check_in'] < $existing['check_out'] && $stay['entry']['check_out'] > $existing['check_in'])->count();

                // A dorm room has room for one stay per bed.
                if ($overlaps < max(1, (int) ($bedsByRoom[$catRoom->id] ?? 0))) {
                    $roomBookings[$catRoom->id][] = $stay['entry'];
                    break;
                }
            }
        }

        $categoryData = [];
        foreach ($categories as $cat) {
            $dailyAvail = [];
            foreach ($dateStrings as $ds) {
                $bookedCount = $stays->filter(fn (array $stay): bool => $stay['room_category_id'] === $cat->id
                    && $stay['entry']['check_in'] <= $ds
                    && $stay['entry']['check_out'] > $ds)->count();
                // A blocked dorm room takes all of its beds.
                $blockedCount = $blocks->filter(fn (RoomBlock $block): bool => $cat->rooms->contains('id', $block->room_id)
                    && $block->start_date->toDateString() <= $ds
                    && $block->end_date->toDateString() > $ds)->sum(fn (RoomBlock $block): int => $bedsByRoom[$block->room_id] ?? 1);
                $avail = max(0, (int) ($capacity[$cat->id] ?? 0) - $bookedCount - $blockedCount);
                $dailyAvail[$ds] = [
                    'available' => $avail,
                    'is_sold_out' => $avail <= 0,
                ];
            }

            $roomsList = [];
            foreach ($cat->rooms as $room) {
                // Its housekeeping status (the first status until it's marked), coloured per Booking Settings.
                $status = $housekeepingStatuses->firstWhere('name', $room->housekeeping_status) ?? $housekeepingStatuses->first();

                $roomsList[] = [
                    'id' => $room->id,
                    'name' => $room->name,
                    'housekeeping' => $status ? ['name' => $status['name'], 'color' => $status['color']] : null,
                    'display_order' => $room->display_order,
                    // Dorm rooms: one lane per bed on the chart (0 for a room sold whole).
                    'beds' => (int) ($bedsByRoom[$room->id] ?? 0),
                    'room_category_id' => $cat->id,
                    'category_abbreviation' => $cat->abbreviation ?: $cat->name,
                    'bookings' => $roomBookings[$room->id] ?? [],
                    'blocks' => $roomBlocks[$room->id] ?? [],
                ];
            }

            $categoryData[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'abbreviation' => $cat->abbreviation ?: $cat->name,
                'rooms_count' => $cat->rooms_count,
                'sell_by_bed' => (bool) $cat->sell_by_bed,
                'daily_availability' => $dailyAvail,
                'rooms' => $roomsList,
            ];
        }

        $dailyTotals = [];
        $totalPropertyRooms = (int) $capacity->sum();
        foreach ($dateStrings as $ds) {
            $totalAvail = 0;
            foreach ($categoryData as $cd) {
                $totalAvail += $cd['daily_availability'][$ds]['available'];
            }
            $dailyTotals[$ds] = [
                'available' => $totalAvail,
                'booked' => max(0, $totalPropertyRooms - $totalAvail),
                'total' => $totalPropertyRooms,
            ];
        }

        return [
            'property_id' => $property->id,
            'dates' => $dates,
            'holidays' => $weekendHolidaySettings['holidays'],
            'start_date' => $startDateStr,
            'end_date' => $dates[$days - 1]['date'],
            'today' => $today->toDateString(),
            'can_go_prev' => $start->gt($today),
            'auto_refresh_minutes' => app(ApplicationSettings::class)->smartChartRefreshMinutes(),
            'daily_totals' => $dailyTotals,
            'categories' => $categoryData,
            'block_reasons' => BlockReason::query()->ordered()->get(['id', 'name'])->all(),
            // Room name → "Mark as …" menu.
            'housekeeping_statuses' => $housekeepingStatuses->map(fn (array $status): array => ['name' => $status['name'], 'color' => $status['color']])->all(),
        ];
    }
}
