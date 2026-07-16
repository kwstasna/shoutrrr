/**
 * TikTok's per-post publishing options, and the compliance rules TikTok's
 * content-sharing guidelines impose on any third-party app that posts.
 *
 * All pure — no React — so the rules that decide whether a post may publish are
 * testable on their own. Sibling of media-rules.ts / platform-newlines.ts.
 */

export type TikTokPrivacy =
    | 'PUBLIC_TO_EVERYONE'
    | 'MUTUAL_FOLLOW_FRIENDS'
    | 'FOLLOWER_OF_CREATOR'
    | 'SELF_ONLY';

/** Direct post goes live unattended; a draft waits in the creator's TikTok inbox. */
export type TikTokPostMode = 'direct_post' | 'inbox_draft';

/** Drives duet/stitch visibility and the photo/video wording in the labels. */
export type TikTokMediaKind = 'photo' | 'video' | 'none';

export type TikTokOptions = {
    postMode: TikTokPostMode;
    /**
     * null = the creator has not chosen yet. NEVER pre-select a value: TikTok's
     * guidelines require the privacy dropdown to start empty, and auditors check
     * for it specifically.
     */
    privacy: TikTokPrivacy | null;
    /**
     * Modelled as ALLOW — matching the visible control — rather than as TikTok's
     * `disable_*` wire fields. This is not cosmetic: the audit requires these to
     * default OFF, and "off" on a control reading "Allow comments" means
     * disable_comment = TRUE. Storing the UI polarity keeps the default honest and
     * confines the inversion to toWire(), which is tested. A `disableComment:
     * false` default would look right and ship the exact opposite.
     */
    allowComment: boolean;
    allowDuet: boolean;
    allowStitch: boolean;
    /** "Your brand" → brand_organic_toggle: the creator promoting their own business. */
    brandOrganic: boolean;
    /** "Branded content" → brand_content_toggle: a paid partnership with a third party. */
    brandContent: boolean;
    /** Photo posts carry a short title alongside the caption; video posts do not. */
    photoTitle: string;
};

export const DEFAULT_TIKTOK_OPTIONS: TikTokOptions = {
    postMode: 'direct_post',
    privacy: null,
    allowComment: false,
    allowDuet: false,
    allowStitch: false,
    brandOrganic: false,
    brandContent: false,
    photoTitle: '',
};

/** What creator_info tells us about this account, right now. */
export type TikTokCreatorInfo = {
    nickname: string;
    username: string;
    avatarUrl: string | null;
    /** Exactly what TikTok returned. The dropdown must offer these and nothing else. */
    privacyOptions: TikTokPrivacy[];
    commentDisabled: boolean;
    duetDisabled: boolean;
    stitchDisabled: boolean;
    maxVideoPostDurationSec: number;
};

export type CreatorInfoState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'ready'; info: TikTokCreatorInfo }
    | { status: 'error'; message: string };

export const TIKTOK_PRIVACY_LABELS: Record<TikTokPrivacy, string> = {
    PUBLIC_TO_EVERYONE: 'Everyone',
    MUTUAL_FOLLOW_FRIENDS: 'Friends',
    FOLLOWER_OF_CREATOR: 'Followers',
    SELF_ONLY: 'Only me',
};

/** TikTok's photo title cap, in UTF-16 runes. */
export const TIKTOK_PHOTO_TITLE_MAX = 90;

/**
 * The wire shape sent to our own server, which maps 1:1 onto the post_targets
 * columns. Note the `disable_*` polarity flip from the UI's `allow*`.
 */
export type TikTokOptionsWire = {
    post_mode: TikTokPostMode;
    privacy_level: TikTokPrivacy | null;
    disable_comment: boolean;
    disable_duet: boolean;
    disable_stitch: boolean;
    brand_content_toggle: boolean;
    brand_organic_toggle: boolean;
    photo_title: string | null;
};

/**
 * Convert the composer's state into the wire/DB shape. The single place the
 * allow→disable inversion happens.
 */
export function toWire(options: TikTokOptions): TikTokOptionsWire {
    return {
        post_mode: options.postMode,
        // A draft carries no privacy level: the creator picks it in the TikTok app
        // when they finish the post. Sending one would be inventing an answer.
        privacy_level:
            options.postMode === 'direct_post' ? options.privacy : null,
        disable_comment: !options.allowComment,
        disable_duet: !options.allowDuet,
        disable_stitch: !options.allowStitch,
        brand_content_toggle: options.brandContent,
        brand_organic_toggle: options.brandOrganic,
        photo_title: options.photoTitle === '' ? null : options.photoTitle,
    };
}

/** Rebuild composer state from what the server stored. */
export function fromWire(wire: TikTokOptionsWire): TikTokOptions {
    return {
        postMode: wire.post_mode,
        privacy: wire.privacy_level,
        allowComment: !wire.disable_comment,
        allowDuet: !wire.disable_duet,
        allowStitch: !wire.disable_stitch,
        brandContent: wire.brand_content_toggle,
        brandOrganic: wire.brand_organic_toggle,
        photoTitle: wire.photo_title ?? '',
    };
}

/**
 * Branded content may not be private — TikTok's guidelines are explicit, and the
 * API rejects it. The composer surfaces the clash rather than silently repicking
 * a privacy level, since quietly changing who can see a post is exactly the kind
 * of decision the no-pre-selection rule exists to keep with the user.
 */
export function hasBrandedPrivacyConflict(options: TikTokOptions): boolean {
    return (
        options.postMode === 'direct_post' &&
        options.brandContent &&
        options.privacy === 'SELF_ONLY'
    );
}

/** Whether the "Only me" option must be blocked, because branded content is on. */
export function isPrivacyOptionDisabled(
    option: TikTokPrivacy,
    options: TikTokOptions,
): boolean {
    return option === 'SELF_ONLY' && options.brandContent;
}

/**
 * The commercial-disclosure label TikTok requires under the toggles, or null when
 * no commercial content is declared.
 *
 * Per the guidelines: "Your brand" alone reads as promotional content; anything
 * involving branded content reads as a paid partnership, including when both are
 * on.
 */
export function commercialLabel(
    options: TikTokOptions,
    mediaKind: TikTokMediaKind,
): string | null {
    const noun = mediaKind === 'photo' ? 'photo' : 'video';

    if (options.brandContent) {
        return `Your ${noun} will be labeled as "Paid partnership".`;
    }

    if (options.brandOrganic) {
        return `Your ${noun} will be labeled as "Promotional content".`;
    }

    return null;
}

/**
 * The Music Usage Confirmation declaration, whose wording TikTok specifies per
 * toggle state — branded content must additionally reference the Branded Content
 * Policy. Returned as parts so the UI can link the policy names.
 */
export function musicDeclaration(options: TikTokOptions): {
    text: string;
    policies: Array<'branded' | 'music'>;
} {
    if (options.brandContent) {
        return {
            text: 'By posting, you agree to TikTok’s Branded Content Policy and Music Usage Confirmation.',
            policies: ['branded', 'music'],
        };
    }

    // "Your brand" only, or nothing declared: Music Usage Confirmation alone.
    return {
        text: 'By posting, you agree to TikTok’s Music Usage Confirmation.',
        policies: ['music'],
    };
}

/**
 * Why this TikTok target cannot publish yet, or null when it is ready.
 *
 * Returned as user-facing copy because it surfaces directly in the submit bar
 * next to the account's handle.
 */
export function tiktokBlockReason(
    options: TikTokOptions | undefined,
    mediaKind: TikTokMediaKind,
): string | null {
    const resolved = options ?? DEFAULT_TIKTOK_OPTIONS;

    // A draft needs nothing else: TikTok collects the rest in-app.
    if (resolved.postMode === 'inbox_draft') {
        return mediaKind === 'none' ? 'needs a video or photos' : null;
    }

    if (mediaKind === 'none') {
        return 'needs a video or photos';
    }

    if (resolved.privacy === null) {
        return 'needs a visibility';
    }

    if (hasBrandedPrivacyConflict(resolved)) {
        return 'branded content cannot be private';
    }

    return null;
}

export function isTikTokReady(
    options: TikTokOptions | undefined,
    mediaKind: TikTokMediaKind,
): boolean {
    return tiktokBlockReason(options, mediaKind) === null;
}
