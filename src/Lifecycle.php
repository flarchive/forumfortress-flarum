<?php

namespace ForumFortress\Flarum;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

final class Lifecycle implements ExtenderInterface, LifecycleInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        // Lifecycle-only extender.
    }

    public function onEnable(Container $container, Extension $extension): void
    {
        $settings = $container->make(SettingsRepositoryInterface::class);
        if ($settings->get('forumfortress.bootstrap_suppressed', '0') === '1') {
            // An explicit extension enable is the user's opt-in to reconnect
            // after a prior manual disconnect/removal workflow.
            $settings->set('forumfortress.bootstrap_suppressed', '0');
            $settings->set('forumfortress.enabled', '1');
        }
        if ($settings->get('forumfortress.enabled', '1') !== '1') {
            return;
        }

        try {
            $pendingRetry = null;
            $uninstall = $container->make(UninstallManager::class);
            if ($uninstall->hasPendingDeprovision()) {
                // Composer removal can finish while the control plane is
                // unreachable. On reinstall, retry that cleanup once before
                // creating or validating the replacement connection.
                $pendingRetry = $uninstall->retryPendingDeprovision();
            }
            if ($settings->get('forumfortress.bootstrap_suppressed', '0') === '1'
                || $settings->get('forumfortress.enabled', '1') !== '1') {
                $settings->set('forumfortress.last_bootstrap_at', (string) time());
                return;
            }
            $client = $container->make(ForumFortressClient::class);
            $bootstrap = $client->bootstrapIfNeeded();
            if ($bootstrap === null) {
                // A retained key may belong to a site removed during an earlier
                // uninstall. Validate it now so enable also repairs reinstalls.
                $client->siteStatus(1);
            }
            if (($pendingRetry['status'] ?? '') !== 'failed') {
                $settings->set('forumfortress.last_bootstrap_error', '');
            }
            $settings->set('forumfortress.last_bootstrap_at', (string) time());
        } catch (\Throwable $error) {
            // Network availability must never make the Flarum extension itself
            // impossible to enable. Runtime checks remain fail-open by default.
            $message = mb_substr(trim($error->getMessage()), 0, 500);
            $settings->set('forumfortress.last_bootstrap_error', $message);
            $container->make(LoggerInterface::class)->warning(
                'Forum Fortress enable-time bootstrap failed: {message}. Support: {support}',
                [
                    'message' => $message,
                    'support' => ForumFortressClient::SUPPORT_URL,
                    'exception' => $error,
                ]
            );
        }
    }

    public function onDisable(Container $container, Extension $extension): void
    {
        // Disabling protection is reversible and must not delete remote data.
    }
}
