# Changelog

All notable changes to Forum Fortress for Flarum are documented here.

## 1.4.2 - 2026-09-25

- Updated naming.

## 1.4.1 - 2026-09-18

- Route lifecycle and bootstrap requests through the resilient public API path.

## 1.4.0 - 2026-09-11

- Replace plugin-managed endpoint catalogues and `/health` probes with
  deterministic GeoDNS routing. Global requests use `api.ffapi.net` followed
  by `fortress.ffapi.net`; regional requests remain locked unless global
  fallback is enabled, in which case they use the regional endpoint, GeoDNS,
  then Fortress.
- Run the scheduler every ten minutes while limiting standard-plan heartbeats
  to hourly and allowing Pro/MultiMod heartbeats every ten minutes.
- Align moderation synchronization to a five-minute schedule.
- Make connection tests verify the authenticated site-ping route instead of
  treating unauthenticated health responses as proof of a working connection.
- Redact credentials from request failures, avoid logging exception objects
  that can retain authenticated headers, and reduce successful-request setting
  write churn.

## 1.3.7.2 - 2026-09-07

- First release licensed as free and open-source software under
  `GPL-2.0-or-later`.
- Add the complete GPLv2 text, project notice and contribution terms, keeping
  the separately governed Forum Fortress hosted service outside the package
  licence.

## 1.3.7.1 - 2026-08-28

- Remove client-side endpoint probing and latency selection. Normal requests
  now start at GeoDNS and use catalog entries only as same-request fallbacks.
- Certify compatibility with Flarum Core, Approval, Flags, and Suspend
  `2.0.0-rc.8` on PHP 8.4, including clean install, activation, migrations,
  live bootstrap, lost-response recovery, checks, removal, reinstall, and
  reactivation.
- Keep Flarum 1.8 support and the existing Flarum 2 RC5-or-newer Composer
  constraint while making the latest tested Flarum release explicit in public
  package metadata.

## 1.3.7 - 2026-08-27

- Retry an incomplete anonymous bootstrap from ordinary public GET traffic,
  with a five-minute backoff, so forums recover even when the Flarum scheduler
  is not running and no administrator revisits the extension.
- Persist identity fields returned by site status, repairing installations
  interrupted between storing their API key and site ID.
- Confirm recovered credentials with an authenticated ping and keep retrying
  from public traffic until that two-way handshake succeeds.
- Apply the same stale-key recovery path when a forced scheduled/admin sync
  encounters an invalid stored key during its initial bootstrap attempt.

## 1.3.6 - 2026-08-25

- Require HTTPS for every service endpoint, including local and private
  deployments, with no plaintext transport exception.
- Use the public `/health` contract for reachability and stop requesting the
  internal `/v1/check-ready` route.

## 1.3.5 - 2026-08-25

- Verify compatibility with Flarum Core `2.0.0-rc.7`, Approval
  `2.0.0-rc.7`, and Flags `2.0.0-rc.7`, including an upgrade from the
  existing `2.0.0-rc.5` test forum.
- Retain Flarum 1.8 support and repeat the full activation, bootstrap,
  scheduler, removal, reinstall, and reactivation checks on Flarum Core
  `1.8.19`, Approval `1.8.2`, and Flags `1.8.2`.
- Add app-bootstrapped administration component tests using Flarum's
  standalone frontend test tooling while continuing to build and validate
  separate Flarum 1.8 and 2.x administration bundles.
- Use the public `/health` response as the plugin readiness contract and stop
  requesting the removed `/v1/check-ready` endpoint.
- Require HTTPS for service endpoints and trusted portal redirects, removing
  the legacy plaintext localhost overrides from production plugin code.

## 1.3.4 - 2026-08-21

- Respect the selected regional route during connection tests.

## 1.3.3 - 2026-08-21

- Remove the configurable control-plane URL and use the stable public plugin
  service at `https://fortress.ffapi.net`.

## 1.3.2 - 2026-08-21

- Replace the free-form API endpoint with a Global, UK, EU, or US region
  selector. Global remains the recommended default.
- Add an opt-in global emergency fallback for region-locked checks. Regional
  checks retry the selected hostname before any permitted global fallback.

## 1.3.0 - 2026-08-15

- Bootstrap automatically when the extension is enabled, with a bounded timeout
  that cannot prevent Flarum from completing activation.
- Make bootstrap retries idempotent with a short-lived recovery token, preventing
  a lost first response from leaving the extension enabled without credentials.
- Treat Approval and Flags as optional moderation integrations so their disabled
  state cannot prevent Forum Fortress from activating on a clean Flarum install.
- Recover automatically when retained plugin credentials refer to a site that
  was previously removed.
- Add authenticated remote deprovisioning for native Flarum purge and an
  explicit disconnect action for Extension Manager removal workflows.
- Handle Extension Manager's removal event while the extension is loaded and
  suppress automatic re-bootstrap after an intentional manual disconnect.
- Preserve accounts with sibling forums, retain paid non-trial accounts, and
  remove free, trial, or overdue accounts when their last forum is removed.
- Link administration, portal, and console errors directly to Forum Fortress
  support, and retain local credentials when remote cleanup fails.
- Move GET credentials out of URL query strings, validate decision and
  moderation-action allowlists, restrict portal redirects, and shorten
  non-critical report and enable-time network work.
- Keep console diagnostics compatible with both supported Flarum series and
  preserve the configured control-plane error when fallback endpoints also
  fail, with a direct support link on every command failure.
- Keep scheduled heartbeat syncs on normal bootstrap backoff while allowing
  operators to request an immediate retry with `forumfortress:sync --force`.
- Persist and retry interrupted uninstall cleanup on reinstall, and show
  disconnected or pending-cleanup states accurately in Flarum Admin.
- Distinguish an already-removed site from an invalid or revoked credential so
  existing forums remain pending for cleanup instead of being silently orphaned.
- Commit site identity before optional notifications, dispatch slow edge and
  Pushover work off the response path, and remove deleted forum key snapshots
  from edge nodes so activation and reinstall are not delayed by integrations.
- Make concurrent bootstrap of shared test-harness sites idempotent, verify the
  administration TypeScript against both supported Flarum type surfaces, and
  include the support link in debug-log failures.

## 1.2.0 - 2026-08-14

- Publish the Extension Manager-compatible administration bundles for Flarum
  1.8.19 and 2.0, so installations do not need a local frontend build step.

## 1.1.6 - 2026-08-14

- Include the compiled Flarum 1.8 and 2 administration bundles in Composer
  distribution archives so Extension Manager installations can load the admin
  interface without a local Node or Composer build step.

## 1.1.5 - 2026-08-14

- Verified compatibility with Flarum 1.8.19 using Flarum Core 1.8.19,
  Approval 1.8.2, and Flags 1.8.2.
- Confirmed clean activation, migrations, public and administration rendering,
  API controls, scheduled commands, endpoint synchronization, and enforcement
  checks on Flarum 1.8.19.

## 1.1.4 - 2026-08-13

- Removed activation/email-verification reporting. Registration is reported
  once after the account is created, matching the other integrations.

## 1.1.3 - 2026-08-13

- Removed activation/email-verification reporting. Registration is reported
  once after the account is created, matching the other integrations.

## 1.1.2 - 2026-08-11

- Verified compatibility with Flarum 1.8.18 using Flarum Core 1.8.18,
  Approval 1.8.2, and Flags 1.8.2.
- No compatibility code changes were required.
- Updated the plugin version reported to Forum Fortress services.

## 1.1.1 - 2026-08-11

- Tighten endpoint selection and fast failover for control-plane and edge
  requests.
- Keep endpoint observations available for portal diagnostics without adding a
  plugin-specific API surface.
- Refresh the administration control plane styling and release metadata.

## 1.1.0 - 2026-08-08

- Added support for Flarum 1.8 while retaining Flarum 2 support in one Composer package.
- Added version-specific Flarum 1 and Flarum 2 admin bundles selected automatically at runtime.
- Restored the translated administration dashboard and shield icon on both supported Flarum series.
- Restored portal login as a direct browser link so popup creation and authentication work on both versions.
- Confirmed clean automatic bootstrap, authenticated control access, and moderation traffic against the production service on both Flarum 1.8 and 2.
- Kept existing Flarum and framework dependency versions untouched during installation.

## 1.0.1 - 2026-08-05

- Made site registration email optional and left it blank by default.
- Kept Register Site behavior aligned with automatic bootstrap and non-empty email-only validation for optional account attachment.

## 1.0.0 - 2026-08-02

- Initial public release for Flarum 2.
- Added automatic site bootstrap and regional endpoint failover.
- Added registration, login, topic, reply, edit, and profile checks.
- Added native approval/moderation synchronization and reporting.
- Added Flarum Admin status, connection, portal, attack-mode, and synchronization controls.
