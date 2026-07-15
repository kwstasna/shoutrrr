<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use App\Enums\ReplyStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\StoryInsight;
use App\Models\Workspace;
use App\Models\WorkspaceWebhook;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.facebook.client_secret', 'app-secret');
    $this->workspace = Workspace::factory()->create();
    $this->webhook = WorkspaceWebhook::factory()->create(['workspace_id' => $this->workspace->id]);
});

function metaPost(WorkspaceWebhook $webhook, array $payload, ?string $secret = 'app-secret', bool $sign = true): TestResponse
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($sign) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, (string) $secret);
    }

    return test()->call('POST', '/api/v1/webhooks/meta/'.$webhook->endpoint_token, [], [], [], $server, $raw);
}

function igEvent(string $field, array $value): array
{
    return [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'ig-user-1',
            'time' => 1_700_000_000,
            'changes' => [['field' => $field, 'value' => $value]],
        ]],
    ];
}

function igMessaging(array $messaging): array
{
    return [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'ig-user-1',
            'time' => 1_700_000_000,
            'messaging' => [$messaging],
        ]],
    ];
}

function storyTargetFor(Workspace $workspace, string $mediaId): PostTarget
{
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    return PostTarget::factory()->story()->create([
        'post_id' => $post->id,
        'remote_id' => $mediaId,
        'status' => PostTargetStatus::Published->value,
    ]);
}

test('verify handshake echoes the challenge when the workspace token matches', function () {
    $this->get('/api/v1/webhooks/meta/'.$this->webhook->endpoint_token.'?hub_mode=subscribe&hub_verify_token='.$this->webhook->verify_token.'&hub_challenge=98765')
        ->assertOk()
        ->assertSee('98765', false);
});

test('verify handshake is rejected when the token does not match', function () {
    $this->get('/api/v1/webhooks/meta/'.$this->webhook->endpoint_token.'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=1')
        ->assertForbidden();
});

test('verify handshake 404s for an unknown endpoint token', function () {
    $this->get('/api/v1/webhooks/meta/nope?hub_mode=subscribe&hub_verify_token=x&hub_challenge=1')
        ->assertNotFound();
});

test('a signed story_insights event persists a StoryInsight and denormalises onto the target', function () {
    $target = storyTargetFor($this->workspace, 'story-media-1');

    metaPost($this->webhook, igEvent('story_insights', [
        'media_id' => 'story-media-1',
        'reach' => 40,
        'replies' => 3,
        'shares' => 2,
        'profile_visits' => 5,
        'follows' => 1,
    ]))->assertOk();

    $insight = StoryInsight::where('post_target_id', $target->id)->first();
    expect($insight)->not->toBeNull()
        ->and($insight->reach)->toBe(40)
        ->and($insight->replies)->toBe(3)
        ->and($insight->profile_visits)->toBe(5)
        ->and($insight->raw['media_id'])->toBe('story-media-1');

    $target->refresh();
    expect($target->impressions)->toBe(40)
        ->and($target->comments)->toBe(3)
        ->and($target->reposts)->toBe(2)
        ->and($target->metrics_status)->toBe(MetricsStatus::Ok);

    $this->webhook->refresh();
    expect($this->webhook->received_count)->toBe(1)
        ->and($this->webhook->last_event)->toBe('story_insights');
});

test('a signed comments event (Facebook Login shape) lands in the Engagement inbox', function () {
    $target = storyTargetFor($this->workspace, 'media-with-comment');

    // The Facebook-Login-for-Business `comments` payload Shoutrrr receives keys the
    // comment as `comment_id` and carries `parent_id` (see Meta webhooks examples).
    metaPost($this->webhook, igEvent('comments', [
        'comment_id' => 'comment-1',
        'parent_id' => 'root-comment',
        'text' => 'love this',
        'from' => ['id' => '999', 'username' => 'fan_account'],
        'media' => ['id' => 'media-with-comment', 'media_product_type' => 'STORY'],
    ]))->assertOk();

    $reply = PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'comment-1')->first();
    expect($reply)->not->toBeNull()
        ->and($reply->post_target_id)->toBe($target->id)
        ->and($reply->workspace_id)->toBe($this->workspace->id)
        ->and($reply->text)->toBe('love this')
        ->and($reply->author_handle)->toBe('fan_account')
        ->and($reply->parent_remote_id)->toBe('root-comment')
        ->and($reply->status)->toBe(ReplyStatus::Pending)
        ->and($reply->is_ours)->toBeFalse();
});

test('a comments event in the Business-Login shape (id key) is also accepted', function () {
    storyTargetFor($this->workspace, 'media-biz');

    metaPost($this->webhook, igEvent('comments', [
        'id' => 'biz-comment-1',
        'text' => 'hey',
        'from' => ['username' => 'someone'],
        'media' => ['id' => 'media-biz'],
    ]))->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'biz-comment-1')->exists())->toBeTrue();
});

test('a comment redelivery is idempotent on (post_target_id, remote_reply_id)', function () {
    storyTargetFor($this->workspace, 'media-x');

    $event = igEvent('comments', [
        'id' => 'dup-1',
        'text' => 'hi',
        'from' => ['username' => 'someone'],
        'media' => ['id' => 'media-x'],
    ]);

    metaPost($this->webhook, $event)->assertOk();
    metaPost($this->webhook, $event)->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'dup-1')->count())->toBe(1);
});

test('a signed story reply (messaging DM) lands in the Engagement inbox against its story', function () {
    $target = storyTargetFor($this->workspace, 'story-media-reply');

    metaPost($this->webhook, igMessaging([
        'sender' => ['id' => 'igsid-123'],
        'recipient' => ['id' => 'ig-user-1'],
        'timestamp' => 1_700_000_000_000,
        'message' => [
            'mid' => 'mid-abc',
            'text' => 'this story is fire',
            'reply_to' => ['story' => ['id' => 'story-media-reply', 'url' => 'https://x/s.jpg']],
        ],
    ]))->assertOk();

    $reply = PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'mid-abc')->first();
    expect($reply)->not->toBeNull()
        ->and($reply->post_target_id)->toBe($target->id)
        ->and($reply->workspace_id)->toBe($this->workspace->id)
        ->and($reply->text)->toBe('this story is fire')
        ->and($reply->parent_remote_id)->toBe('story-media-reply')
        ->and($reply->author_handle)->toBe('ig:igsid-123')
        ->and($reply->status)->toBe(ReplyStatus::Pending)
        ->and($reply->is_ours)->toBeFalse();

    $this->webhook->refresh();
    expect($this->webhook->last_event)->toBe('messages');
});

test('a plain DM with no story context is acknowledged but not recorded', function () {
    storyTargetFor($this->workspace, 'story-media-plain');

    metaPost($this->webhook, igMessaging([
        'sender' => ['id' => 'igsid-9'],
        'recipient' => ['id' => 'ig-user-1'],
        'timestamp' => 1_700_000_000_000,
        'message' => ['mid' => 'mid-plain', 'text' => 'just a DM'],
    ]))->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'mid-plain')->exists())->toBeFalse();
});

test('an echo of our own story reply is ignored', function () {
    storyTargetFor($this->workspace, 'story-echo');

    metaPost($this->webhook, igMessaging([
        'sender' => ['id' => 'ig-user-1'],
        'message' => [
            'mid' => 'mid-echo',
            'is_echo' => true,
            'text' => 'our reply',
            'reply_to' => ['story' => ['id' => 'story-echo']],
        ],
    ]))->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'mid-echo')->exists())->toBeFalse();
});

test('a story reply redelivery is idempotent on (post_target_id, remote_reply_id)', function () {
    storyTargetFor($this->workspace, 'story-dup');

    $event = igMessaging([
        'sender' => ['id' => 'igsid-1'],
        'message' => [
            'mid' => 'mid-dup',
            'text' => 'nice',
            'reply_to' => ['story' => ['id' => 'story-dup']],
        ],
    ]);

    metaPost($this->webhook, $event)->assertOk();
    metaPost($this->webhook, $event)->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'mid-dup')->count())->toBe(1);
});

test('a wrongly-signed event is rejected and records nothing', function () {
    $target = storyTargetFor($this->workspace, 'story-media-2');

    metaPost($this->webhook, igEvent('story_insights', ['media_id' => 'story-media-2', 'reach' => 10]), secret: 'wrong-secret')
        ->assertForbidden();

    expect(StoryInsight::where('post_target_id', $target->id)->exists())->toBeFalse();
});

test('an event for another workspace media id is ignored', function () {
    $otherWorkspace = Workspace::factory()->create();
    $target = storyTargetFor($otherWorkspace, 'foreign-media');

    metaPost($this->webhook, igEvent('story_insights', ['media_id' => 'foreign-media', 'reach' => 7]))
        ->assertOk();

    expect(StoryInsight::where('post_target_id', $target->id)->exists())->toBeFalse();
});

test('a feed target is never matched by a story_insights event sharing its media id', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $feed = PostTarget::factory()->create([
        'post_id' => $post->id,
        'platform' => Platform::Instagram->value,
        'format' => PostFormat::Feed->value,
        'remote_id' => 'shared-id',
    ]);

    metaPost($this->webhook, igEvent('story_insights', ['media_id' => 'shared-id', 'reach' => 5]))
        ->assertOk();

    expect(StoryInsight::where('post_target_id', $feed->id)->exists())->toBeFalse();
});
