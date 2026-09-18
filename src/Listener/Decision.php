<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Foundation\ValidationException;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;

final class Decision
{
    public static function assertAllowed(?array $response): void
    {
        if (! $response) {
            return;
        }

        $decision = strtolower(trim((string) ($response['decision'] ?? '')));
        if ($decision === 'block') {
            throw new ValidationException([
                'forumfortress' => 'Forum Fortress rejected this request as suspected spam.',
            ]);
        }
        if (! in_array($decision, ['allow', 'review'], true)) {
            throw new UnavailableException('Forum Fortress returned an invalid decision response.');
        }
    }

    public static function unavailable(UnavailableException $error): ValidationException
    {
        return new ValidationException([
            'forumfortress' => 'Forum Fortress is temporarily unavailable. Please try again. Support: '
                .ForumFortressClient::SUPPORT_URL,
        ]);
    }
}
