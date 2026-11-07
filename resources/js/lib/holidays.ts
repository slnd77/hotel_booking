/**
 * Holidays, long weekends and events from Properties → Policies → Weekends & Holidays,
 * shown on the policy calendar, the Smart Chart and the booking form's date picker.
 */

export type HolidayType =
    | 'national_holiday'
    | 'regional_holiday'
    | 'festival'
    | 'long_weekend'
    | 'event';

/** One day's details (a whole range shares one, unless it is detailed day-wise). */
export type HolidayDetail = {
    name: string;
    type: HolidayType;
    description: string;
};

export type HolidayDay = HolidayDetail & { date: string };

export type Holiday = HolidayDetail & {
    /** Y-m-d, the property's own calendar dates. */
    from: string;
    to: string;
    day_wise: boolean;
    days: HolidayDay[];
};

/** Each type's colour and icon (an SVG path on a 24×24 box, filled). */
export const HOLIDAY_TYPES: {
    value: HolidayType;
    label: string;
    color: string;
    icon: string;
}[] = [
    {
        value: 'national_holiday',
        label: 'National Holiday',
        color: '#dc2626',
        // flag
        icon: 'M5 2a1 1 0 0 1 1 1v.6c1.6-.8 3.4-.9 5.3-.2 1.7.6 3.3.6 5.2-.2A1 1 0 0 1 18 4.1v9a1 1 0 0 1-.6.9c-2.3 1-4.4 1-6.5.3-1.7-.6-3.3-.5-4.9.4V21a1 1 0 1 1-2 0V3a1 1 0 0 1 1-1Z',
    },
    {
        value: 'regional_holiday',
        label: 'Regional Holiday',
        color: '#ea580c',
        // map pin
        icon: 'M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5Z',
    },
    {
        value: 'festival',
        label: 'Festival',
        color: '#9333ea',
        // star
        icon: 'M12 2l2.9 6.9 7.1.6-5.4 4.7 1.6 7.3L12 17.8l-6.2 3.7 1.6-7.3L2 9.5l7.1-.6L12 2Z',
    },
    {
        value: 'long_weekend',
        label: 'Long Weekend',
        color: '#ca8a04',
        // sun
        icon: 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0-5 1.2 3.2h-2.4L12 2Zm0 20-1.2-3.2h2.4L12 22ZM2 12l3.2-1.2v2.4L2 12Zm20 0-3.2 1.2v-2.4L22 12ZM4.9 4.9l3.1 1.4-1.7 1.7-1.4-3.1Zm14.2 14.2L16 17.7l1.7-1.7 1.4 3.1ZM4.9 19.1l1.4-3.1 1.7 1.7-3.1 1.4ZM19.1 4.9 17.7 8 16 6.3l3.1-1.4Z',
    },
    {
        value: 'event',
        label: 'Event',
        color: '#0284c7',
        // calendar
        icon: 'M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1V3a1 1 0 0 1 1-1Zm12 8H5v9h14v-9Zm-9 2h4v4h-4v-4Z',
    },
];

/** Types saved before the list grew map onto the current ones. */
const LEGACY_TYPES: Record<string, HolidayType> = {
    holiday: 'national_holiday',
};

export function holidayType(type: string) {
    const value = LEGACY_TYPES[type] ?? type;

    return (
        HOLIDAY_TYPES.find((option) => option.value === value) ??
        HOLIDAY_TYPES[0]
    );
}

/** Every date of a range, inclusive (Y-m-d, no timezone shifts). */
export function datesBetween(from: string, to: string): string[] {
    const dates: string[] = [];
    const [y, m, d] = from.split('-').map(Number);
    const cursor = new Date(Date.UTC(y, m - 1, d));

    while (cursor.toISOString().slice(0, 10) <= to && dates.length < 366) {
        dates.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return dates;
}

/** The holiday covering a date, with that day's own details when detailed day-wise. */
export function holidayOn(
    date: string,
    holidays: Holiday[] | null | undefined,
): HolidayDay | null {
    const holiday = (holidays ?? []).find(
        (entry) => entry.from <= date && date <= entry.to,
    );

    if (!holiday) {
        return null;
    }

    const day = holiday.day_wise
        ? holiday.days?.find((entry) => entry.date === date)
        : undefined;

    return {
        date,
        name: day?.name || holiday.name,
        type: holidayType(day?.type ?? holiday.type).value,
        description: day?.description ?? holiday.description ?? '',
    };
}

/** "Wed, 13th May 2026". */
export function formatHolidayDate(date: string, withYear = true): string {
    const [y, m, d] = date.split('-').map(Number);
    const value = new Date(Date.UTC(y, m - 1, d));
    const suffix =
        d % 10 === 1 && d !== 11
            ? 'st'
            : d % 10 === 2 && d !== 12
              ? 'nd'
              : d % 10 === 3 && d !== 13
                ? 'rd'
                : 'th';
    const weekday = value.toLocaleDateString('en-GB', {
        weekday: 'short',
        timeZone: 'UTC',
    });
    const month = value.toLocaleDateString('en-GB', {
        month: 'short',
        timeZone: 'UTC',
    });

    return `${weekday}, ${d}${suffix} ${month}${withYear ? ` ${y}` : ''}`;
}
