import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The configured currency of the property the admin page is about (shared by
 * HandleInertiaRequests), with money formatting in it: `money(1234.5)` → "₹ 1,234.50".
 */
export function useCurrency() {
    const page = usePage();
    const code = computed(() => page.props.currency?.code ?? 'INR');
    const symbol = computed(() => page.props.currency?.symbol ?? '₹');

    /** Amount with two decimals, grouped the way the currency's region writes it. */
    function amount(value: number | string | null | undefined): string {
        const number = Number(value);

        return (Number.isFinite(number) ? number : 0).toLocaleString(
            code.value === 'INR' ? 'en-IN' : 'en-US',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 },
        );
    }

    function money(value: number | string | null | undefined): string {
        return `${symbol.value} ${amount(value)}`;
    }

    return { code, symbol, amount, money };
}
