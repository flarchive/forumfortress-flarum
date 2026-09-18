<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;

final class CheckPost
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(Saving $event): void
    {
        if (! $event->post instanceof CommentPost || $event->actor->isAdmin()) {
            return;
        }

        $body = trim((string) $event->post->content);
        if ($body === '') {
            return;
        }

        $isTopic = $event->post->exists
            ? (int) $event->post->number === 1
            : $event->post->discussion?->first_post_id === null;
        $title = $isTopic ? trim((string) ($event->post->discussion?->title ?? '')) : '';
        $content = trim($title === '' ? $body : $title."\n\n".$body);

        try {
            $response = $this->client->check($isTopic ? 'topic' : 'reply', $this->client->userPayload($event->actor, [
                'content' => $content,
                'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
                'content_id' => $event->post->exists ? (string) $event->post->id : null,
                'thread_id' => $event->post->discussion_id ? (string) $event->post->discussion_id : null,
                'action' => $event->post->exists ? 'edit' : 'create',
            ]));
            Decision::assertAllowed($response);
        } catch (UnavailableException $error) {
            throw Decision::unavailable($error);
        }
    }
}
