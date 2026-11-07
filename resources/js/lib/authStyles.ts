/** Field, label and button classes shared by the log-in, create-account and password pages. */
export const authLabel =
    'text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';

export function authInput(invalid: boolean): string {
    return [
        'h-[46px] w-full rounded-lg border bg-white px-3.5 text-[15px] text-[#1b1b18] outline-none transition',
        'focus:border-[#f53003] focus:ring-3 focus:ring-[#f53003]/20 dark:bg-[#161615] dark:text-[#EDEDEC] dark:focus:border-[#FF4433] dark:focus:ring-[#FF4433]/20',
        invalid
            ? 'border-red-600 dark:border-red-500'
            : 'border-[#e3e3e0] dark:border-[#3E3E3A]',
    ].join(' ');
}

export const authError = 'm-0 text-[13px] text-red-600 dark:text-red-500';

export const authSubmit =
    'font-display mt-1 flex h-[46px] w-full items-center justify-center gap-2.5 rounded-lg bg-[#1b1b18] text-[15px] font-medium text-white transition hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f53003] disabled:cursor-wait disabled:opacity-75 dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white';

export const authSecondary =
    'font-display flex h-[46px] w-full items-center justify-center gap-2.5 rounded-lg bg-white text-[15px] font-medium text-[#1b1b18] no-underline shadow-[inset_0_0_0_1px_rgba(26,26,0,0.16)] transition hover:bg-[#f5f5f4] dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0_0_0_1px_#fffaed2d] dark:hover:bg-[#1C1C1A]';

export const authLink =
    'font-semibold text-[#f53003] hover:underline hover:underline-offset-[3px] dark:text-[#FF4433]';
