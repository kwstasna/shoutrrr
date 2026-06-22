import { PlatformGlyph } from '@/components/common/platform-glyph';
import { cn } from '@/lib/utils';
import { BASE_TAB, type Account } from '@/types/compose';

const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

type PlatformTabsProps = {
    /** One tab per destination account. Empty → a single generic "Post" tab. */
    accounts: Account[];
    /** Active tab: an account id, or `BASE_TAB`. */
    activeTab: string;
    onChange: (tab: string) => void;
    /** Per-account count chip text, e.g. "4" (section count) or "✓" / "!". */
    chipFor: (accountId: string) => string;
    /** Per-account severity driving the `after:` underline tint. */
    stateFor: (accountId: string) => 'ok' | 'warn' | 'over';
    /** Returns true when the given account has a content override active. */
    hasOverride: (accountId: string) => boolean;
};

const TAB_CLASS = cn(
    'group/tab relative flex shrink-0 items-center gap-2 rounded-t-md px-3 pt-2 pb-2.5 text-[12.5px] font-medium tracking-[-0.005em] transition-colors',
    'text-muted-foreground hover:bg-muted hover:text-foreground',
    'data-[active=true]:text-foreground',
    'after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-t-sm after:bg-foreground after:opacity-0 data-[active=true]:after:opacity-100',
    'data-[state=over]:after:bg-destructive data-[state=warn]:after:bg-amber-500',
);

export default function PlatformTabs({
    accounts,
    activeTab,
    onChange,
    chipFor,
    stateFor,
    hasOverride,
}: PlatformTabsProps) {
    // No accounts → one generic, platform-less tab that edits the base text.
    if (accounts.length === 0) {
        return (
            <div
                className="flex min-w-0 flex-1 items-end gap-0.5 overflow-x-auto overflow-y-hidden"
                role="tablist"
                aria-label="Post"
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={activeTab === BASE_TAB}
                    data-active={activeTab === BASE_TAB}
                    data-state="ok"
                    onClick={() => onChange(BASE_TAB)}
                    className={TAB_CLASS}
                >
                    <span className="grid size-[18px] place-items-center rounded-[5px] bg-foreground text-background">
                        <span className="size-1 rounded-full bg-background" />
                    </span>
                    <span>Post</span>
                </button>
            </div>
        );
    }

    return (
        <div
            className="flex min-w-0 flex-1 items-end gap-0.5 overflow-x-auto overflow-y-hidden"
            role="tablist"
            aria-label="Accounts"
        >
            {accounts.map((account) => {
                const isActive = account.id === activeTab;
                const severity = stateFor(account.id);
                const overridden = hasOverride(account.id);
                const brand =
                    PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;

                return (
                    <button
                        key={account.id}
                        type="button"
                        role="tab"
                        aria-selected={isActive}
                        data-active={isActive}
                        data-state={severity}
                        onClick={() => onChange(account.id)}
                        title={account.display_name ?? account.handle}
                        className={TAB_CLASS}
                    >
                        <span
                            className={cn(
                                'grid size-[18px] place-items-center rounded-[5px]',
                                brand.tile,
                                brand.glyph,
                            )}
                        >
                            <PlatformGlyph
                                platform={account.platform}
                                size={11}
                                className={brand.glyph}
                            />
                        </span>
                        <span>{account.handle}</span>
                        {overridden && (
                            <span
                                className="size-1.5 rounded-full bg-primary"
                                aria-label="override active"
                                title="Override active on this account"
                            />
                        )}
                        <span className="font-mono text-[11px] text-muted-foreground tabular-nums">
                            {chipFor(account.id)}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
