import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, type Ref } from 'vue';

/**
 * Times travel as UTC (ISO 8601) between the server and the UI; they become local only here,
 * at render time, in the timezone of the property the page is about (shared by
 * HandleInertiaRequests) — not the timezone of the device.
 */

const FALLBACK_TIME_ZONE = 'Asia/Kolkata';

/**
 * A calendar date (YYYY-MM-DD, e.g. a stay's check-in day) as "28/09/2026". It's already the
 * property's own day, so no timezone conversion applies.
 */
export function calendarDate(value: string | null | undefined): string {
    const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})/);

    return match ? `${match[3]}/${match[2]}/${match[1]}` : '—';
}

/**
 * Old names some browsers still list (Chrome lists "Asia/Calcutta"), mapped to the current
 * IANA names the server stores and validates.
 */
const LEGACY_TIME_ZONES: Record<string, string> = {
    'Asia/Calcutta': 'Asia/Kolkata',
    'Asia/Katmandu': 'Asia/Kathmandu',
    'Asia/Rangoon': 'Asia/Yangon',
    'Asia/Saigon': 'Asia/Ho_Chi_Minh',
    'Atlantic/Faeroe': 'Atlantic/Faroe',
    'Europe/Kiev': 'Europe/Kyiv',
    'Pacific/Enderbury': 'Pacific/Kanton',
};

/**
 * Every IANA timezone the browser knows, for pickers, under current names — always including
 * the given (saved) one, so a required picker never starts empty.
 */
export function timeZoneOptions(current?: string | null): string[] {
    const supportedValuesOf = (
        Intl as unknown as { supportedValuesOf?: (key: string) => string[] }
    ).supportedValuesOf;
    const zones = (
        supportedValuesOf
            ? supportedValuesOf('timeZone')
            : [FALLBACK_TIME_ZONE, 'UTC']
    ).map((zone) => LEGACY_TIME_ZONES[zone] ?? zone);

    return [
        ...new Set([
            ...zones,
            FALLBACK_TIME_ZONE,
            'UTC',
            ...(current ? [current] : []),
        ]),
    ].sort();
}

/**
 * The timezone picker's options, for a page that is also rendered on the server: the browser's
 * list is read once mounted, since Node's differs from Chrome's and the hydrating browser must
 * find the markup the server rendered. Until then, only the saved zone and the fallbacks.
 */
export function useTimeZoneOptions(current?: string | null): Ref<string[]> {
    const zones = ref(
        [
            ...new Set([
                FALLBACK_TIME_ZONE,
                'UTC',
                ...(current ? [current] : []),
            ]),
        ].sort(),
    );

    onMounted(() => (zones.value = timeZoneOptions(current)));

    return zones;
}

/** The wall-clock parts of an instant in a timezone. */
function wallClock(
    date: Date,
    timeZone: string,
): {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    second: number;
} {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        })
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, Number(part.value)]),
    );

    return {
        year: parts.year,
        month: parts.month,
        day: parts.day,
        hour: parts.hour,
        minute: parts.minute,
        second: parts.second,
    };
}

/** How far the timezone's wall clock is ahead of UTC at that instant, in ms. */
function offsetAt(date: Date, timeZone: string): number {
    const wall = wallClock(date, timeZone);

    return (
        Date.UTC(
            wall.year,
            wall.month - 1,
            wall.day,
            wall.hour,
            wall.minute,
            wall.second,
        ) -
        (date.getTime() - date.getMilliseconds())
    );
}

const pad = (value: number): string => String(value).padStart(2, '0');

export function usePropertyTime() {
    const page = usePage();
    const timeZone = computed(() => page.props.timezone ?? FALLBACK_TIME_ZONE);

    function toDate(value: string | null | undefined): Date | null {
        if (!value) {
            return null;
        }

        const date = new Date(value);

        return Number.isNaN(date.getTime()) ? null : date;
    }

    /** "29/09/2026 2:14 pm" in the property's time. */
    function dateTime(
        value: string | null | undefined,
        withSeconds = false,
    ): string {
        const date = toDate(value);

        if (!date) {
            return '—';
        }

        const wall = wallClock(date, timeZone.value);
        const hour12 = wall.hour % 12 || 12;

        return `${pad(wall.day)}/${pad(wall.month)}/${wall.year} ${hour12}:${pad(wall.minute)}${withSeconds ? `:${pad(wall.second)}` : ''} ${wall.hour < 12 ? 'am' : 'pm'}`;
    }

    /** "29/09/2026" — the property's calendar date of an instant. */
    function date(value: string | null | undefined): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '—';
        }

        const wall = wallClock(parsed, timeZone.value);

        return `${pad(wall.day)}/${pad(wall.month)}/${wall.year}`;
    }

    /** An instant as a datetime-local input value (YYYY-MM-DDTHH:mm) in the property's time; default now. */
    function toInput(value?: string | null): string {
        const wall = wallClock(toDate(value) ?? new Date(), timeZone.value);

        return `${wall.year}-${pad(wall.month)}-${pad(wall.day)}T${pad(wall.hour)}:${pad(wall.minute)}`;
    }

    /** A datetime-local value, read as the property's wall-clock time, as a UTC ISO timestamp (null when empty). */
    function fromInput(value: string | null | undefined): string | null {
        const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);

        if (!match) {
            return null;
        }

        const [, year, month, day, hour, minute] = match.map(Number);
        const asUtc = Date.UTC(year, month - 1, day, hour, minute);
        // Two passes settle the offset across a DST change.
        let instant = asUtc - offsetAt(new Date(asUtc), timeZone.value);
        instant = asUtc - offsetAt(new Date(instant), timeZone.value);

        return new Date(instant).toISOString();
    }

    /** The property's business date today (YYYY-MM-DD). */
    function today(): string {
        return toInput().slice(0, 10);
    }

    return { timeZone, dateTime, date, toInput, fromInput, today };
}
