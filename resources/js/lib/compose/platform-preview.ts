import { replaceMentionTokens } from '@/lib/compose/mentions';
import {
    manualSegments,
    measure,
    previewSections,
} from '@/lib/compose/section-split';
import type {
    Account,
    MediaView,
    MentionPlaceholder,
    PlatformName,
} from '@/types/compose';

export type PlatformPreviewItem = {
    id: string;
    text: string;
    media: MediaView[];
    count: number;
    overLimit: boolean;
};

export type PlatformPreview = {
    platform: PlatformName;
    accountName: string;
    accountHandle: string;
    avatarUrl: string | null;
    limit: number;
    autoSplit: boolean;
    items: PlatformPreviewItem[];
};

type BuildPlatformPreviewInput = {
    account: Account;
    text: string;
    mentions: MentionPlaceholder[];
    media: MediaView[];
    excludedMediaIds: Set<string>;
    limit: number;
    autoSplit: boolean;
};

export function buildPlatformPreview({
    account,
    text,
    mentions,
    media,
    excludedMediaIds,
    limit,
    autoSplit,
}: BuildPlatformPreviewInput): PlatformPreview {
    const resolvedText = replaceMentionTokens(text, mentions, account.platform);
    const shouldSplit = account.platform !== 'linkedin' && autoSplit;
    const sections =
        account.platform === 'linkedin'
            ? [manualSegments(resolvedText).join('\n')]
            : shouldSplit
              ? previewSections(resolvedText, account.platform, limit)
              : manualSegments(resolvedText);
    const visibleMedia = media.filter((item) => !excludedMediaIds.has(item.id));

    return {
        platform: account.platform,
        accountName: account.display_name ?? account.handle,
        accountHandle: account.handle,
        avatarUrl: account.avatar_url,
        limit,
        autoSplit,
        items: sections.map((section, index) => ({
            id: `${account.platform}-preview-${index + 1}`,
            text: section,
            media: index === 0 ? visibleMedia : [],
            count: measure(section, account.platform),
            overLimit: limit > 0 && measure(section, account.platform) > limit,
        })),
    };
}
