<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SchedulePostRequest;
use App\Models\Post;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PostScheduleController extends Controller
{
    public function update(SchedulePostRequest $request, Post $post): JsonResponse|RedirectResponse
    {
        $scheduledAt = $request->validated('scheduled_at');

        if ($scheduledAt !== null) {
            $post->scheduled_at = $scheduledAt;
            $post->status = PostStatus::Scheduled;
        } else {
            $post->scheduled_at = null;
            $post->status = PostStatus::Draft;
        }

        $post->save();

        if ($request->headers->has('X-Inertia')) {
            return back();
        }

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
