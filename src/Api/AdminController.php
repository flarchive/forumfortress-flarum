<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Moderation\Bridge;
use ForumFortress\Flarum\UninstallManager;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminController implements RequestHandlerInterface
{
    public function __construct(
        private ForumFortressClient $client,
        private Bridge $bridge,
        private SettingsRepositoryInterface $settings,
        private UninstallManager $uninstall
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $route = (string) $request->getAttribute('routeName');

        try {
            $data = match ($route) {
                'forumfortress.register' => $this->register($request),
                'forumfortress.attack.start' => $this->attackMode(true),
                'forumfortress.attack.end' => $this->attackMode(false),
                'forumfortress.portal' => $this->client->portalLaunch(),
                'forumfortress.test' => $this->connectionTest(),
                'forumfortress.sync' => $this->synchronize(),
                'forumfortress.deprovision' => $this->uninstall->deprovision('manual_disconnect', true),
                default => $this->dashboardStatus(),
            };

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $error) {
            return new JsonResponse([
                'error' => $error->getMessage(),
                'support_url' => ForumFortressClient::SUPPORT_URL,
            ], 502);
        }
    }

    private function dashboardStatus(): array
    {
        if ($this->settings->get('forumfortress.bootstrap_suppressed', '0') === '1') {
            return [
                'status' => [
                    'status' => 'disconnected',
                    'message' => 'Automatic bootstrap is paused until Forum Fortress is explicitly reconnected.',
                ],
                'stats' => [],
                'endpoints' => $this->client->endpointStateSummary(),
            ];
        }
        $status = $this->client->siteStatus();
        $dashboard = [
            'status' => $status,
            'stats' => $this->client->forumStats(),
            'endpoints' => $this->client->endpointStateSummary(),
        ];
        if ($this->settings->get('forumfortress.deprovision_pending', '0') === '1') {
            $dashboard['deprovision_pending'] = true;
            $dashboard['warning'] = (string) $this->settings->get(
                'forumfortress.last_deprovision_error',
                'Remote cleanup from a previous removal is still pending.'
            );
            $dashboard['support_url'] = ForumFortressClient::SUPPORT_URL;
        }

        $this->cacheDashboardStatus($dashboard);

        return $dashboard;
    }

    private function connectionTest(): array
    {
        $bootstrapError = null;
        try {
            $this->client->bootstrapIfNeeded(true);
        } catch (\Throwable $error) {
            // A regional node may remain able to protect a forum while the
            // control-plane bootstrap path is temporarily unavailable.
            $bootstrapError = $error->getMessage();
        }

        $checkRoute = null;
        $checkRouteError = null;
        try {
            $checkRoute = $this->client->checkRouteHealth();
        } catch (\Throwable $error) {
            $checkRouteError = $error->getMessage();
        }

        $controlHealth = null;
        $controlError = null;
        try {
            $controlHealth = $this->client->health();
        } catch (\Throwable $error) {
            $controlError = $error->getMessage();
        }

        if ($checkRoute === null && $controlHealth === null) {
            throw new \RuntimeException($checkRouteError ?? $controlError ?? $bootstrapError ?? 'Connection test failed.');
        }

        $result = [
            'check_route' => $checkRoute,
            'check_route_error' => $checkRouteError,
            'control_health' => $controlHealth,
            'control_error' => $controlError,
            'bootstrap_error' => $bootstrapError,
            // Retained for older admin bundles and integrations.
            'health' => $controlHealth,
            'capabilities' => $controlHealth === null ? null : $this->client->capabilities(),
            'status' => $controlHealth === null ? [] : $this->client->siteStatus(),
            'stats' => $controlHealth === null ? [] : $this->client->forumStats(),
        ];

        $this->cacheDashboardStatus([
            'status' => $result['status'],
            'stats' => $result['stats'],
            'endpoints' => $this->client->endpointStateSummary(),
        ]);

        return $result;
    }

    private function synchronize(): array
    {
        if ($this->settings->get('forumfortress.bootstrap_suppressed', '0') === '1') {
            $this->settings->set('forumfortress.bootstrap_suppressed', '0');
            $this->settings->set('forumfortress.enabled', '1');
        }
        $site = $this->client->sync(true);
        $moderation = $this->bridge->sync();
        $dashboard = $this->dashboardStatus();

        return [
            'site' => $site,
            'moderation' => $moderation,
            'dashboard' => $dashboard,
        ];
    }

    private function attackMode(bool $enabled): array
    {
        $status = $this->client->setAttackMode($enabled);
        $cached = json_decode((string) $this->settings->get('forumfortress.dashboard_status', '{}'), true);
        $dashboard = is_array($cached) ? $cached : [];
        $dashboard['status'] = array_merge((array) ($dashboard['status'] ?? []), $status, [
            'attack_mode_active' => (bool) $status['attack_mode_active'],
        ]);
        $dashboard['endpoints'] = $this->client->endpointStateSummary();
        $this->cacheDashboardStatus($dashboard);

        return $status;
    }

    private function cacheDashboardStatus(array $dashboard): void
    {
        $encoded = json_encode($dashboard, JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            $this->settings->set('forumfortress.dashboard_status', $encoded);
        }
    }

    private function register(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? $this->settings->get('forumfortress.registration_email', '')));

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid registration email is required.');
        }

        return $this->client->registerSite($email);
    }
}
