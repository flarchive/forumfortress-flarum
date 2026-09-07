<?php

namespace ForumFortress\Flarum\Console;

use Flarum\Console\AbstractCommand;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Symfony\Component\Console\Input\InputOption;

final class SyncCommand extends AbstractCommand
{
    public function __construct(private ForumFortressClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('forumfortress:sync')
            ->setDescription('Bootstrap or ping the Forum Fortress service.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Retry bootstrap immediately instead of observing the automatic retry backoff.'
            );
    }

    protected function fire(): int
    {
        try {
            if (! $this->client->isEnabled()) {
                $this->info('Forum Fortress is disabled.');
                return 0;
            }

            // The scheduler invokes this command without options. Only an
            // explicit --force bypasses the automatic bootstrap backoff.
            $forceBootstrap = (bool) $this->input->getOption('force');
            if (($this->client->sync($forceBootstrap)['enabled'] ?? false) === true) {
                $this->info('Forum Fortress synchronization completed.');
                return 0;
            }

            $this->error('Forum Fortress synchronization was not completed.');
            return 1;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            $this->info('Support: '.ForumFortressClient::SUPPORT_URL);
            return 1;
        }
    }
}
