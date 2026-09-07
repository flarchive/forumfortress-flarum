<?php

namespace ForumFortress\Flarum;

use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Psr\Log\LoggerInterface;

final class UninstallManager
{
    public function __construct(
        private ForumFortressClient $client,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger
    ) {
    }

    public function deprovision(string $reason = 'plugin_uninstall', bool $throwOnFailure = false): array
    {
        try {
            $result = $this->client->deprovisionSite($reason);
            if (self::shouldClearIdentity($result)) {
                $this->client->clearIdentity();
            } else {
                throw new \RuntimeException('Forum Fortress returned an unconfirmed deprovision result.');
            }
            $this->clearPendingDeprovision();
            if ($reason === 'manual_disconnect') {
                // Keep an installed extension from silently recreating the site
                // between an intentional disconnect and Composer removal.
                $this->settings->set('forumfortress.bootstrap_suppressed', '1');
                $this->settings->set('forumfortress.enabled', '0');
            }
            $this->logger->info('Forum Fortress site deprovisioned during {reason}: {status}', [
                'reason' => $reason,
                'status' => (string) ($result['status'] ?? 'ok'),
            ]);
            return array_merge($result, ['support_url' => ForumFortressClient::SUPPORT_URL]);
        } catch (\Throwable $error) {
            // Keep the local identity on failure. A later reinstall can continue
            // using it instead of getting trapped with an orphaned remote site.
            $message = mb_substr(trim($error->getMessage()), 0, 500);
            $this->settings->set('forumfortress.last_bootstrap_error', $message);
            $this->settings->set('forumfortress.deprovision_pending', '1');
            $this->settings->set('forumfortress.last_deprovision_error', $message);
            $this->settings->set('forumfortress.last_deprovision_reason', $reason);
            $this->settings->set('forumfortress.last_deprovision_at', (string) time());
            $this->logger->warning(
                'Forum Fortress deprovision failed during {reason}: {message}. Support: {support}',
                [
                    'reason' => $reason,
                    'message' => $message,
                    'support' => ForumFortressClient::SUPPORT_URL,
                    'exception' => $error,
                ]
            );
            if ($throwOnFailure) {
                throw new \RuntimeException(
                    $message.' Contact Forum Fortress support: '.ForumFortressClient::SUPPORT_URL,
                    0,
                    $error
                );
            }
            return [
                'status' => 'failed',
                'error' => $message,
                'support_url' => ForumFortressClient::SUPPORT_URL,
            ];
        }
    }

    public static function shouldClearIdentity(array $result): bool
    {
        return in_array(strtolower(trim((string) ($result['status'] ?? ''))), [
            'ok',
            'already_removed',
            'no_identity',
        ], true);
    }

    public function hasPendingDeprovision(): bool
    {
        return $this->settings->get('forumfortress.deprovision_pending', '0') === '1';
    }

    public function retryPendingDeprovision(): array
    {
        if (! $this->hasPendingDeprovision()) {
            return ['status' => 'not_pending'];
        }

        $reason = (string) $this->settings->get(
            'forumfortress.last_deprovision_reason',
            'plugin_uninstall'
        );
        if (! in_array($reason, ['plugin_uninstall', 'manual_disconnect'], true)) {
            $reason = 'plugin_uninstall';
        }

        return $this->deprovision($reason);
    }

    private function clearPendingDeprovision(): void
    {
        $this->settings->set('forumfortress.deprovision_pending', '0');
        $this->settings->set('forumfortress.last_deprovision_error', '');
        $this->settings->set('forumfortress.last_deprovision_reason', '');
        $this->settings->set('forumfortress.last_deprovision_at', '0');
    }
}
