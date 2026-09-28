<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Services\PermissionGate;
use App\Services\RoomInventory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomBlockController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $this->validateRange($request, $property, [
            'block_reason_id' => ['required', 'integer', 'exists:block_reasons,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        app(PermissionGate::class)->roomBlock($request->user(), $property, 'block', $data['start_date']);
        $this->ensureRoomsAreFree($data['room_ids'], $data['start_date'], $data['end_date'], 'room_ids');

        DB::transaction(function () use ($data, $property, $request): void {
            foreach ($data['room_ids'] as $roomId) {
                RoomBlock::create([
                    'property_id' => $property->id,
                    'room_id' => $roomId,
                    'user_id' => $request->user()->id,
                    'block_reason_id' => $request->integer('block_reason_id'),
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'name' => $data['name'] ?? null,
                    'remarks' => $request->string('remarks')->toString(),
                ]);
            }
        });

        return response()->json(['blocked' => count($data['room_ids'])], 201);
    }

    /**
     * Moves a block to another room and/or start date on the smart chart,
     * keeping its length.
     */
    public function move(Request $request, Property $property, RoomBlock $roomBlock): JsonResponse
    {
        abort_unless($roomBlock->property_id === $property->id, 404);

        $data = $request->validate([
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->where(fn (QueryBuilder $query) => $query->whereIn('room_category_id', $property->roomCategories()->select('id')))],
            'start_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $currentStart = $roomBlock->start_date->toDateString();
        app(PermissionGate::class)->roomBlock($request->user(), $property, 'block', min($currentStart, $data['start_date']));
        $nights = (int) $roomBlock->start_date->diffInDays($roomBlock->end_date);
        $endDate = CarbonImmutable::parse($data['start_date'])->addDays($nights)->toDateString();

        if ($data['start_date'] !== $currentStart && $data['start_date'] < $property->today()) {
            throw ValidationException::withMessages(['start_date' => 'A block cannot be moved into the past.']);
        }

        $this->ensureRoomsAreFree([(int) $data['room_id']], $data['start_date'], $endDate, 'room_id', $roomBlock->id);

        $roomBlock->update(['room_id' => $data['room_id'], 'start_date' => $data['start_date'], 'end_date' => $endDate]);

        return response()->json(['moved' => true]);
    }

    /**
     * @param  list<int>  $roomIds
     *
     * @throws ValidationException
     */
    private function ensureRoomsAreFree(array $roomIds, string $startDate, string $endDate, string $errorKey, ?int $ignoreBlockId = null): void
    {
        $bookedRoomIds = app(RoomInventory::class)->bookedRoomIds($roomIds, $startDate, $endDate);

        $blockedRoomIds = RoomBlock::query()
            ->whereIn('room_id', $roomIds)
            ->when($ignoreBlockId, fn ($query) => $query->whereKeyNot($ignoreBlockId))
            ->overlapping($startDate, $endDate)
            ->pluck('room_id');

        $unavailableRoomIds = $bookedRoomIds->merge($blockedRoomIds)->unique();

        if ($unavailableRoomIds->isNotEmpty()) {
            $names = Room::query()->whereIn('id', $unavailableRoomIds)->orderBy('name')->pluck('name')->implode(', ');

            throw ValidationException::withMessages([
                $errorKey => "These rooms are already booked or blocked for the selected dates: {$names}.",
            ]);
        }
    }

    /**
     * Releases the selected nights from any blocks on the selected rooms,
     * trimming or splitting blocks that extend beyond the range.
     */
    public function unblock(Request $request, Property $property): JsonResponse
    {
        $data = $this->validateRange($request, $property);
        app(PermissionGate::class)->roomBlock($request->user(), $property, 'unblock', $data['start_date']);

        $blocks = RoomBlock::query()
            ->where('property_id', $property->id)
            ->whereIn('room_id', $data['room_ids'])
            ->overlapping($data['start_date'], $data['end_date'])
            ->get();

        if ($blocks->isEmpty()) {
            throw ValidationException::withMessages([
                'room_ids' => 'None of the selected rooms are blocked for the selected dates.',
            ]);
        }

        DB::transaction(function () use ($blocks, $data): void {
            foreach ($blocks as $block) {
                $startsBefore = $block->start_date->toDateString() < $data['start_date'];
                $endsAfter = $block->end_date->toDateString() > $data['end_date'];

                if ($startsBefore && $endsAfter) {
                    $block->replicate()->fill(['start_date' => $data['end_date']])->save();
                    $block->update(['end_date' => $data['start_date']]);
                } elseif ($startsBefore) {
                    $block->update(['end_date' => $data['start_date']]);
                } elseif ($endsAfter) {
                    $block->update(['start_date' => $data['end_date']]);
                } else {
                    $block->delete();
                }
            }
        });

        return response()->json(['unblocked' => $blocks->pluck('room_id')->unique()->count()]);
    }

    /**
     * @param  array<string, mixed>  $extraRules
     * @return array{start_date: string, end_date: string, room_ids: list<int>, block_reason_id?: int, name?: string|null, remarks?: string}
     */
    private function validateRange(Request $request, Property $property, array $extraRules = []): array
    {
        return $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('rooms', 'id')->where(fn (QueryBuilder $query) => $query->whereIn(
                    'room_category_id',
                    $property->roomCategories()->select('id'),
                )),
            ],
            ...$extraRules,
        ]);
    }
}
