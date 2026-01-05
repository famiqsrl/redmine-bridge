<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Symfony\DependencyInjection;

use Famiq\RedmineBridge\Contracts\Interfaces\ClienteServiceInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\ContactResolverInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\IdempotencyStoreInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\TicketServiceInterface;
use Famiq\RedmineBridge\Infrastructure\Redmine\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\Infrastructure\Redmine\Contacts\ContactResolverSelector;
use Famiq\RedmineBridge\Infrastructure\Redmine\Contacts\CustomFieldContactResolver;
use Famiq\RedmineBridge\Infrastructure\Redmine\Contacts\FallbackContactResolver;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineClienteService;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineConfig;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineHttpClient;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedminePayloadMapper;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineTicketService;
use Famiq\RedmineBridge\Infrastructure\Symfony\SymfonyIdempotencyStore;
use Famiq\RedmineBridge\Infrastructure\Symfony\RedmineBridgeCheckCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;

final class RedmineBridgeExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->register(RedmineConfig::class)
            ->setArguments([
                $config['base_url'],
                $config['api_key'],
                $config['project_id'],
                $config['tracker_id'],
                $config['custom_fields'],
                $config['contacts_api_base'],
                $config['contacts_search_path'],
                $config['contacts_upsert_path'],
                $config['contact_strategy'],
            ]);

        $container->register(RedmineHttpClient::class)
            ->setArguments([
                new Psr18Client(),
                new Reference(RedmineConfig::class),
                new Reference('logger'),
            ]);

        $container->register(RedminePayloadMapper::class);

        $container->register(IdempotencyStoreInterface::class, SymfonyIdempotencyStore::class)
            ->setArguments([new Reference('doctrine.dbal.default_connection')]);

        $container->register(ApiContactResolver::class)
            ->setArguments([
                new Reference(RedmineHttpClient::class),
                new Reference(RedmineConfig::class),
                new Reference('logger'),
            ]);

        $container->register(CustomFieldContactResolver::class)
            ->setArguments([new Reference('logger')]);

        $container->register(FallbackContactResolver::class)
            ->setArguments([new Reference('logger')]);

        $container->register(ContactResolverInterface::class, ContactResolverSelector::class)
            ->setArguments([
                new Reference(RedmineConfig::class),
                new Reference(ApiContactResolver::class),
                new Reference(CustomFieldContactResolver::class),
                new Reference(FallbackContactResolver::class),
            ]);

        $container->register(ClienteServiceInterface::class, RedmineClienteService::class)
            ->setArguments([
                new Reference(ContactResolverInterface::class),
                new Reference('logger'),
            ]);

        $container->register(TicketServiceInterface::class, RedmineTicketService::class)
            ->setArguments([
                new Reference(RedmineHttpClient::class),
                new Reference(RedmineConfig::class),
                new Reference(RedminePayloadMapper::class),
                new Reference(IdempotencyStoreInterface::class),
                new Reference('logger'),
            ]);

        $container->register(RedmineBridgeCheckCommand::class)
            ->setArguments([
                new Reference(RedmineHttpClient::class),
                new Reference(RedmineConfig::class),
            ])
            ->addTag('console.command');
    }
}
