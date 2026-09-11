<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PortalController implements RequestHandlerInterface
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        try {
            $result = $this->client->portalLaunch();
            $url = trim((string) ($result['portal_url'] ?? ''));

            $parts = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $trustedHost = $host === 'forumfortress.com'
                || str_ends_with($host, '.forumfortress.com')
                || $host === 'ffapi.net'
                || str_ends_with($host, '.ffapi.net');
            $path = is_array($parts) ? '/' . ltrim((string) ($parts['path'] ?? ''), '/') : '';
            $hasUserInfo = is_array($parts)
                && (array_key_exists('user', $parts) || array_key_exists('pass', $parts));
            $hasFragment = is_array($parts) && array_key_exists('fragment', $parts);
            parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);

            if (
                $url === ''
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || !is_array($parts)
                || ! ($scheme === 'https' && $trustedHost)
                || $hasUserInfo
                || $hasFragment
                || !in_array((int) ($parts['port'] ?? 443), [443], true)
                || rtrim($path, '/') !== '/access'
                || !is_string($query['token'] ?? null)
                || trim($query['token']) === ''
            ) {
                throw new \UnexpectedValueException('Forum Fortress did not return a valid portal URL.');
            }

            return new RedirectResponse($url);
        } catch (\Throwable $error) {
            $message = 'Forum Fortress could not open the portal. Check the plugin connection and try again.';

            return new HtmlResponse(
                '<!doctype html><html lang="en"><meta charset="utf-8"><title>Forum Fortress Portal</title>'
                . '<body><h1>Portal launch failed</h1><p>' . $message . '</p><p><a href="'
                . ForumFortressClient::SUPPORT_URL
                . '">Contact Forum Fortress support</a></p></body></html>',
                502
            );
        }
    }
}
