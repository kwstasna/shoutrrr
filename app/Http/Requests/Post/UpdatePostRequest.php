<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Enums\PostFormat;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_text' => ['sometimes', 'nullable', 'string'],
            'segments' => ['present', 'array'],
            'segments.*' => ['nullable', 'string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            'mentions.*.handles.x' => ['nullable', 'string'],
            'mentions.*.handles.bluesky' => ['nullable', 'string'],
            'mentions.*.handles.linkedin' => ['nullable', 'string'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.format' => ['sometimes', Rule::enum(PostFormat::class)],
            // TikTok's per-post options. Shape only — everything is nullable
            // because the composer autosaves on every keystroke, long before the
            // creator has picked a visibility, and because TikTok's guidelines
            // forbid pre-selecting one for them. Completeness is enforced at
            // publish (TikTokConnector re-checks against live creator_info), not
            // here, where it would reject an in-progress draft.
            'targets.*.tiktok_options' => ['nullable', 'array'],
            'targets.*.tiktok_options.post_mode' => ['nullable', Rule::enum(TikTokPostMode::class)],
            'targets.*.tiktok_options.privacy_level' => ['nullable', Rule::enum(TikTokPrivacyLevel::class)],
            'targets.*.tiktok_options.disable_comment' => ['boolean'],
            'targets.*.tiktok_options.disable_duet' => ['boolean'],
            'targets.*.tiktok_options.disable_stitch' => ['boolean'],
            'targets.*.tiktok_options.brand_content_toggle' => ['boolean'],
            'targets.*.tiktok_options.brand_organic_toggle' => ['boolean'],
            'targets.*.tiktok_options.photo_title' => ['nullable', 'string', 'max:150'],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.segments' => ['array'],
            'targets.*.content_override.segments.*' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
