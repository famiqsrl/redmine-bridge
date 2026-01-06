<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Symfony;

use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\RedmineConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'redmine:bridge:check', description: 'Verifica conectividad con Redmine')]
final class RedmineBridgeCheckCommand extends Command
{
    public function __construct(
        private readonly RedmineHttpClient $client,
        private readonly RedmineConfig $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = sprintf('/projects/%d.json', $this->config->projectId);
        $this->client->request('GET', $path, null, [], null);
        $output->writeln('<info>Redmine bridge OK</info>');

        return Command::SUCCESS;
    }
}
