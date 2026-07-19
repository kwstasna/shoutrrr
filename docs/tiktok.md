# TikTok integration

TikTok is wired in as the eighth platform: OAuth connect, the Content Posting API
(direct post + inbox draft, video + photo carousel), the compliance UI TikTok's
audit requires, and a 9:16 composer preview. Metrics and engagement are
intentionally `unsupported` for now (see [Not implemented](#not-implemented)).

This file maps every endpoint, field, scope, limit, and error code the code uses
to the TikTok documentation it came from, so a reviewer can spot-check the
integration against the source, and flags the few things that could only be
confirmed against the live API.

## Setup

Environment (`.env`):

| Var | Notes |
| --- | --- |
| `TIKTOK_CLIENT_KEY` | TikTok's developer portal labels this **"Client key"**. It is stored under `services.tiktok.client_id` on purpose — `Platform::isConfigured()` reads `{configKey}.client_id` generically, so naming the config key `client_key` would leave TikTok permanently "Not set up". `TikTokOAuthConnector` maps it back to `client_key` on the wire. |
| `TIKTOK_CLIENT_SECRET` | Client secret from the portal. |
| `TIKTOK_REDIRECT_URI` | Must match a redirect URI registered in the portal **exactly** (TikTok rejects redirect URIs with query params). Falls back to `route('accounts.tiktok.callback')` when unset. |

TikTok developer portal:

- **Products**: enable *Login Kit* and *Content Posting API*.
- **Scopes**: `user.info.basic`, `user.info.profile`, `video.publish`, `video.upload` (see [Scopes](#scopes)).
- **Redirect URI**: register the exact value of `TIKTOK_REDIRECT_URI`.
- **URL Properties**: verify the domain of `APP_URL`. Photo carousels are
  `PULL_FROM_URL` only — TikTok fetches each photo from a URL on our domain, and
  an unverified domain fails with `url_ownership_unverified`.
  <https://developers.tiktok.com/doc/content-posting-api-get-started>

> **App audit gate.** Until your TikTok app passes TikTok's audit, it can only
> post with `SELF_ONLY` visibility ("Only me"); a public direct post returns
> `unaudited_client_can_only_post_to_private_accounts`. Test with "Only me"
> first, or use a test account that TikTok has allow-listed for your app.

## OAuth flow

`TikTokConnectionController` + `TikTokOAuthConnector` (dedicated flow, no Socialite
driver — `Platform::usesDedicatedConnectionFlow()` 404s TikTok on the generic
route). Docs: <https://developers.tiktok.com/doc/oauth-user-access-token-management>

| Step | Code | TikTok |
| --- | --- | --- |
| Authorize | `https://www.tiktok.com/v2/auth/authorize/` with `client_key`, `response_type=code`, comma-separated `scope`, `redirect_uri`, `state` | ✅ matches |
| Token exchange | `POST https://open.tiktokapis.com/v2/oauth/token/`, `grant_type=authorization_code` | ✅ |
| Refresh | same endpoint, `grant_type=refresh_token`; both access **and** refresh tokens are re-persisted (TikTok may rotate the refresh token) | ✅ |
| Profile | `GET https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,avatar_url,display_name,username` | ✅ |

- `open_id` identifies the user to this app; stored as `remote_account_id`.
- `username` (the @handle) needs `user.info.profile` — it is **not** part of
  `user.info.basic`. A common integration trap.
- Token responses carry an `error` envelope; a 200 does not imply success.

## Publishing endpoints

`TikTokConnector` — init → transfer → poll, mirroring `InstagramConnector`. The
`publish_id` is persisted in `PostTarget.media_upload_state` so a queue retry
resumes rather than restarts.

| Purpose | Endpoint | Doc |
| --- | --- | --- |
| Direct-post video init | `POST /v2/post/publish/video/init/` | [Direct Post](https://developers.tiktok.com/doc/content-posting-api-reference-direct-post) |
| Inbox (draft) video init | `POST /v2/post/publish/inbox/video/init/` | [Upload Video](https://developers.tiktok.com/doc/content-posting-api-reference-upload-video) |
| Photo (direct or draft) | `POST /v2/post/publish/content/init/` | [Photo Post](https://developers.tiktok.com/doc/content-posting-api-reference-photo-post) |
| Poll status | `POST /v2/post/publish/status/fetch/` | [Get Post Status](https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status) |
| Query creator info | `POST /v2/post/publish/creator_info/query/` | [Query Creator Info](https://developers.tiktok.com/doc/content-posting-api-reference-query-creator-info) |

Base host: `https://open.tiktokapis.com/v2`. All prefixes/hosts above verified
against the docs.

### Video transfer (chunked `FILE_UPLOAD`)

`TikTokChunkPlan`, verified against the
[Media Transfer Guide](https://developers.tiktok.com/doc/content-posting-api-media-transfer-guide):

- Each chunk **≥ 5 MB and ≤ 64 MB**; the **final chunk may exceed `chunk_size`,
  up to 128 MB**. ✅
- `total_chunk_count = floor(video_size / chunk_size)`; the last chunk absorbs
  the remainder. ✅
- A video smaller than one chunk is sent whole (`chunk_size == video_size`,
  `total_chunk_count == 1`) — the one case where `chunk_size` may fall below the
  5 MB floor. ✅
- `Content-Range: bytes {start}-{end}/{total}` (inclusive end). ✅
- TikTok never says whether "MB" means 10⁶ or 2²⁰, so `CHUNK_SIZE` (10 MiB) is
  chosen to be legal under **both** readings: `[5_242_880, 64_000_000]`. Do not
  raise it past `64_000_000`.

### Photo carousel (`PULL_FROM_URL`)

`content/init` body — `media_type: PHOTO`, `post_mode: DIRECT_POST | MEDIA_UPLOAD`,
`source_info.source: PULL_FROM_URL`, `photo_images` (URLs on our domain),
`photo_cover_index`, and for a direct post `post_info` (`title`, `description`,
`privacy_level`, `disable_comment`, `brand_content_toggle`, `brand_organic_toggle`,
`auto_add_music`). All fields verified against the Photo Post doc. Photo URLs are
resolved through the shared `PublicMediaUrl` → `ImageToJpegConverter` pipeline
(PNG/GIF → JPEG; WebP/JPEG pass through, per `Platform::TikTok->allowedMime()`).

## Scopes

`user.info.basic`, `user.info.profile`, `video.publish` (Direct Post),
`video.upload` (inbox draft). `user.info.stats` and `video.list` are deliberately
**not** requested until a metrics connector lands (adding them later costs a
reconnect). Verified against the OAuth and Content Posting docs.

## Limits (`Platform::TikTok`)

| Limit | Value | Source |
| --- | --- | --- |
| Video caption / `post_info.title` | 2 200 UTF-16 runes | ✅ docs |
| Photo title | 90 runes (`TIKTOK_PHOTO_TITLE_MAX`) | ✅ docs |
| Photo description | 4 000 runes | ✅ docs |
| Text counting unit | UTF-16 code units ("runes") — shares X's `measure()` arm | ✅ docs |
| Photo carousel max | 35 images | docs |
| Photo MIME | JPEG, WebP (PNG/GIF transcoded to JPEG) | docs |
| Per-photo bytes | 20 MB | docs |
| Threading | 1 (TikTok has no thread concept — pinned in `threadMax()`) | n/a |
| Video/photo mixing | never (`combinesVideoAndImages()` → false) | docs |

`maxVideoBytes` is held at **1 GiB** even though TikTok accepts up to 4 GB:
`Platform::maxVideoBytesCeiling()` is a `max()` across all platforms and gates the
presigned-upload guard, so raising it here would raise the cap for every platform.
Raise both together once that guard is per-platform.

## Compliance (`TikTokOptionsPanel` + `TikTokConnector::revalidateAgainstCreatorInfo`)

Per TikTok's
[content-sharing guidelines](https://developers.tiktok.com/doc/content-sharing-guidelines):

- `creator_info` is queried **fresh** before the options render (never cached) and
  **again at publish** — this is a scheduler, so allowed levels can change between
  compose and publish.
- The privacy dropdown offers **exactly** `privacy_level_options` from
  `creator_info`, with **nothing pre-selected** (`tiktok_privacy_level` stays NULL
  until the creator chooses).
- Interaction/disclosure toggles **default off**. They are stored as TikTok's
  `disable_*` and modelled as `allow*` in the UI; the inversion lives in one
  tested place (`toWire`/`fromWire`).
- **Branded content cannot be private** — blocked in the composer and re-checked
  at publish.

`creator_info` fields consumed: `privacy_level_options`, `comment_disabled`,
`duet_disabled`, `stitch_disabled`, `max_video_post_duration_sec`,
`creator_username`, `creator_nickname`, `creator_avatar_url`. All verified.

Privacy levels (`TikTokPrivacyLevel`): `PUBLIC_TO_EVERYONE`,
`MUTUAL_FOLLOW_FRIENDS`, `FOLLOWER_OF_CREATOR`, `SELF_ONLY`. Verified.

## Error handling (`TikTokErrorMap`)

Classified on TikTok's `error.code`, **not** the HTTP status (the same condition
can arrive as 200, 403, or 429 on different endpoints). Verified codes:

| Code | Mapped to | Note |
| --- | --- | --- |
| `rate_limit_exceeded` | RateLimited | the only true 429 |
| `spam_risk_too_many_pending_share` | Validation (terminal) | 5 pending drafts / 24 h; HTTP 403, not 429 |
| `spam_risk_too_many_posts` | Validation (terminal) | daily post cap |
| `privacy_level_option_mismatch` | Validation | chosen visibility no longer allowed |
| `url_ownership_unverified` | Validation | verify the domain under URL Properties |
| `unaudited_client_can_only_post_to_private_accounts` | Validation | app not audited yet |
| `access_token_invalid` / `scope_*` | AuthExpired | reconnect |

Reference: <https://developers.tiktok.com/doc/tiktok-api-v2-error-handling>

## Post status (`status/fetch`)

`TikTokConnector::pollStatus` handles: `PUBLISH_COMPLETE` (success; reads the
TikTok-typo'd `publicaly_available_post_id`), `SEND_TO_USER_INBOX` (inbox draft
landed — terminal success), `PROCESSING_UPLOAD` / `PROCESSING_DOWNLOAD`
(re-queue), `FAILED` (reads `fail_reason`). Unknown statuses fall to the `default`
arm as a retryable `ServerError`, so an unexpected value degrades safely rather
than crashing. `PROCESSING_UPLOAD`, `PUBLISH_COMPLETE`, `FAILED`,
`publicaly_available_post_id`, and `fail_reason` are verified against the docs.

## Not implemented

- **Metrics** — `unsupported`. The Content Posting API returns
  `publicaly_available_post_id`; the Display API takes `filters.video_ids`, and no
  doc states they are the same id. Needs verifying against a real post first.
- **Engagement** — none. No API reads comments on a creator's own organic posts.
- **`delete()`** — a no-op. TikTok has no delete endpoint, so removing a target
  locally leaves the post live on TikTok (the composer warns before delete).

## How to test

1. Create a TikTok app, enable Login Kit + Content Posting API, add the four
   scopes, register `TIKTOK_REDIRECT_URI`, and verify `APP_URL`'s domain under URL
   Properties. Set the three env vars.
2. **Connect**: Accounts → Connect → TikTok, accept all permissions. The account
   card should appear with your @handle and avatar.
3. **Compose a draft (safest first test)**: pick the TikTok account, add a video,
   set the mode toggle to **Draft**, publish. It should land in your TikTok app's
   inbox (`SEND_TO_USER_INBOX`) for you to finish by hand — no audit needed.
4. **Direct post**: switch the toggle to **Direct post**, choose a visibility from
   the dropdown (only what `creator_info` offers appears). Until your app is
   audited, use **Only me**. Publish and confirm it appears on the profile.
5. **Photo carousel**: add 2+ images; confirm the preview auto-advances and the
   post publishes via `PULL_FROM_URL` (requires the verified domain).

## Verify against the live API

Doc shapes are confirmed above; these could only be confirmed against a real
TikTok app and should be checked during the first live run:

- **`SEND_TO_USER_INBOX` / `PROCESSING_DOWNLOAD` status strings** — used by the
  poller but not quotable from public sources; only TikTok's Get-Post-Status page
  lists them. If `SEND_TO_USER_INBOX` is spelled differently, a **draft** upload
  would poll until it times out instead of reporting success (the `default` arm
  keeps it from crashing). Confirm the exact strings on the first inbox draft.
- **`creator_avatar_url` field name** and the real `error.message` bodies.
- **Audit-gated behaviours** (`unaudited_client_can_only_post_to_private_accounts`).

Nothing here has been exercised against the live TikTok API yet — only against
faked HTTP in the test suite (`tests/Feature/Publishing/TikTokConnectorTest.php`).
