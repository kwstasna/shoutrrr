import { Layers } from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Account, AccountSet, Destination } from '@/types/compose';

/** Per-platform brand accent for the glyph badge (mirrors the accounts page). */
const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

/**
 * Avatar with the platform logo tucked into the bottom-right corner. The badge
 * stays inside the avatar bounds so it never gets clipped when this visual is
 * mirrored into the (overflow-hidden) trigger.
 */
function AccountVisual({ account }: { account: Account }) {
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;

    return (
        <span className="relative inline-grid shrink-0">
            <Avatar className="size-5">
                <AvatarImage
                    src={account.avatar_url ?? undefined}
                    alt={account.handle}
                />
                <AvatarFallback className="text-[9px] font-medium">
                    {account.handle.replace(/^@/, '').slice(0, 1).toUpperCase()}
                </AvatarFallback>
            </Avatar>
            <span
                className={cn(
                    'absolute right-0 bottom-0 grid size-2.5 place-items-center rounded-full ring-2 ring-popover',
                    brand.tile,
                    brand.glyph,
                )}
            >
                {/* size-* class is required: the Select CSS force-sizes any
                    class-less svg to size-4 (16px). */}
                <PlatformGlyph
                    platform={account.platform}
                    className={cn('size-1.5', brand.glyph)}
                />
            </span>
        </span>
    );
}

/** Leading icon for an account set. */
function SetVisual() {
    return (
        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
            <Layers className="size-3" />
        </span>
    );
}

type DestinationSelectorProps = {
    accounts: Account[];
    sets: AccountSet[];
    destination: Destination;
    onChange: (destination: Destination) => void;
    /** Lock the selector (read-only post). */
    disabled?: boolean;
};

function toValue(destination: Destination): string {
    if (destination.kind === 'all') {
        return 'all';
    }

    return `${destination.kind}:${destination.id}`;
}

export default function DestinationSelector({
    accounts,
    sets,
    destination,
    onChange,
    disabled = false,
}: DestinationSelectorProps) {
    function handleChange(value: string) {
        if (value === 'all') {
            onChange({ kind: 'all' });

            return;
        }
        const [kind, id] = value.split(':');
        onChange(
            kind === 'set' ? { kind: 'set', id } : { kind: 'account', id },
        );
    }

    return (
        <Select
            value={toValue(destination)}
            onValueChange={handleChange}
            disabled={disabled}
        >
            <SelectTrigger
                size="sm"
                aria-label="Post destination"
                className="max-w-[150px] gap-1 rounded-md border-transparent bg-transparent px-2 text-[12px] text-muted-foreground hover:bg-muted hover:text-foreground data-[size=sm]:h-7"
            >
                <SelectValue placeholder="Choose where to post" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectLabel>Sets</SelectLabel>
                    <SelectItem value="all">
                        <SetVisual />
                        All accounts
                    </SelectItem>
                    {sets.map((set) => (
                        <SelectItem key={set.id} value={`set:${set.id}`}>
                            <SetVisual />
                            {set.name}
                        </SelectItem>
                    ))}
                </SelectGroup>
                {accounts.length > 0 && (
                    <SelectGroup>
                        <SelectLabel>Accounts</SelectLabel>
                        {accounts.map((account) => (
                            <SelectItem
                                key={account.id}
                                value={`account:${account.id}`}
                            >
                                <AccountVisual account={account} />
                                {account.handle}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                )}
            </SelectContent>
        </Select>
    );
}
