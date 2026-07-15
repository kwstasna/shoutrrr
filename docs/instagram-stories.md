# Instagram Stories & Meta webhooks

Shoutrrr can schedule and publish Instagram **Stories** (photo or video) alongside
feed posts and Reels, and receive **story insights** and **comments** back from Meta
through a per-workspace webhook.

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

Meta pushes **story insights** (final metrics, sent within 24 h before a story
expires) and **comments** to a callback URL. Shoutrrr exposes one callback URL per
workspace so a single instance can route deliveries to the right tenant.

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
3. Subscribe to the **`story_insights`** and **`comments`** fields.

### 3. Subscribe each Instagram account

Webhook fields are delivered only for accounts subscribed to your app:

```
POST /<IG_USER_ID>/subscribed_apps?subscribed_fields=story_insights,comments&access_token=<PAGE_TOKEN>
```

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

## Environment

```dotenv
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=          # also used to verify webhook signatures
FACEBOOK_GRAPH_VERSION=v25.0
```

Required OAuth scopes already requested at connect time include
`instagram_content_publish` (publishing) and `instagram_manage_insights`
(story insights). To subscribe pages programmatically you additionally need
`pages_manage_metadata`.
