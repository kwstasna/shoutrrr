import { describe, expect, it } from 'vitest';

import {
    DEFAULT_TIKTOK_OPTIONS,
    commercialLabel,
    fromWire,
    hasBrandedPrivacyConflict,
    isPrivacyOptionDisabled,
    isTikTokReady,
    musicDeclaration,
    tiktokBlockReason,
    toWire,
    type TikTokOptions,
} from '../tiktok';

function options(overrides: Partial<TikTokOptions> = {}): TikTokOptions {
    return { ...DEFAULT_TIKTOK_OPTIONS, ...overrides };
}

describe('toWire', () => {
    // The whole reason the composer stores `allow*` rather than `disable_*`.
    // TikTok's audit requires the interaction toggles to default OFF, and OFF on a
    // control labelled "Allow comments" means disable_comment: true. Getting this
    // backwards would look correct and publish the opposite of what was asked.
    it('inverts allow* into TikTok disable_* polarity', () => {
        expect(toWire(options())).toMatchObject({
            disable_comment: true,
            disable_duet: true,
            disable_stitch: true,
        });

        expect(
            toWire(
                options({
                    allowComment: true,
                    allowDuet: true,
                    allowStitch: true,
                }),
            ),
        ).toMatchObject({
            disable_comment: false,
            disable_duet: false,
            disable_stitch: false,
        });
    });

    it('sends no privacy level for an inbox draft', () => {
        const wire = toWire(
            options({ postMode: 'inbox_draft', privacy: 'PUBLIC_TO_EVERYONE' }),
        );

        expect(wire.privacy_level).toBeNull();
    });

    it('keeps the chosen privacy level for a direct post', () => {
        const wire = toWire(
            options({ postMode: 'direct_post', privacy: 'SELF_ONLY' }),
        );

        expect(wire.privacy_level).toBe('SELF_ONLY');
    });

    it('normalises an empty photo title to null', () => {
        expect(toWire(options({ photoTitle: '' })).photo_title).toBeNull();
        expect(toWire(options({ photoTitle: 'Trip' })).photo_title).toBe(
            'Trip',
        );
    });
});

describe('fromWire', () => {
    it('round-trips options unchanged', () => {
        const original = options({
            postMode: 'direct_post',
            privacy: 'FOLLOWER_OF_CREATOR',
            allowComment: true,
            allowDuet: false,
            allowStitch: true,
            brandContent: true,
            brandOrganic: false,
            photoTitle: 'Summer',
        });

        expect(fromWire(toWire(original))).toEqual(original);
    });

    it('restores defaults from a freshly created target', () => {
        expect(fromWire(toWire(DEFAULT_TIKTOK_OPTIONS))).toEqual(
            DEFAULT_TIKTOK_OPTIONS,
        );
    });
});

describe('privacy defaults', () => {
    // TikTok's guidelines require the dropdown to start with nothing selected,
    // and auditors check for it explicitly.
    it('never pre-selects a privacy level', () => {
        expect(DEFAULT_TIKTOK_OPTIONS.privacy).toBeNull();
    });

    it('leaves every interaction toggle off', () => {
        expect(DEFAULT_TIKTOK_OPTIONS.allowComment).toBe(false);
        expect(DEFAULT_TIKTOK_OPTIONS.allowDuet).toBe(false);
        expect(DEFAULT_TIKTOK_OPTIONS.allowStitch).toBe(false);
    });

    it('leaves both commercial toggles off', () => {
        expect(DEFAULT_TIKTOK_OPTIONS.brandContent).toBe(false);
        expect(DEFAULT_TIKTOK_OPTIONS.brandOrganic).toBe(false);
    });
});

describe('branded content interlock', () => {
    it('flags branded content that is set to private', () => {
        expect(
            hasBrandedPrivacyConflict(
                options({ brandContent: true, privacy: 'SELF_ONLY' }),
            ),
        ).toBe(true);
    });

    it('allows branded content at any non-private visibility', () => {
        expect(
            hasBrandedPrivacyConflict(
                options({ brandContent: true, privacy: 'PUBLIC_TO_EVERYONE' }),
            ),
        ).toBe(false);
    });

    it('ignores the clash for a draft, which carries no privacy level', () => {
        expect(
            hasBrandedPrivacyConflict(
                options({
                    postMode: 'inbox_draft',
                    brandContent: true,
                    privacy: 'SELF_ONLY',
                }),
            ),
        ).toBe(false);
    });

    it('disables only the private option, and only when branded content is on', () => {
        const branded = options({ brandContent: true });

        expect(isPrivacyOptionDisabled('SELF_ONLY', branded)).toBe(true);
        expect(isPrivacyOptionDisabled('PUBLIC_TO_EVERYONE', branded)).toBe(
            false,
        );
        expect(isPrivacyOptionDisabled('SELF_ONLY', options())).toBe(false);
    });
});

describe('commercialLabel', () => {
    it('says nothing when no commercial content is declared', () => {
        expect(commercialLabel(options(), 'video')).toBeNull();
    });

    it('labels "Your brand" alone as promotional content', () => {
        expect(commercialLabel(options({ brandOrganic: true }), 'video')).toBe(
            'Your video will be labeled as "Promotional content".',
        );
    });

    it('labels branded content as a paid partnership', () => {
        expect(commercialLabel(options({ brandContent: true }), 'video')).toBe(
            'Your video will be labeled as "Paid partnership".',
        );
    });

    it('labels both toggles as a paid partnership', () => {
        expect(
            commercialLabel(
                options({ brandContent: true, brandOrganic: true }),
                'video',
            ),
        ).toBe('Your video will be labeled as "Paid partnership".');
    });

    it('uses the right noun for a photo post', () => {
        expect(commercialLabel(options({ brandOrganic: true }), 'photo')).toBe(
            'Your photo will be labeled as "Promotional content".',
        );
    });
});

describe('musicDeclaration', () => {
    it('references Music Usage Confirmation alone by default', () => {
        expect(musicDeclaration(options()).policies).toEqual(['music']);
    });

    it('references Music Usage Confirmation alone for "Your brand"', () => {
        expect(
            musicDeclaration(options({ brandOrganic: true })).policies,
        ).toEqual(['music']);
    });

    it('also references the Branded Content Policy for branded content', () => {
        expect(
            musicDeclaration(options({ brandContent: true })).policies,
        ).toEqual(['branded', 'music']);
    });

    it('references both policies when both toggles are on', () => {
        expect(
            musicDeclaration(
                options({ brandContent: true, brandOrganic: true }),
            ).policies,
        ).toEqual(['branded', 'music']);
    });
});

describe('tiktokBlockReason', () => {
    it('blocks a direct post with no visibility chosen', () => {
        expect(tiktokBlockReason(options(), 'video')).toBe(
            'needs a visibility',
        );
    });

    it('blocks a post with no media', () => {
        expect(
            tiktokBlockReason(
                options({ privacy: 'PUBLIC_TO_EVERYONE' }),
                'none',
            ),
        ).toBe('needs a video or photos');
    });

    it('blocks branded content that is private', () => {
        expect(
            tiktokBlockReason(
                options({ brandContent: true, privacy: 'SELF_ONLY' }),
                'video',
            ),
        ).toBe('branded content cannot be private');
    });

    it('allows a draft without a visibility, since TikTok collects it in-app', () => {
        expect(
            tiktokBlockReason(options({ postMode: 'inbox_draft' }), 'video'),
        ).toBeNull();
    });

    it('still requires media for a draft', () => {
        expect(
            tiktokBlockReason(options({ postMode: 'inbox_draft' }), 'none'),
        ).toBe('needs a video or photos');
    });

    it('allows a complete direct post', () => {
        expect(
            tiktokBlockReason(
                options({ privacy: 'PUBLIC_TO_EVERYONE' }),
                'video',
            ),
        ).toBeNull();
    });

    it('treats a target with no options yet as not ready', () => {
        expect(isTikTokReady(undefined, 'video')).toBe(false);
    });
});
