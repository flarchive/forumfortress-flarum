<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\User\Event\Saving;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;

final class CheckProfile
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(Saving $event): void
    {
        if (! $event->user->exists || $event->actor->isAdmin()) return;
        $attributes = (array) ($event->data['attributes'] ?? []);
        $profile = array_intersect_key($attributes, array_flip(['username', 'displayName', 'nickname', 'bio', 'signature']));
        if ($profile === []) return;
        $content = trim(implode("\n", array_map('strval', $profile)));
        try {
            Decision::assertAllowed($this->client->check('profile', $this->client->userPayload($event->user, [
                'content' => $content,
                'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
            ])));
        } catch (UnavailableException $error) {
            throw Decision::unavailable($error);
        }
    }
}
