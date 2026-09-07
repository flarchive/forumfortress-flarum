<?php

namespace ForumFortress\Flarum\Console;

use Flarum\Console\AbstractCommand;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Moderation\Bridge;

final class ModerationSyncCommand extends AbstractCommand
{
    public function __construct(private Bridge $bridge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('forumfortress:moderation-sync')->setDescription('Synchronize Flarum Approval with Forum Fortress moderation.');
    }

    protected function fire(): int
    {
        try {
            $result = $this->bridge->sync();
            if (($result['skipped'] ?? false) === true) {
                $this->info((string) ($result['reason'] ?? 'Moderation synchronization is unavailable.'));
                return 0;
            }
            $this->info(sprintf('Synchronized %d queue items and processed %d actions.', $result['queue_items'], $result['actions']));
            return 0;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            $this->info('Support: '.ForumFortressClient::SUPPORT_URL);
            return 1;
        }
    }
}
