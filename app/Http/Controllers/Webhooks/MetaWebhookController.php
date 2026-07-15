<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RecordInstagramComment;
use App\Jobs\RecordInstagramStoryReply;
use App\Jobs\RecordStoryInsights;
use App\Models\WorkspaceWebhook;
use App\Services\Webhooks\MetaWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Receives Meta (Instagram Graph API) webhook events on a per-workspace callback
 * URL (/api/v1/webhooks/meta/{token}). The token resolves the owning workspace, so
 * a single instance behind one Meta app routes every delivery to the right tenant
 * and verifies it with that workspace's own token/secret.
 *
 * Wired fields: `story_insights` (persistent story analytics), `comments` and story
 * replies via `messaging` (both feed the Engagement inbox). Any other field is
 * acknowledged with 200 and ignored.
 */
class MetaWebhookController extends Controller
{
    /**
     * Verification handshake. Meta sends `hub.mode`, `hub.verify_token` and
     * `hub.challenge` as query params (PHP rewrites the dots to underscores) and
     * expects the raw challenge echoed back when the token matches.
     */
    public function verify(Request $request, string $token): Response
    {
        $webhook = $this->resolveWebhook($token);

        abort_unless($request->query('hub_mode') === 'subscribe', 403, 'Unsupported hub mode.');
        abort_unless(
            hash_equals($webhook->verify_token, (string) $request->query('hub_verify_token', '')),
            403,
            'Verify token mismatch.',
        );

        return response((string) $request->query('hub_challenge', ''));
    }

    /**
     * Event delivery. Verify the signature with the workspace's effective secret,
     * fan out each recognised change to a queued recorder, and acknowledge with 200
     * immediately (Meta retries on any non-200).
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        $webhook = $this->resolveWebhook($token);

        if (! MetaWebhookSignature::verify(
            $request->getContent(),
            $request->header('X-Hub-Signature-256'),
            $webhook->effectiveSigningSecret(),
        )) {
            Log::warning('Meta webhook rejected: invalid signature.', [
                'workspace_id' => $webhook->workspace_id,
                'has_signature' => $request->header('X-Hub-Signature-256') !== null,
            ]);

            abort(403, 'Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        $object = $payload['object'] ?? null;
        $handled = [];
        $lastField = null;

        if ($object === 'instagram') {
            foreach ($payload['entry'] ?? [] as $entry) {
                // Field changes: aggregate story metrics and feed/Reel comments.
                foreach ($entry['changes'] ?? [] as $change) {
                    $field = $change['field'] ?? null;
                    $value = $change['value'] ?? null;

                    if (! is_array($value)) {
                        continue;
                    }

                    if ($field === 'story_insights') {
                        RecordStoryInsights::dispatch($webhook->workspace_id, $value);
                        $handled[] = $field;
                        $lastField = $field;
                    } elseif ($field === 'comments') {
                        RecordInstagramComment::dispatch($webhook->workspace_id, $value);
                        $handled[] = $field;
                        $lastField = $field;
                    }
                }

                // Messaging events: a story reply arrives as a Direct Message (the
                // recorder ignores plain DMs that aren't story replies).
                foreach ($entry['messaging'] ?? [] as $messaging) {
                    if (! is_array($messaging)) {
                        continue;
                    }

                    RecordInstagramStoryReply::dispatch($webhook->workspace_id, $messaging);
                    $handled[] = 'messages';
                    $lastField = 'messages';
                }
            }
        }

        Log::info('Meta webhook received.', [
            'workspace_id' => $webhook->workspace_id,
            'object' => $object,
            'fields' => array_values(array_unique($handled)),
        ]);

        $this->recordDelivery($webhook, $lastField);

        return response()->json(['status' => 'ok']);
    }

    private function resolveWebhook(string $token): WorkspaceWebhook
    {
        $webhook = WorkspaceWebhook::query()->where('endpoint_token', $token)->first();

        abort_if($webhook === null, 404);

        return $webhook;
    }

    private function recordDelivery(WorkspaceWebhook $webhook, ?string $lastField): void
    {
        $webhook->forceFill([
            'last_received_at' => Date::now(),
            'last_event' => $lastField ?? $webhook->last_event,
            'received_count' => $webhook->received_count + 1,
        ])->save();
    }
}
