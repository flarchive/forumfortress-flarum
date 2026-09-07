<?php

namespace ForumFortress\Flarum\Moderation;

use Carbon\Carbon;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Flags\Flag;
use Flarum\Foundation\Config;
use Flarum\Group\Group;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Flarum\Extension\ExtensionManager;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Illuminate\Contracts\Events\Dispatcher;

final class Bridge
{
    public function __construct(
        private ForumFortressClient $client,
        private Config $config,
        private Dispatcher $events,
        private ExtensionManager $extensions
    ) {
    }

    public function sync(): array
    {
        if (! $this->isAvailable()) {
            return [
                'skipped' => true,
                'reason' => 'Enable Flarum Approval and Flags to use moderation synchronization.',
                'queue_items' => 0,
                'actions' => 0,
            ];
        }
        $items = $this->collectQueueItems();
        $push = $this->client->moderationQueueSync($items);
        $pulled = $this->client->pullModerationActions();
        $results = $this->executeActions((array) ($pulled['actions'] ?? []));
        $ack = $results === [] ? [] : $this->client->acknowledgeModerationActions($results);

        return ['queue_items' => count($items), 'push' => $push, 'actions' => count($results), 'ack' => $ack];
    }

    public function isAvailable(): bool
    {
        return class_exists(PostWasApproved::class)
            && class_exists(Flag::class)
            && $this->extensions->isEnabled('flarum-approval')
            && $this->extensions->isEnabled('flarum-flags');
    }

    public function collectQueueItems(): array
    {
        $posts = CommentPost::query()
            ->where('is_approved', false)
            ->with(['discussion', 'user'])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($posts as $post) {
            $isThread = (int) $post->number === 1;
            $discussion = $post->discussion;
            $user = $post->user;
            $content = (string) $post->content;
            $items[] = [
                'remote_content_type' => $isThread ? 'thread' : 'post',
                'remote_content_id' => (string) ($isThread ? $post->discussion_id : $post->id),
                'title' => $isThread ? (string) ($discussion?->title ?? '') : 'Reply',
                'excerpt' => mb_substr($content, 0, 280),
                'username' => (string) ($user?->username ?? ''),
                'remote_user_id' => $user ? (string) $user->id : null,
                'content_date' => $post->created_at?->getTimestamp(),
                'content_url' => rtrim((string) $this->config['url'], '/').'/d/'.$post->discussion_id.'/'.$post->number,
                'available_actions' => ['approve', 'reject', 'spam_clean'],
                'payload' => ['content_type' => $isThread ? 'thread' : 'post', 'post_id' => (int) $post->id],
            ];
        }
        return $items;
    }

    public function executeActions(array $actions): array
    {
        $actor = $this->systemModerator();
        $results = [];
        foreach ($actions as $action) {
            $id = (int) ($action['id'] ?? 0);
            try {
                if ($id < 1) throw new \UnexpectedValueException('Moderation action is missing a valid ID.');
                if (! $actor) throw new \RuntimeException('No administrator is available to apply moderation actions.');
                $contentType = strtolower(trim((string) ($action['remote_content_type'] ?? '')));
                $actionName = strtolower(trim((string) ($action['action'] ?? '')));
                if (! in_array($contentType, ['thread', 'post'], true)) {
                    throw new \UnexpectedValueException('Unknown moderation content type.');
                }
                if (! in_array($actionName, ['approve', 'reject', 'spam_clean'], true)) {
                    throw new \UnexpectedValueException('Unknown moderation action.');
                }
                $post = $this->findPost($contentType, (int) ($action['remote_content_id'] ?? 0));
                if (! $post) {
                    $results[] = ['id' => $id, 'status' => 'applied', 'message' => 'Queue item is no longer pending.'];
                    continue;
                }
                match ($actionName) {
                    'approve' => $this->approve($post, $actor),
                    'reject' => $this->reject($post),
                    'spam_clean' => $this->spamClean($post),
                };
                $results[] = ['id' => $id, 'status' => 'applied', 'message' => 'Action applied.'];
            } catch (\Throwable $error) {
                $results[] = ['id' => $id, 'status' => 'failed', 'message' => $error->getMessage()];
            }
        }
        return $results;
    }

    private function findPost(string $type, int $id): ?CommentPost
    {
        if ($id < 1) return null;
        $query = CommentPost::query()->where('is_approved', false);
        if ($type === 'thread') {
            return $query->where('discussion_id', $id)->where('number', 1)->first();
        }
        if ($type === 'post') {
            return $query->whereKey($id)->first();
        }
        return null;
    }

    private function approve(CommentPost $post, User $actor): void
    {
        $post->is_approved = true;
        $post->save();
        if ((int) $post->number === 1 && $post->discussion) {
            $post->discussion->is_approved = true;
            $post->discussion->save();
        }
        Flag::query()->where('post_id', $post->id)->where('type', 'approval')->delete();
        $this->events->dispatch(new PostWasApproved($post, $actor));
    }

    private function reject(CommentPost $post): void
    {
        Flag::query()->where('post_id', $post->id)->where('type', 'approval')->delete();
        if ((int) $post->number === 1 && $post->discussion) {
            $post->discussion->delete();
        } else {
            $post->delete();
        }
    }

    private function spamClean(CommentPost $post): void
    {
        $user = $post->user;
        if ($user && ! $user->isAdmin() && $user->getConnection()->getSchemaBuilder()->hasColumn('users', 'suspended_until')) {
            $user->suspended_until = Carbon::now()->addYears(100);
            $user->save();
        }
        $this->reject($post);
    }

    private function systemModerator(): ?User
    {
        return User::query()->whereHas('groups', fn ($query) => $query->where('groups.id', Group::ADMINISTRATOR_ID))->orderBy('users.id')->first();
    }
}
