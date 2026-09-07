<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Foundation\Application;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;

final class EndpointRequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public int $statusCode = 0,
        public bool $retryable = true,
        public ?string $errorCode = null
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function isExplicitSiteNotFound(int $statusCode, ?string $errorCode): bool
    {
        return in_array($statusCode, [404, 410], true)
            && strtolower(trim((string) $errorCode)) === 'site_not_found';
    }
}

final class ForumFortressClient
{
    public const PLUGIN_VERSION = '1.3.7.2';
    private const CONTROL_BASE_URL = 'https://fortress.ffapi.net';
    public const SUPPORT_URL = 'https://forumfortress.com/#contact';
    private const CATALOG_TTL = 3600;
    private const CHECK_TOTAL_BUDGET_SECONDS = 5;
    private const CHECK_ENDPOINT_TIMEOUT_SECONDS = 1;
    private const BOOTSTRAP_TOTAL_BUDGET_SECONDS = 3;
    private const BOOTSTRAP_ENDPOINT_TIMEOUT_SECONDS = 1;
    private const BOOTSTRAP_RETRY_BACKOFF_SECONDS = 300;
    private const REPORT_TIMEOUT_SECONDS = 1;

    private HttpClient $http;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Config $config,
        private LoggerInterface $logger
    ) {
        $this->http = new HttpClient();
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('forumfortress.enabled', '1') === '1'
            && $this->settings->get('forumfortress.bootstrap_suppressed', '0') !== '1';
    }

    public function check(string $eventType, array $payload): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $this->bootstrapIfNeeded();
            $result = $this->requestChecks('POST', '/v1/check/'.$eventType, array_merge($this->commonPayload(), $payload));
            $decision = strtolower(trim((string) ($result['decision'] ?? '')));
            if (! in_array($decision, ['allow', 'review', 'block'], true)) {
                throw new \UnexpectedValueException('Forum Fortress returned an invalid decision response.');
            }
            $this->persistIdentity($result);

            return $result;
        } catch (\Throwable $error) {
            $this->logFailure('check/'.$eventType, $error);
            if ($this->failOpen()) {
                return null;
            }

            throw new UnavailableException('Forum Fortress is temporarily unavailable.', 0, $error);
        }
    }

    public function report(string $reportType, array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            // Reports are best-effort telemetry. They must not turn a Flarum
            // moderation action into a multi-endpoint bootstrap operation.
            if ($this->apiKey() === '') {
                return;
            }
            $base = (string) ($this->checkCandidates()[0] ?? '');
            if ($base === '') {
                return;
            }
            $this->request(
                'POST',
                $base.'/v1/report/'.$reportType,
                array_merge($this->commonPayload(), $payload),
                self::REPORT_TIMEOUT_SECONDS
            );
            $this->recordEndpointResult($base, true, 0);
        } catch (\Throwable $error) {
            if (isset($base) && $base !== '') {
                $this->recordEndpointResult($base, false, 0);
            }
            $this->logFailure('report/'.$reportType, $error);
        }
    }

    public function bootstrapIfNeeded(bool $force = false): ?array
    {
        if (! $force && $this->settings->get('forumfortress.bootstrap_suppressed', '0') === '1') {
            throw new \RuntimeException(
                'Forum Fortress is disconnected. Re-enable the extension to create a new site connection.'
            );
        }
        $apiKey = trim((string) $this->settings->get('forumfortress.api_key', ''));
        $state = $this->endpointState();
        $rebootstrapAt = (int) ($state['rebootstrap_at'] ?? 0);

        if (! $force && $apiKey !== '' && ($rebootstrapAt === 0 || time() < $rebootstrapAt)) {
            return null;
        }
        $lastBootstrapFailure = (int) ($state['last_bootstrap_failure_at'] ?? 0);
        if (! $force && $lastBootstrapFailure > time() - self::BOOTSTRAP_RETRY_BACKOFF_SECONDS) {
            throw new \RuntimeException(
                'Forum Fortress bootstrap is waiting briefly before retrying after a connection failure.'
            );
        }

        $payload = $this->commonPayload();
        if ($force && str_starts_with($apiKey, 'ff_ob_')) {
            unset($payload['api_key']);
        }
        if (trim((string) ($payload['api_key'] ?? '')) === '') {
            $payload['bootstrap_recovery_token'] = $this->bootstrapRecoveryToken();
        }
        $reportedError = null;
        $started = microtime(true);
        $state['last_bootstrap_attempt_at'] = time();
        $this->saveEndpointState($state);
		$bases = $this->apiRegion() !== 'global'
			? array_values(array_unique(array_filter([
				$this->apiBaseUrl(),
				$this->allowGlobalEmergencyFallback() ? 'https://api.ffapi.net' : null,
				$this->controlBaseUrl(),
			])))
			: array_values(array_unique(array_merge([$this->controlBaseUrl()], $this->bootstrapCandidates())));
        foreach ($bases as $base) {
            $remaining = self::BOOTSTRAP_TOTAL_BUDGET_SECONDS - (microtime(true) - $started);
            if ($remaining <= 0) {
                break;
            }
            try {
                $attemptTimeout = max(1, min(self::BOOTSTRAP_ENDPOINT_TIMEOUT_SECONDS, (int) ceil($remaining)));
                $result = $this->requestBootstrap($base, $payload, $attemptTimeout);
                if (trim((string) ($result['api_key'] ?? '')) === '') {
                    throw new \UnexpectedValueException(
                        'Forum Fortress recognizes this site but did not return an API key. Open its plugin re-registration window, then retry synchronization.'
                    );
                }
                $this->persistIdentity($result);
                $this->recordEndpointResult($base, true, 0);
                $successState = $this->endpointState();
                unset($successState['last_bootstrap_failure_at']);
                $this->saveEndpointState($successState);
                return $result;
            } catch (\Throwable $error) {
                // Prefer the configured control plane's error, especially an
                // HTTP response, over a later fallback transport failure. This
                // keeps the operator-facing diagnosis tied to the endpoint
                // they configured instead of, for example, ending on a DNS
                // error from the final catalogue candidate.
                if ($reportedError === null
                    || ($error instanceof EndpointRequestException
                        && ! $reportedError instanceof EndpointRequestException)) {
                    $reportedError = $error;
                }
                $this->recordEndpointResult($base, false, 0);
            }
        }

        $failureState = $this->endpointState();
        $failureState['last_bootstrap_failure_at'] = time();
        $this->saveEndpointState($failureState);
        throw $reportedError ?: new \RuntimeException('No Forum Fortress bootstrap endpoint is available.');
    }

    public function refreshEndpointCatalog(bool $force = false): array
    {
        $state = $this->endpointState();
        if (! $force && time() - (int) ($state['catalog_fetched_at'] ?? 0) < self::CATALOG_TTL) {
            return $state;
        }

        $catalog = null;
        $fetchBases = array_values(array_unique(array_filter(array_merge(
            [$this->controlBaseUrl(), $this->apiBaseUrl()],
            (array) ($state['catalog'] ?? [])
        ))));
        foreach ($fetchBases as $base) {
            try {
                $catalog = $this->request('GET', $this->normalizeBaseUrl($base).'/v1/node-endpoints', [], 2);
                break;
            } catch (\Throwable $error) {
                $this->logFailure('node-endpoints', $error);
            }
        }
        if (is_array($catalog)) {
            [$endpoints, $meta] = $this->extractEndpointCatalog($catalog);
            if ($endpoints !== []) $state['catalog'] = $endpoints;
            if ($meta !== []) $state['endpoint_meta'] = $meta;
            $state['control_check_fallback'] = ! empty($catalog['control_check_fallback']);
            $state['catalog_fetched_at'] = time();
            unset($state['catalog_refresh_failed_at']);
        } else {
            $state['catalog_refresh_failed_at'] = time();
        }
        $this->saveEndpointState($state);

        return $state;
    }

    public function refreshEndpointHealth(bool $force = false): array
    {
        $state = $this->refreshEndpointCatalog($force);
        unset(
            $state['health'],
            $state['last_health_at'],
            $state['best_latency_ms'],
            $state['slow_health_mode'],
            $state['preferred_candidate'],
            $state['preferred_candidate_streak']
        );
        $this->settings->set('forumfortress.preferred_endpoint', $this->apiBaseUrl());
        $this->saveEndpointState($state);
        return $state;
    }

    public function siteStatus(?int $timeoutOverride = null): array
    {
        $status = $this->requestControlWithIdentityRecovery('GET', '/v1/site/status', fn (): array => [
            'api_key' => $this->apiKey(),
            'domain' => $this->domain(),
        ], false, $timeoutOverride);
        $this->persistIdentity($status);
        return $status;
    }

    public function forumStats(): array
    {
        return $this->requestControlWithIdentityRecovery('GET', '/v1/forum/stats', fn (): array => [
            'api_key' => $this->apiKey(),
            'domain' => $this->domain(),
        ]);
    }

    public function capabilities(): array
    {
        return $this->request('GET', $this->controlBaseUrl().'/v1/capabilities');
    }

    public function health(): array
    {
        return $this->request('GET', $this->controlBaseUrl().'/health');
    }

    /**
     * Probe the selected live-check route without submitting forum content.
     * This deliberately avoids the control plane so the admin can verify that
     * regional protection remains reachable during a control-plane outage.
     *
     * @return array{endpoint: string, health: array, check_ready: array}
     */
    public function checkRouteHealth(): array
    {
        $lastError = null;

        foreach ($this->checkCandidates() as $base) {
            try {
                $health = $this->request('GET', $base.'/health', [], self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                $this->recordEndpointResult($base, true, 0);
                return [
                    'endpoint' => $base,
                    'health' => $health,
                    // Kept as an alias for extensions consuming the old shape.
                    'check_ready' => $health,
                ];
            } catch (\Throwable $error) {
                $lastError = $error;
                $this->recordEndpointResult($base, false, 0);
            }
        }

        throw $lastError ?: new \RuntimeException('No selected Forum Fortress check endpoint is available.');
    }

    public function registerSite(string $email): array
    {
        $result = $this->requestControlWithIdentityRecovery('POST', '/v1/site/register', fn (): array => array_merge($this->commonPayload(), [
            'email' => trim($email),
        ]), true);
        $this->persistIdentity($result);
        return $result;
    }

    public function setAttackMode(bool $enabled): array
    {
        $path = $enabled ? '/v1/site/attack-mode' : '/v1/site/attack-mode/end';
        $response = $this->requestControlWithIdentityRecovery('POST', $path, fn (): array => $this->commonPayload());
        $active = null;
        if (array_key_exists('attack_mode_active', $response)) {
            $active = (bool) $response['attack_mode_active'];
        } elseif (array_key_exists('enabled', $response)) {
            $active = (bool) $response['enabled'];
        } elseif (is_array($response['attack_mode'] ?? null) && array_key_exists('enabled', $response['attack_mode'])) {
            $active = (bool) $response['attack_mode']['enabled'];
        }
        if ($active === null || $active !== $enabled) {
            throw new \RuntimeException(
                $enabled
                    ? 'Forum Fortress did not confirm that attack mode is active.'
                    : 'Forum Fortress did not confirm that attack mode has ended.'
            );
        }

        $response['attack_mode_active'] = $active;
        return $response;
    }

    public function portalLaunch(): array
    {
        return $this->requestControlWithIdentityRecovery('POST', '/v1/site/portal', fn (): array => $this->commonPayload());
    }

    public function deprovisionSite(string $reason = 'plugin_uninstall'): array
    {
        if ($this->apiKey() === '' || trim((string) $this->settings->get('forumfortress.site_id', '')) === '') {
            if (trim((string) $this->settings->get('forumfortress.bootstrap_recovery_token', '')) !== '') {
                // A bootstrap response may have been lost after the control plane
                // created the site. Recover that identity before uninstalling it.
                $this->bootstrapIfNeeded(true);
            }
            if ($this->apiKey() === '' || trim((string) $this->settings->get('forumfortress.site_id', '')) === '') {
                return ['status' => 'no_identity'];
            }
        }

        try {
            return $this->request('POST', $this->controlBaseUrl().'/v1/site/deprovision', array_merge(
                $this->commonPayload(),
                ['reason' => $reason]
            ), 3);
        } catch (EndpointRequestException $error) {
            if (strtolower((string) $error->errorCode) === 'stale_site') {
                // Keep the still-valid key, recover the current site linkage,
                // then retry so stale local metadata cannot strand an uninstall.
                $this->recoverIdentityOrRestore($error);
                return $this->request('POST', $this->controlBaseUrl().'/v1/site/deprovision', array_merge(
                    $this->commonPayload(),
                    ['reason' => $reason]
                ), 3);
            }
            if ($this->isAlreadyRemovedError($error)) {
                return ['status' => 'already_removed'];
            }
            throw $error;
        }
    }

    public function clearIdentity(): void
    {
        foreach (['api_key', 'site_id', 'bootstrap_recovery_token', 'preferred_endpoint'] as $key) {
            $this->settings->set('forumfortress.'.$key, '');
        }
        $this->settings->set('forumfortress.endpoint_state', '{}');
        $this->settings->set('forumfortress.dashboard_status', '{}');
        $this->settings->set('forumfortress.last_bootstrap_error', '');
    }

    public function moderationQueueSync(array $items): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-queue/sync', array_merge($this->commonPayload(), [
            'items' => $items,
            'block_reject_action' => $this->settings->get('forumfortress.block_reject_action', 'reject'),
        ]));
    }

    public function pullModerationActions(int $limit = 50): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-actions/pull', array_merge($this->commonPayload(), [
            'limit' => max(1, min(100, $limit)),
        ]));
    }

    public function acknowledgeModerationActions(array $results): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-actions/ack', array_merge($this->commonPayload(), [
            'results' => $results,
        ]));
    }

    public function sync(bool $forceBootstrap = false): array
    {
        if (! $this->isEnabled()) {
            return ['enabled' => false];
        }

        $this->refreshEndpointCatalog();
        $this->refreshEndpointHealth();
        $ping = $this->confirmConnection(null, $forceBootstrap);

        return ['enabled' => true, 'ping' => $ping, 'endpoint_state' => $this->endpointStateSummary()];
    }

    public function confirmConnection(?int $timeoutOverride = null, bool $forceBootstrap = false): array
    {
        $ping = $this->requestControlWithIdentityRecovery(
            'POST',
            '/v1/site/ping',
            fn (): array => $this->commonPayload(),
            $forceBootstrap,
            $timeoutOverride
        );
        $this->persistIdentity($ping);
        $state = $this->endpointState();
        $state['last_site_ping_at'] = time();
        $this->saveEndpointState($state);

        return $ping;
    }

    public function endpointStateSummary(): array
    {
        $state = $this->endpointState();
        return [
            'preferred' => $this->apiBaseUrl(),
            'catalog' => array_values((array) ($state['catalog'] ?? [])),
            'catalog_fetched_at' => (int) ($state['catalog_fetched_at'] ?? 0),
            'health' => [],
            'last_health_at' => (int) ($state['catalog_fetched_at'] ?? 0),
            'key_type' => (string) ($state['key_type'] ?? 'normal'),
            'rebootstrap_at' => (int) ($state['rebootstrap_at'] ?? 0),
            'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
        ];
    }

    public function userPayload(User $user, array $extra = []): array
    {
        $joinedAt = $user->joined_at;
        return array_merge([
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'account_age_seconds' => $joinedAt ? max(0, time() - $joinedAt->getTimestamp()) : 0,
            'post_count' => (int) $user->comment_count,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ], $extra);
    }

    public static function extractExternalLinks(string $content, string $forumDomain): array
    {
        preg_match_all('~https?://[^\\s<>\"\']+~i', $content, $matches);
        $links = [];
        foreach ($matches[0] ?? [] as $rawUrl) {
            $url = rtrim($rawUrl, '.,;:!?)]}');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $host !== $forumDomain && ! str_ends_with($host, '.'.$forumDomain)) {
                $links[] = $url;
            }
        }
        return array_values(array_unique($links));
    }

    public function domain(): string
    {
        return strtolower((string) parse_url((string) $this->config['url'], PHP_URL_HOST));
    }

    private function requestChecks(string $method, string $path, array $payload): array
    {
        $lastError = null;
        $started = microtime(true);

        foreach ($this->checkCandidates() as $base) {
            if ((microtime(true) - $started) >= self::CHECK_TOTAL_BUDGET_SECONDS) break;
            try {
                $attemptStarted = microtime(true);
                $result = $this->request($method, $base.$path, $payload, self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                $this->recordEndpointResult($base, true, (int) ((microtime(true) - $attemptStarted) * 1000));
                return $result;
            } catch (\Throwable $error) {
                $lastError = $error;
                $this->recordEndpointResult($base, false, 0);

				if ($this->apiRegion() !== 'global' && $base === $this->apiBaseUrl() && (! $error instanceof EndpointRequestException || $error->retryable)) {
					try {
						$result = $this->request($method, $base.$path, $payload, self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
						$this->recordEndpointResult($base, true, 0);
						return $result;
					} catch (\Throwable $retryError) {
						$lastError = $retryError;
						$error = $retryError;
						$this->recordEndpointResult($base, false, 0);
					}
				}

                if ($this->isStaleIdentityError($error)) {
                    $this->recoverIdentityOrRestore($error);
                    $payload = array_merge($payload, $this->commonPayload());
                    try {
                        $result = $this->request($method, $base.$path, $payload, self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                        $this->recordEndpointResult($base, true, 0);
                        return $result;
                    } catch (\Throwable $retryError) {
                        $lastError = $retryError;
                        $this->recordEndpointResult($base, false, 0);
                        $error = $retryError;
                    }
                }
                if ($error instanceof EndpointRequestException && ! $error->retryable) throw $error;
            }
        }

        throw $lastError ?: new \RuntimeException('No healthy Forum Fortress check endpoint is available.');
    }

    private function request(string $method, string $url, array $payload = [], ?int $timeoutOverride = null): array
    {
        $timeout = max(1, min(30, $timeoutOverride ?? $this->timeout()));
        $requestMethod = strtoupper($method);
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'ForumFortress-Flarum/'.self::PLUGIN_VERSION];
        if ($requestMethod === 'GET' && trim((string) ($payload['api_key'] ?? '')) !== '') {
            $headers['X-FF-Key'] = trim((string) $payload['api_key']);
            unset($payload['api_key']);
        }
        $options = [
            'connect_timeout' => min(2, $timeout),
            'timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => $headers,
        ];
        if ($payload !== []) {
            $options[$requestMethod === 'GET' ? 'query' : 'json'] = $payload;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            $detailValue = is_array($decoded) ? ($decoded['detail'] ?? $decoded) : null;
            $errorCode = null;
            if (is_array($detailValue)) {
                $errorCode = trim((string) ($detailValue['error'] ?? $detailValue['code'] ?? '')) ?: null;
            }
            $detail = is_array($detailValue) ? json_encode($detailValue) : trim((string) $detailValue);
            $retryable = ($status >= 200 && $status < 300)
                || $status === 0
                || in_array($status, [408, 425], true)
                || in_array($status, [500, 502, 503, 504], true);
            throw new EndpointRequestException(
                trim('Forum Fortress returned HTTP '.$status.' '.$detail),
                $status,
                $retryable,
                $errorCode
            );
        }
        return $decoded;
    }

    private function requestBootstrap(string $base, array $payload, int $timeout): array
    {
        try {
            return $this->request('POST', $base.'/v1/site/flarum/bootstrap', $payload, $timeout);
        } catch (EndpointRequestException $error) {
            if (! in_array($error->statusCode, [404, 405], true)) {
                throw $error;
            }

            // A rolling release can briefly put plugin 1.3 in front of an older
            // control plane. Its generic endpoint does not understand the
            // Flarum recovery token, but remains a safe compatibility fallback.
            $legacyPayload = $payload;
            unset($legacyPayload['bootstrap_recovery_token']);
            return $this->request('POST', $base.'/v1/site/bootstrap', $legacyPayload, $timeout);
        }
    }

    private function requestControlWithIdentityRecovery(
        string $method,
        string $path,
        callable $payload,
        bool $forceBootstrap = false,
        ?int $timeoutOverride = null
    ): array
    {
        try {
            $this->bootstrapIfNeeded($forceBootstrap);
        } catch (EndpointRequestException $error) {
            if (! $this->isStaleIdentityError($error)) {
                throw $error;
            }
            $this->recoverIdentityOrRestore($error);
        }
        try {
            return $this->request($method, $this->controlBaseUrl().$path, $payload(), $timeoutOverride);
        } catch (EndpointRequestException $error) {
            if (! $this->isStaleIdentityError($error)) {
                throw $error;
            }
            $this->recoverIdentityOrRestore($error);
            return $this->request($method, $this->controlBaseUrl().$path, $payload(), $timeoutOverride);
        }
    }

    private function isStaleIdentityError(\Throwable $error): bool
    {
        if (! $error instanceof EndpointRequestException) {
            return str_contains(strtolower($error->getMessage()), 'node_mismatch');
        }
        if ($error->statusCode === 401) {
            return true;
        }
        if (in_array(strtolower((string) $error->errorCode), [
            'invalid_key',
            'invalid_api_key',
            'node_mismatch',
            'stale_site',
            'site_not_found',
        ], true)) {
            return true;
        }
        $message = strtolower($error->getMessage());
        return str_contains($message, 'node_mismatch') || str_contains($message, 'api key not recognised');
    }

    private function isAlreadyRemovedError(EndpointRequestException $error): bool
    {
        // A missing site is idempotent only when the control plane explicitly
        // identifies the response as site_not_found. A bare 404/410 can be a
        // routing, authentication, or deployment error and must remain a
        // pending cleanup failure so local identity is not erased.
        return EndpointRequestException::isExplicitSiteNotFound($error->statusCode, $error->errorCode);
    }

    private function prepareIdentityRecovery(\Throwable $error): void
    {
        if ($error instanceof EndpointRequestException && strtolower((string) $error->errorCode) === 'stale_site') {
            // The key is valid, but the locally retained site identifier is not.
            // Keep the key so authenticated bootstrap can repair the linkage.
            $this->settings->set('forumfortress.site_id', '');
            $this->settings->set('forumfortress.dashboard_status', '{}');
            return;
        }
        $this->clearIdentity();
    }

    private function recoverIdentityOrRestore(\Throwable $error): void
    {
        $settingNames = [
            'api_key',
            'site_id',
            'bootstrap_recovery_token',
            'preferred_endpoint',
            'endpoint_state',
            'dashboard_status',
            'enabled',
            'bootstrap_suppressed',
        ];
        $snapshot = [];
        foreach ($settingNames as $name) {
            $snapshot[$name] = (string) $this->settings->get('forumfortress.'.$name, '');
        }

        $this->prepareIdentityRecovery($error);
        try {
            $this->bootstrapIfNeeded(true);
        } catch (\Throwable $recoveryError) {
            // A valid credential can be briefly unknown to a newly selected
            // edge. If the control plane declines cautious recovery for an
            // already-synced site, preserve the last stored identity instead
            // of turning a transient 401 into permanent local data loss.
            foreach ($snapshot as $name => $value) {
                $this->settings->set('forumfortress.'.$name, $value);
            }
            throw $recoveryError;
        }
    }

    private function commonPayload(): array
    {
        return array_filter([
            'api_key' => $this->apiKey(),
            'site_id' => (string) $this->settings->get('forumfortress.site_id', ''),
            'domain' => $this->domain(),
            'platform' => 'flarum',
            'platform_version' => Application::VERSION,
            'plugin_version' => self::PLUGIN_VERSION,
        ], static fn ($value) => $value !== '');
    }

    private function persistIdentity(array $result): void
    {
        foreach (['api_key', 'site_id'] as $key) {
            $value = trim((string) ($result[$key] ?? ''));
            if ($value !== '') {
                $this->settings->set('forumfortress.'.$key, $value);
            }
        }
        $this->settings->set('forumfortress.bootstrap_recovery_token', '');
        $this->settings->set('forumfortress.last_bootstrap_error', '');
        $this->settings->set('forumfortress.bootstrap_suppressed', '0');
        $this->settings->set('forumfortress.enabled', '1');

        $state = $this->endpointState();
        $state['key_type'] = (string) ($result['key_type'] ?? $state['key_type'] ?? 'normal');
        if ($state['key_type'] === 'offline_bootstrap' || str_starts_with((string) ($result['api_key'] ?? ''), 'ff_ob_')) {
            $state['offline_preferred_endpoint'] = $this->normalizeBaseUrl((string) ($result['preferred_endpoint'] ?? ''));
        } else {
            unset($state['offline_preferred_endpoint']);
            $this->settings->set('forumfortress.preferred_endpoint', $this->apiBaseUrl());
        }
        if (isset($result['rebootstrap_after_seconds'])) {
            $state['rebootstrap_at'] = time() + max(60, (int) $result['rebootstrap_after_seconds']);
        } elseif (! str_starts_with((string) ($result['api_key'] ?? $this->apiKey()), 'ff_ob_')) {
            $state['rebootstrap_at'] = 0;
        }
        $fallbacks = $result['fallback_bootstrap_endpoints'] ?? [];
        if (is_array($fallbacks) && $fallbacks !== []) {
            $state['fallback_bootstrap_endpoints'] = array_values(array_filter(array_map([$this, 'normalizeBaseUrl'], $fallbacks)));
        }
        $this->saveEndpointState($state);
    }

    private function checkCandidates(): array
    {
        $state = $this->endpointState();
        if ($this->apiRegion() !== 'global') {
            return array_values(array_filter([
                $this->apiBaseUrl(),
                $this->allowGlobalEmergencyFallback() ? 'https://api.ffapi.net' : null,
            ]));
        }
        $offlinePreferred = $this->normalizeBaseUrl((string) ($state['offline_preferred_endpoint'] ?? ''));
        if (str_starts_with($this->apiKey(), 'ff_ob_') && $offlinePreferred !== '') {
            return [$offlinePreferred];
        }

        $meta = (array) ($state['endpoint_meta'] ?? []);
        $catalog = array_values((array) ($state['catalog'] ?? []));
        $edges = array_values(array_filter(array_map([$this, 'normalizeBaseUrl'], $catalog), static function (string $base) use ($meta): bool {
            $role = strtolower((string) ($meta[$base]['role'] ?? ''));
            if (in_array($role, ['backup', 'control', 'control-fallback'], true)) return false;
            return ($meta[$base]['check_ready'] ?? true) !== false;
        }));
        $controlAllowed = ! empty($state['control_check_fallback']) || $edges === [];
        return array_values(array_unique(array_filter(array_merge(
            [$this->apiBaseUrl()],
            $edges,
            $controlAllowed ? [$this->controlBaseUrl()] : []
        ))));
    }

    private function bootstrapCandidates(): array
    {
        $state = $this->endpointState();
        return array_merge((array) ($state['fallback_bootstrap_endpoints'] ?? []), (array) ($state['catalog'] ?? []), [$this->apiBaseUrl()]);
    }

    private function extractEndpointCatalog(mixed $value): array
    {
        $urls = [];
        $meta = [];
        $walk = function (mixed $node, ?string $key = null) use (&$walk, &$urls, &$meta): void {
            if (is_array($node)) {
                $rawUrl = $node['url'] ?? $node['base_url'] ?? $node['endpoint'] ?? null;
                if (is_string($rawUrl)) {
                    $url = $this->normalizeBaseUrl($rawUrl);
                    if ($url !== '') {
                        $urls[] = $url;
                        $meta[$url] = [
                            'role' => strtolower((string) ($node['role'] ?? 'edge')),
                            'node_id' => (string) ($node['node_id'] ?? ''),
                            'check_ready' => (bool) ($node['check_ready'] ?? $node['check_capable'] ?? false),
                        ];
                    }
                }
                foreach ($node as $childKey => $child) $walk($child, (string) $childKey);
            } elseif (is_string($node) && in_array($key, ['base_url', 'endpoint', 'url'], true)) {
                $url = $this->normalizeBaseUrl($node);
                if ($url !== '') $urls[] = $url;
            }
        };
        $walk($value);
        return [array_values(array_unique($urls)), $meta];
    }

    private function recordEndpointResult(string $base, bool $success, int $latencyMs): void
    {
        $base = $this->normalizeBaseUrl($base);
        if ($base === '') return;
        $state = $this->endpointState();
        if ($success) {
            $state['last_responded'] = $base;
            $state['last_response_at'] = time();
        } else {
            $state['last_failure'] = ['base' => $base, 'at' => time()];
        }
        $this->saveEndpointState($state);
    }

    private function endpointState(): array
    {
        $decoded = json_decode((string) $this->settings->get('forumfortress.endpoint_state', '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveEndpointState(array $state): void
    {
        $this->settings->set('forumfortress.endpoint_state', json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeBaseUrl(mixed $url): string
    {
        $url = rtrim(trim((string) $url), '/');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'https' ? $url : '';
    }

    private function apiKey(): string
    {
        return trim((string) $this->settings->get('forumfortress.api_key', ''));
    }

    private function bootstrapRecoveryToken(): string
    {
        $current = trim((string) $this->settings->get('forumfortress.bootstrap_recovery_token', ''));
        if (strlen($current) >= 32 && strlen($current) <= 200) {
            return $current;
        }
        $token = 'ff_br_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->settings->set('forumfortress.bootstrap_recovery_token', $token);
        return $token;
    }

    private function apiBaseUrl(): string
    {
        return [
            'global' => 'https://api.ffapi.net',
            'uk' => 'https://api-uk.ffapi.net',
            'eu' => 'https://api-eu.ffapi.net',
            'us' => 'https://api-us.ffapi.net',
        ][$this->apiRegion()];
    }

    private function apiRegion(): string
    {
        $region = strtolower(trim((string) $this->settings->get('forumfortress.api_region', '')));
        if (in_array($region, ['global', 'uk', 'eu', 'us'], true)) return $region;
        return match (strtolower($this->normalizeBaseUrl($this->settings->get('forumfortress.api_base_url', '')))) {
            'https://api-uk.ffapi.net' => 'uk', 'https://api-eu.ffapi.net' => 'eu', 'https://api-us.ffapi.net' => 'us', default => 'global',
        };
    }

    private function allowGlobalEmergencyFallback(): bool
    {
        return $this->settings->get('forumfortress.allow_global_fallback', '0') === '1';
    }

    private function controlBaseUrl(): string
    {
        return self::CONTROL_BASE_URL;
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) $this->settings->get('forumfortress.timeout', '5')));
    }

    private function failOpen(): bool
    {
        return $this->settings->get('forumfortress.fail_open', '1') === '1';
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        if ($this->settings->get('forumfortress.debug_log', '0') === '1') {
            $this->logger->warning('Forum Fortress {operation} failed: {message}. Support: {support}', [
                'operation' => $operation,
                'message' => $error->getMessage(),
                'support' => self::SUPPORT_URL,
                'exception' => $error,
            ]);
        }
    }
}
