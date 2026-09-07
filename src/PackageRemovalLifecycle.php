<?php

namespace ForumFortress\Flarum;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

final class PackageRemovalLifecycle implements ExtenderInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
        $removedEvent = 'Flarum\\ExtensionManager\\Extension\\Event\\Removed';
        if (! class_exists($removedEvent)) {
            return;
        }

        // Resolve and capture the concrete service while this package still
        // exists. Extension Manager dispatches Removed only after Composer has
        // deleted the package, when class-string listeners can no longer load.
        $uninstall = $container->make(UninstallManager::class);
        $container->make(Dispatcher::class)->listen(
            $removedEvent,
            static function (object $event) use ($uninstall): void {
                $removed = $event->extension ?? null;
                if (! $removed instanceof Extension || $removed->getId() !== 'forumfortress-flarum') {
                    return;
                }
                $uninstall->deprovision('plugin_uninstall');
            }
        );
    }
}
