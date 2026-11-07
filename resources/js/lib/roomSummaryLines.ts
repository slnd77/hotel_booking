export type RoomSummaryInput = {
    category: string;
    mealPlan: string | null;
    adults: number;
};

/**
 * One line per room category and meal plan, e.g. "2 - Single Room - EP X 3"
 * (rooms - category - meal plan X adults), in the order rooms were added.
 */
export function roomSummaryLines(rooms: RoomSummaryInput[]): string[] {
    const groups = new Map<
        string,
        { count: number; category: string; mealPlan: string; adults: number }
    >();

    rooms.forEach((room) => {
        const mealPlan = room.mealPlan || '—';
        const key = `${room.category}|${mealPlan}`;
        const group = groups.get(key) ?? {
            count: 0,
            category: room.category,
            mealPlan,
            adults: 0,
        };

        group.count += 1;
        group.adults += room.adults || 0;
        groups.set(key, group);
    });

    return [...groups.values()].map(
        (group) =>
            `${group.count} - ${group.category} - ${group.mealPlan} X ${group.adults}`,
    );
}
