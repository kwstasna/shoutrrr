import { router } from '@inertiajs/react';

export function invalidatePostCaches() {
    router.flushAll();
}
