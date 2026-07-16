import { AlertTriangle, Info } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    type CreatorInfoState,
    type TikTokMediaKind,
    TIKTOK_PRIVACY_LABELS,
    type TikTokOptions,
    type TikTokPrivacy,
    commercialLabel,
    hasBrandedPrivacyConflict,
    isPrivacyOptionDisabled,
    musicDeclaration,
} from '@/lib/compose/tiktok';
import { cn } from '@/lib/utils';

type Props = {
    options: TikTokOptions;
    creator: CreatorInfoState;
    mediaKind: TikTokMediaKind;
    readOnly?: boolean;
    onChange: (patch: Partial<TikTokOptions>) => void;
};

/**
 * The TikTok compliance panel — the analogue of StoryComposer, in that it is the
 * platform-conditional body the composer swaps in for a TikTok destination.
 *
 * Almost nothing here is a design choice. TikTok's content-sharing guidelines
 * make each of these an audit requirement for any third-party app that posts:
 * show the creator's nickname so it is unambiguous which account receives the
 * post; offer exactly the visibility options creator_info returns, with none
 * pre-selected; default every interaction and disclosure toggle to off; grey out
 * what the creator's account disallows; state the Music Usage Confirmation, whose
 * wording changes with the disclosure toggles; and say that publishing takes time.
 *
 * A draft (inbox) post collapses almost all of it: TikTok collects those choices
 * in its own app when the creator finishes the post, so asking here would be
 * inventing answers we then could not send.
 */
export function TikTokOptionsPanel({
    options,
    creator,
    mediaKind,
    readOnly = false,
    onChange,
}: Props) {
    const isDraft = options.postMode === 'inbox_draft';

    return (
        <div className="mx-4 mb-3 rounded-xl border border-border bg-muted/20 sm:mx-[26px]">
            <CreatorHeader creator={creator} />

            {isDraft ? (
                <p className="px-3 pb-3 text-[12px] leading-5 text-muted-foreground">
                    This lands as a draft in the account&apos;s TikTok inbox.
                    Whoever owns the account finishes it there — choosing
                    visibility, comments and any branded-content settings — and
                    publishes it themselves. TikTok holds at most 5 pending
                    drafts per account per day.
                </p>
            ) : (
                <div className="space-y-3.5 px-3 pb-3">
                    <PrivacySelect
                        options={options}
                        creator={creator}
                        readOnly={readOnly}
                        onChange={onChange}
                    />

                    <InteractionToggles
                        options={options}
                        creator={creator}
                        mediaKind={mediaKind}
                        readOnly={readOnly}
                        onChange={onChange}
                    />

                    <CommercialToggles
                        options={options}
                        mediaKind={mediaKind}
                        readOnly={readOnly}
                        onChange={onChange}
                    />

                    {hasBrandedPrivacyConflict(options) && (
                        <Alert variant="destructive">
                            <AlertTriangle />
                            <AlertDescription>
                                TikTok doesn&apos;t allow branded content to be
                                private. Pick a different visibility, or turn
                                off &ldquo;Branded content&rdquo;.
                            </AlertDescription>
                        </Alert>
                    )}

                    <p className="text-[11px] leading-4 text-muted-foreground">
                        {musicDeclaration(options).text}
                    </p>
                </div>
            )}

            <p className="flex items-start gap-1.5 border-t border-border px-3 py-2 text-[11px] leading-4 text-muted-foreground">
                <Info className="mt-px size-3 shrink-0" aria-hidden />
                {/* Required: TikTok asks that users be told publishing is not instant. */}
                <span>
                    TikTok needs a few minutes to process
                    {mediaKind === 'photo' ? ' photos' : ' a video'} before it
                    appears on the profile.
                </span>
            </p>
        </div>
    );
}

/**
 * The creator's nickname and avatar. Required so the user can see exactly which
 * TikTok account is about to receive the post.
 */
function CreatorHeader({ creator }: { creator: CreatorInfoState }) {
    if (creator.status === 'loading' || creator.status === 'idle') {
        return (
            <div className="flex items-center gap-2 px-3 py-2.5">
                <Skeleton className="size-6 rounded-full" />
                <Skeleton className="h-3 w-32" />
            </div>
        );
    }

    if (creator.status === 'error') {
        return (
            <div className="px-3 pt-3 pb-1">
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertDescription>{creator.message}</AlertDescription>
                </Alert>
            </div>
        );
    }

    const { info } = creator;

    return (
        <div className="flex items-center gap-2 px-3 py-2.5">
            <Avatar className="size-6">
                <AvatarImage src={info.avatarUrl ?? undefined} />
                <AvatarFallback className="text-[9px] font-semibold">
                    {info.nickname.slice(0, 2).toUpperCase()}
                </AvatarFallback>
            </Avatar>
            <p className="min-w-0 truncate text-[12.5px] font-medium">
                Posting to{' '}
                <span className="font-semibold">{info.nickname}</span>
            </p>
        </div>
    );
}

/**
 * The visibility dropdown.
 *
 * Two rules make this different from a normal select: the options must be
 * exactly what creator_info returned for this creator (a private account cannot
 * post publicly, and sending a level TikTok didn't offer fails with
 * privacy_level_option_mismatch), and it must start with nothing selected.
 */
function PrivacySelect({
    options,
    creator,
    readOnly,
    onChange,
}: {
    options: TikTokOptions;
    creator: CreatorInfoState;
    readOnly: boolean;
    onChange: (patch: Partial<TikTokOptions>) => void;
}) {
    // Exactly what TikTok returned, in TikTok's order — never a hardcoded list.
    const available: TikTokPrivacy[] =
        creator.status === 'ready' ? creator.info.privacyOptions : [];

    const items = available.map((option) => ({
        value: option,
        label: TIKTOK_PRIVACY_LABELS[option],
    }));

    return (
        <div className="space-y-1.5">
            <Label className="text-[12px] font-medium">Who can see this</Label>

            <Select
                items={items}
                value={options.privacy ?? undefined}
                disabled={readOnly || creator.status !== 'ready'}
                onValueChange={(value) =>
                    onChange({ privacy: value as TikTokPrivacy })
                }
            >
                <SelectTrigger className="w-full" size="sm">
                    {/* No pre-selected value — the placeholder stands until the
                        user chooses, which is the audit requirement. */}
                    <SelectValue placeholder="Select who can view this post" />
                </SelectTrigger>
                <SelectContent>
                    {items.map((item) => (
                        <SelectItem
                            key={item.value}
                            value={item.value}
                            // Branded content cannot be private, so "Only me" is
                            // blocked rather than silently swapped underneath.
                            disabled={isPrivacyOptionDisabled(
                                item.value,
                                options,
                            )}
                        >
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {options.privacy === null && creator.status === 'ready' && (
                <p className="text-[11px] leading-4 text-muted-foreground">
                    TikTok requires you to choose this yourself before posting.
                </p>
            )}
        </div>
    );
}

/**
 * Comment / Duet / Stitch. Modelled as "Allow …" to match TikTok's own wording,
 * all defaulting to off, and disabled where the creator's account already turns
 * them off.
 */
function InteractionToggles({
    options,
    creator,
    mediaKind,
    readOnly,
    onChange,
}: {
    options: TikTokOptions;
    creator: CreatorInfoState;
    mediaKind: TikTokMediaKind;
    readOnly: boolean;
    onChange: (patch: Partial<TikTokOptions>) => void;
}) {
    const info = creator.status === 'ready' ? creator.info : null;

    // A photo post has no Duet or Stitch — they are video-only features, so
    // showing them would be offering settings TikTok will not accept.
    const isPhoto = mediaKind === 'photo';

    const rows: Array<{
        key: 'allowComment' | 'allowDuet' | 'allowStitch';
        label: string;
        blocked: boolean;
        hidden: boolean;
    }> = [
        {
            key: 'allowComment',
            label: 'Allow comments',
            blocked: info?.commentDisabled ?? false,
            hidden: false,
        },
        {
            key: 'allowDuet',
            label: 'Allow Duet',
            blocked: info?.duetDisabled ?? false,
            hidden: isPhoto,
        },
        {
            key: 'allowStitch',
            label: 'Allow Stitch',
            blocked: info?.stitchDisabled ?? false,
            hidden: isPhoto,
        },
    ];

    return (
        <div className="space-y-1.5">
            <Label className="text-[12px] font-medium">Interactions</Label>

            <div className="flex flex-wrap gap-x-4 gap-y-2">
                {rows
                    .filter((row) => !row.hidden)
                    .map((row) => {
                        const disabled =
                            readOnly || row.blocked || info === null;

                        return (
                            <label
                                key={row.key}
                                className={cn(
                                    'flex items-center gap-2 text-[12px]',
                                    disabled
                                        ? 'cursor-not-allowed text-muted-foreground opacity-60'
                                        : 'cursor-pointer',
                                )}
                            >
                                <Checkbox
                                    checked={options[row.key]}
                                    disabled={disabled}
                                    onCheckedChange={(checked) =>
                                        onChange({
                                            [row.key]: checked === true,
                                        })
                                    }
                                />
                                {row.label}
                                {row.blocked && (
                                    <span className="text-[11px] text-muted-foreground">
                                        (off for this account)
                                    </span>
                                )}
                            </label>
                        );
                    })}
            </div>
        </div>
    );
}

/**
 * The commercial-content disclosure. Off by default; when either box is ticked
 * TikTok requires the specific label text to be shown back to the user.
 */
function CommercialToggles({
    options,
    mediaKind,
    readOnly,
    onChange,
}: {
    options: TikTokOptions;
    mediaKind: TikTokMediaKind;
    readOnly: boolean;
    onChange: (patch: Partial<TikTokOptions>) => void;
}) {
    const label = commercialLabel(options, mediaKind);

    return (
        <div className="space-y-1.5">
            <Label className="text-[12px] font-medium">
                Commercial content disclosure
            </Label>

            <div className="flex flex-wrap gap-x-4 gap-y-2">
                <label
                    className={cn(
                        'flex items-center gap-2 text-[12px]',
                        readOnly
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer',
                    )}
                >
                    <Checkbox
                        checked={options.brandOrganic}
                        disabled={readOnly}
                        onCheckedChange={(checked) =>
                            onChange({ brandOrganic: checked === true })
                        }
                    />
                    Your brand
                </label>

                <label
                    className={cn(
                        'flex items-center gap-2 text-[12px]',
                        readOnly
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer',
                    )}
                >
                    <Checkbox
                        checked={options.brandContent}
                        disabled={readOnly}
                        onCheckedChange={(checked) =>
                            onChange({ brandContent: checked === true })
                        }
                    />
                    Branded content
                </label>
            </div>

            {label && (
                <p className="text-[11px] leading-4 text-foreground">{label}</p>
            )}
        </div>
    );
}
