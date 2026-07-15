# Instagram Stories & Meta webhooks

Shoutrrr can schedule and publish Instagram **Stories** (photo or video) alongside
feed posts and Reels, and receive **story insights**, **comments**, and **story
replies** back from Meta through a per-workspace webhook.

This guide covers publishing stories, wiring up the webhook, and where the data
shows up. It is validated against the Meta developer documentation:

- [IG User `media`](https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/media) — story container creation
- [IG User `stories`](https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/stories) — reading stories
- [Story media metrics](https://developers.facebook.com/documentation/instagram-platform/reference/instagram-media/insights#story-media-metrics)
- [Instagram webhooks](https://developers.facebook.com/documentation/instagram-platform/webhooks)

## Publishing a story

1. In the composer, add an Instagram account to the destination.
2. On the Instagram tab, toggle **Story** in the toolbar.
3. Attach **one** photo or video, then schedule as usual.

Constraints enforced by Shoutrrr (matching Meta's Stories API):

| | Story |
| --- | --- |
| Media | exactly **one** photo or video (no carousel) |
| Caption | ignored — stories don't render captions |
| Video length | ≤ 60 seconds |
| Frame | 9:16, 1080×1920 recommended |

The composer shows a live 9:16 preview with the story chrome (progress bar,
header, reply bar) so you can keep key content out of the top/bottom safe zones
(~250 px each on a 1920 px canvas).

Under the hood the [`InstagramConnector`](../app/Services/Publishing/Connectors/InstagramConnector.php)
creates a `media_type=STORIES` container with an `image_url` or `video_url`, polls
it to `FINISHED`, then calls `media_publish` — the same two-step flow as feed posts.

## Webhooks

Meta pushes **story insights** (final metrics, sent when a story expires, within
24 h), **comments**, and **story replies** (delivered as Direct Messages) to a
callback URL. Shoutrrr exposes one callback URL per workspace so a single instance
can route deliveries to the right tenant.

### 1. Create the webhook in Shoutrrr

**Workspace settings → Webhooks → Create webhook.** You get:

- a **Callback URL** — `https://<your-host>/api/v1/webhooks/meta/<token>`
- a **Verify token**

Both are unique to the workspace. Use **Test** to confirm the endpoint is publicly
reachable and the signature verifies end-to-end.

### 2. Configure the Meta App Dashboard

1. In your app at the [Meta App Dashboard](https://developers.facebook.com/apps),
   open **Webhooks → Instagram**.
2. Paste the **Callback URL** and **Verify token**, then **Verify and save**. Meta
   sends a `GET` with `hub.mode`, `hub.verify_token` and `hub.challenge`; Shoutrrr
   echoes the challenge when the token matches.
3. Subscribe to the **`story_insights`**, **`comments`**, and **`messages`** fields
   (`messages` carries story replies).

### 3. Subscribe each Instagram account

Configuring the callback URL and fields above is necessary but **not sufficient** —
Instagram only delivers an account's events once its linked Facebook Page is
subscribed to the app on the Page node:

```
POST /<PAGE_ID>/subscribed_apps?subscribed_fields=comments,story_insights,messages&access_token=<PAGE_TOKEN>
```

Shoutrrr does this for you: **every Instagram account is subscribed automatically
when you connect it.** For accounts connected before you set up webhooks (or after a
token refresh), use **Workspace settings → Webhooks → Subscribe accounts** to
re-wire every connected Instagram account in one click.

### Security

Every `POST` is signed by Meta with `X-Hub-Signature-256: sha256=<HMAC-SHA256(raw body, app secret)>`.
Shoutrrr recomputes the digest over the raw bytes and compares it in constant time
([`MetaWebhookSignature`](../app/Services/Webhooks/MetaWebhookSignature.php)); a
missing secret, missing header, or mismatch is rejected with `403`. The secret is:

- the per-workspace **signing secret**, if set (for operators running a separate
  Meta app per workspace), otherwise
- the instance-wide `FACEBOOK_CLIENT_SECRET`.

Set at least one, or signatures cannot be verified. The verify token and signing
secret are encrypted at rest.

## Where the data goes

- **Story insights** → persisted in `story_insights` (durable, so metrics survive
  the 24 h story expiry) and denormalised onto the post target, so stories show up
  in **Analytics** alongside feed posts. Reach maps to impressions, replies to
  comments, shares to reposts.
- **Comments** → the **Engagement** inbox, deduplicated on
  `(post_target_id, remote_reply_id)` so a comment that also arrives via polling
  never doubles up.
- **Story replies** → the **Engagement** inbox too. A reply is a Direct Message
  carrying a `reply_to.story` context; Shoutrrr matches that story back to its post
  target and stores the reply on the same `(post_target_id, remote_reply_id)`
  contract as comments. Plain DMs (no story context) are acknowledged and ignored.

## Environment

```dotenv
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=          # also used to verify webhook signatures
FACEBOOK_GRAPH_VERSION=v25.0
```

### Instagram permissions

Connecting an Instagram account requests the full permission set the feature needs,
so a social media manager grants everything in one consent screen. Enable and
request each of these on your app (**App Dashboard → Use cases / Permissions**);
in Development mode they work for accounts added as testers, and production use
requires **App Review / Advanced Access**.

| Permission | Enables |
| --- | --- |
| `instagram_basic` | read the account + its media |
| `instagram_content_publish` | publish feed posts, Reels, and **Stories** |
| `instagram_manage_insights` | **story insights** (reach, replies, …) |
| `instagram_manage_comments` | fetch + reply to comments |
| `instagram_manage_messages` | receive + read **story replies** (Direct Messages) |
| `pages_show_list` | enumerate the user's Pages during connect |
| `pages_manage_metadata` | subscribe the linked Page to this app for webhook delivery |
| `business_management` | resolve Business-owned Pages / assets |

The exact list is the single source of truth in
[`Platform::scopes()`](../app/Enums/Platform.php); Facebook publishing requests its
own Page set alongside these when both are launched.
