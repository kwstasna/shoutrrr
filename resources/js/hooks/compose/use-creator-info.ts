import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import TikTokCreatorInfoController from '@/actions/App/Http/Controllers/ConnectedAccounts/TikTokCreatorInfoController';
import type { CreatorInfoState, TikTokCreatorInfo } from '@/lib/compose/tiktok';

type CreatorInfoPayload =
    | { status: 'ready'; info: TikTokCreatorInfo }
    | { status: 'error'; message: string };

/**
 * Fetch TikTok's creator info for the active account.
 *
 * TikTok's guidelines require this to be queried before the posting UI renders,
 * rather than cached or hardcoded — the creator's allowed privacy levels, their
 * interaction settings and their max video duration all change independently of
 * us, and the avatar URL expires after about two hours.
 *
 * Fires on account change only (the sibling of useNextSlot's fetch-on-activate),
 * never on keystrokes: TikTok allows 20 calls a minute per account and the
 * composer autosaves constantly.
 *
 * Pass `accountId: null` for any non-TikTok destination to skip the request.
 */
export function useCreatorInfo(accountId: string | null): CreatorInfoState {
    const http = useHttp<Record<string, never>, CreatorInfoPayload>({});
    const [state, setState] = useState<CreatorInfoState>({ status: 'idle' });

    useEffect(() => {
        if (accountId === null) {
            setState({ status: 'idle' });

            return;
        }

        let cancelled = false;
        setState({ status: 'loading' });

        // Any transport failure has to land on a terminal state, or the panel
        // would sit on its skeleton forever and the user could never post.
        const failTerminal = () => {
            if (cancelled) {
                return;
            }
            setState({
                status: 'error',
                message:
                    "Couldn't load this TikTok account's posting settings. Reload to try again.",
            });
        };

        void http.get(TikTokCreatorInfoController.url({ account: accountId }), {
            onSuccess: (data: CreatorInfoPayload) => {
                if (cancelled) {
                    return;
                }

                setState(
                    data.status === 'ready'
                        ? { status: 'ready', info: data.info }
                        : { status: 'error', message: data.message },
                );
            },
            onHttpException: failTerminal,
            onNetworkError: failTerminal,
        });

        return () => {
            cancelled = true;
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- `http` is a stable ref from useHttp; including it re-fires the fetch every render
    }, [accountId]);

    return state;
}
