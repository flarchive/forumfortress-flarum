<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\User\Event\Saving;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;

final class CheckRegistration
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(Saving $event): void
    {
        if ($event->user->exists || $event->actor->isAdmin()) {
            return;
        }

        try {
            Decision::assertAllowed($this->client->check('register', $this->client->userPayload($event->user)));
        } catch (UnavailableException $error) {
            throw Decision::unavailable($error);
        }
    }
}
