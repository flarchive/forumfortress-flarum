# Forum Fortress for Flarum 1.8.x and 2.x

Forum Fortress adds cloud-based spam and abuse protection to Flarum 1.8.x and 2.x. It checks supported forum activity with the Forum Fortress service and returns a simple `ALLOW` or `BLOCK` decision before Flarum completes the action.

Current release: `1.4.2`.

## Features

- Automatic site bootstrap on extension enable, with no API key required.
- Registration, topic, reply, post-edit, and supported profile/signature checks.
- Immediate enforcement of `BLOCK` decisions through native Flarum errors.
- Regional endpoint selection, failover, timeout, and fail-open controls.
- Native Flarum Approval and moderation synchronization.
- Attack mode, connection test, usage status, portal access, and manual sync controls in Flarum Admin.
- Scheduled heartbeat and moderation synchronization through Flarum's scheduler.

## Requirements

- Flarum `1.8.x` (tested on `1.8.20`) or `2.x` (tested through
  `2.0.0-rc.8`).
- PHP 8.0 or newer (Flarum 2 itself requires the newer PHP version supported by that release).
- Outbound HTTPS access to Forum Fortress services.

Flarum Approval and Flags are optional. When both are enabled, Forum Fortress
also synchronizes their moderation queue; their disabled state never blocks the
main protection extension from activating.

The package includes separate Flarum 1 and Flarum 2 admin bundles generated from
the same TypeScript source. The appropriate bundle is selected automatically.

## Tracked activity

Forum Fortress checks only initial registrations, new topics, replies, post
edits, and supported profile changes including signatures. The extension does
not send login events or moderation hide/restore/approve events. Flarum does
not provide a contact-form event in this extension; contact forms remain
outside the plugin's activity tracking.

## Install

Or search in Extension Manager for **Anti-Spam – Forum Fortress**. In **Administration >
Extensions**, choose **Find more extensions** or **Install extension**, search
for the official `forumfortress/flarum` package, select **Install**, then
**Enable** it. Open the Anti-Spam – Forum Fortress administration page and run **Refresh**
and **Connection test** after enabling.

From the Flarum root:

```bash
composer require forumfortress/flarum:"^1.4"
php flarum extension:enable forumfortress-flarum
php flarum cache:clear
```

The supported Flarum and Guzzle packages are already present in standard Flarum
1.8 and 2 installations. This scoped command installs Forum Fortress without
updating their locked versions. Do not add Composer's `-W` or
`--with-all-dependencies` option.

Enabling the extension immediately performs a short, best-effort bootstrap. A
temporary network problem will not prevent Flarum from enabling the extension;
the next protected request, status refresh, or scheduled synchronization retries
automatically. Open **Administration > Extensions > Anti-Spam – Forum Fortress** to confirm
the live status. Bootstrap retries use a short-lived, client-held recovery token,
so a response lost after the remote site is created cannot strand the install.

## Removal and reinstall

Flarum's native **Purge** action automatically notifies Forum Fortress and
removes the remote forum. The current release also listens for Extension Manager's
post-Composer removal event while the extension is loaded. If the extension is
already disabled, it cannot register that listener or display its maintenance
panel. Re-enable it first, then open the Forum Fortress maintenance panel and
choose **Disconnect and remove site** before removing the Composer package.
That action pauses automatic bootstrap until the extension is explicitly
re-enabled or reinstalled.

If remote cleanup fails because Forum Fortress is temporarily unreachable,
removal remains non-blocking and the local identity is retained. A later
reinstall retries the pending cleanup before bootstrapping and shows a direct
support warning if it still cannot finish.

When the account contains other forums, only this forum is removed. When it is
the last forum, a paid non-trial account is retained; free, trial, and overdue
accounts are removed. Local credentials are cleared only after successful
deprovisioning, allowing a failed removal or a later reinstall to recover safely.

For scheduler setup, configuration, updates, removal, and troubleshooting, use the full installation guide.

## API region lock

The administrator can select Global (the default), United Kingdom only,
European Union only, or United States only. Regional modes keep live check
traffic on the matching `api-uk`, `api-eu`, or `api-us` hostname. The optional
global emergency fallback is disabled by default. When enabled, the plugin
retries the regional hostname first and may then use `api.ffapi.net`; processing
may consequently occur outside the selected region. Endpoint URLs are not
free-form administrator settings.
Global mode uses `https://api.ffapi.net`. Regional mode uses only its
configured regional endpoint unless global emergency fallback is enabled; with
fallback enabled, requests try the regional endpoint, then `api.ffapi.net`.
The plugin relies on GeoDNS and
does not fetch an endpoint catalogue or probe service health routes. Portal and
attack-mode actions continue to prefer the global control plane.

The scheduler checks every ten minutes. Heartbeats are limited to once per hour
for standard plans and increase to every ten minutes for Pro/MultiMod forums;
moderation synchronization runs every five minutes. These jobs require Flarum's
scheduler to be configured and running.

## Documentation and support

- [Flarum 1.8.x and 2.x installation and configuration](https://forumfortress.com/docs/install/flarum/)
- [Forum Fortress documentation](https://forumfortress.com/docs/)
- [Support](https://forumfortress.com/#support)
- [Contact support](https://forumfortress.com/#contact)
- [Service status](https://status.forumfortress.com/)

## License

The Forum Fortress plugin is free and open-source software licensed under the
GNU General Public License, version 2 or later (`GPL-2.0-or-later`). See
[LICENSE](LICENSE) and [NOTICE](NOTICE).

The Forum Fortress hosted service is separate and is governed by its service
terms. The plugin licence does not provide a subscription, credentials or
access to private backend code. Contributions use the same project licence,
contributors retain their copyright, and no contributor licence agreement or
copyright assignment is required; see [CONTRIBUTING.md](CONTRIBUTING.md).
