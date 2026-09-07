<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later

use Flarum\Extend;
use ForumFortress\Flarum\Api\AdminController;
use ForumFortress\Flarum\Api\PortalController;
use ForumFortress\Flarum\Console\ModerationSyncCommand;
use ForumFortress\Flarum\Console\SyncCommand;
use ForumFortress\Flarum\Lifecycle;
use ForumFortress\Flarum\Listener;
use ForumFortress\Flarum\Middleware\BackgroundBootstrap;
use ForumFortress\Flarum\PackageRemovalLifecycle;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;

$adminJavaScript = str_starts_with(Flarum\Foundation\Application::VERSION, '1.')
    ? __DIR__.'/js/dist/admin.v1.js'
    : __DIR__.'/js/dist/admin.js';

return [
    new Lifecycle(),
    new PackageRemovalLifecycle(),

    (new Extend\Frontend('admin'))
        ->js($adminJavaScript)
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('forumfortress.enabled', '1')
        ->default('forumfortress.api_base_url', 'https://api.ffapi.net')
        ->default('forumfortress.api_region', 'global')
        ->default('forumfortress.allow_global_fallback', '0')
        ->default('forumfortress.api_key', '')
        ->default('forumfortress.site_id', '')
        ->default('forumfortress.bootstrap_recovery_token', '')
        ->default('forumfortress.bootstrap_suppressed', '0')
        ->default('forumfortress.preferred_endpoint', '')
        ->default('forumfortress.endpoint_state', '{}')
        ->default('forumfortress.dashboard_status', '{}')
        ->default('forumfortress.last_bootstrap_error', '')
        ->default('forumfortress.last_bootstrap_at', '0')
        ->default('forumfortress.last_background_recovery_at', '0')
        ->default('forumfortress.deprovision_pending', '0')
        ->default('forumfortress.last_deprovision_error', '')
        ->default('forumfortress.last_deprovision_reason', '')
        ->default('forumfortress.last_deprovision_at', '0')
        ->default('forumfortress.registration_email', '')
        ->default('forumfortress.block_reject_action', 'reject')
        ->default('forumfortress.timeout', '5')
        ->default('forumfortress.fail_open', '1')
        ->default('forumfortress.debug_log', '0'),

    (new Extend\Middleware('forum'))
        ->add(BackgroundBootstrap::class),

    (new Extend\Event())
        ->listen(Flarum\User\Event\Saving::class, Listener\CheckRegistration::class)
        ->listen(Flarum\User\Event\Saving::class, Listener\CheckProfile::class)
        ->listen(Flarum\Post\Event\Saving::class, Listener\CheckPost::class),

    (new Extend\Routes('api'))
        ->get('/forumfortress/status', 'forumfortress.status', AdminController::class)
        ->get('/forumfortress/portal-launch', 'forumfortress.portal.launch', PortalController::class)
        ->post('/forumfortress/register', 'forumfortress.register', AdminController::class)
        ->post('/forumfortress/attack-mode', 'forumfortress.attack.start', AdminController::class)
        ->post('/forumfortress/attack-mode/end', 'forumfortress.attack.end', AdminController::class)
        ->post('/forumfortress/portal', 'forumfortress.portal', AdminController::class)
        ->post('/forumfortress/test', 'forumfortress.test', AdminController::class)
        ->post('/forumfortress/sync', 'forumfortress.sync', AdminController::class)
        ->post('/forumfortress/deprovision', 'forumfortress.deprovision', AdminController::class),

    (new Extend\Console())
        ->command(SyncCommand::class)
        ->command(ModerationSyncCommand::class)
        ->schedule('forumfortress:sync', function (ScheduleEvent $event): void {
            $event->hourly()->withoutOverlapping();
        })
        ->schedule('forumfortress:moderation-sync', function (ScheduleEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),
];
