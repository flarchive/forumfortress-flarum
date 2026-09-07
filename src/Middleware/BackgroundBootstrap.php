<?php

namespace ForumFortress\Flarum\Middleware;

use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recover an incomplete first bootstrap from ordinary forum traffic.
 *
 * Flarum's scheduler is not configured on every installation. A lost
 * enable-time response must therefore also recover when a visitor loads the
 * forum, without waiting for a registration/post or an administrator action.
 */
final class BackgroundBootstrap implements MiddlewareInterface
{
    private const RETRY_BACKOFF_SECONDS = 300;

    public function __construct(
        private ForumFortressClient $client,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (strtoupper($request->getMethod()) !== 'GET' || ! $this->recoveryNeeded()) {
            return $response;
        }

        $now = time();
        $lastAttempt = (int) $this->settings->get('forumfortress.last_background_recovery_at', '0');
        if ($lastAttempt > $now - self::RETRY_BACKOFF_SECONDS) {
            return $response;
        }
        $this->settings->set('forumfortress.last_background_recovery_at', (string) $now);

        try {
            if (trim((string) $this->settings->get('forumfortress.api_key', '')) === '') {
                $this->client->bootstrapIfNeeded();
            } else {
                // A process can be interrupted between the two setting writes.
                // Site status returns the current identity and persists it.
                $this->client->siteStatus(1);
            }
            // Bootstrap persistence alone is not the handshake boundary. Send
            // the stored key back on an authenticated ping so the API can end
            // permissive anonymous recovery for this forum.
            $this->client->confirmConnection(2);
            $this->settings->set('forumfortress.last_bootstrap_at', (string) time());
            $this->settings->set('forumfortress.last_bootstrap_error', '');
        } catch (\Throwable $error) {
            $message = mb_substr(trim($error->getMessage()), 0, 500);
            $this->settings->set('forumfortress.last_bootstrap_error', $message);
            if ($this->settings->get('forumfortress.debug_log', '0') === '1') {
                $this->logger->warning('Forum Fortress background bootstrap failed: {message}', [
                    'message' => $message,
                    'exception' => $error,
                ]);
            }
        }

        return $response;
    }

    private function recoveryNeeded(): bool
    {
        if ($this->settings->get('forumfortress.enabled', '1') !== '1'
            || $this->settings->get('forumfortress.bootstrap_suppressed', '0') === '1') {
            return false;
        }
        if (trim((string) $this->settings->get('forumfortress.api_key', '')) === ''
            || trim((string) $this->settings->get('forumfortress.site_id', '')) === '') {
            return true;
        }

        $state = json_decode((string) $this->settings->get('forumfortress.endpoint_state', '{}'), true);
        return ! is_array($state) || (int) ($state['last_site_ping_at'] ?? 0) <= 0;
    }
}
