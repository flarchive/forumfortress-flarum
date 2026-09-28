<?php

use ForumFortress\Flarum\UninstallManager;
use Illuminate\Database\Schema\Builder;

return [
    'up' => static function (Builder $schema): void {
        // The migration record gives Flarum's native Purge action a reliable
        // uninstall callback without creating plugin-owned database tables.
    },
    'down' => static function (Builder $schema): void {
        try {
            resolve(UninstallManager::class)->deprovision('plugin_uninstall');
        } catch (\Throwable $error) {
            // A network outage must never prevent Flarum from completing purge.
        }
    },
];
