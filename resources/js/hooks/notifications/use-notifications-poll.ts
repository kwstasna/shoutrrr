import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const POLL_MS = 120_000;

/**
 * Keeps the shared `notifications` prop fresh by reloading it on a fixed
 * interval. Navigation and Inertia actions already refresh shared props, so this
 * interval only covers a page left open and idle.
 */
export function useNotificationsPoll(): void {
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['notifications'] });
        }, POLL_MS);

        return () => clearInterval(id);
    }, []);
}
