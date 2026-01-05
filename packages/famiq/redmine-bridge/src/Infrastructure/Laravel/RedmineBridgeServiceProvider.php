<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Laravel;

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
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Psr18Client;

final class RedmineBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/redmine_bridge.php', 'redmine_bridge');

        $this->app->singleton(RedmineConfig::class, function ($app) {
            $config = $app['config']->get('redmine_bridge');

            return new RedmineConfig(
                $config['base_url'],
                $config['api_key'],
                (int) $config['project_id'],
                (int) $config['tracker_id'],
                $config['custom_fields'] ?? [],
                $config['contacts_api_base'],
                $config['contacts_search_path'],
                $config['contacts_upsert_path'],
                $config['contact_strategy'] ?? 'fallback',
            );
        });

        $this->app->singleton(RedmineHttpClient::class, function ($app) {
            return new RedmineHttpClient(
                new Psr18Client(),
                $app->make(RedmineConfig::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(RedminePayloadMapper::class, fn () => new RedminePayloadMapper());

        $this->app->singleton(IdempotencyStoreInterface::class, function ($app) {
            return new LaravelIdempotencyStore($app['db']->connection());
        });

        $this->app->singleton(ContactResolverInterface::class, function ($app) {
            $logger = $app->make(LoggerInterface::class);
            return new ContactResolverSelector(
                $app->make(RedmineConfig::class),
                new ApiContactResolver($app->make(RedmineHttpClient::class), $app->make(RedmineConfig::class), $logger),
                new CustomFieldContactResolver($logger),
                new FallbackContactResolver($logger),
            );
        });

        $this->app->singleton(ClienteServiceInterface::class, function ($app) {
            return new RedmineClienteService($app->make(ContactResolverInterface::class), $app->make(LoggerInterface::class));
        });

        $this->app->singleton(TicketServiceInterface::class, function ($app) {
            return new RedmineTicketService(
                $app->make(RedmineHttpClient::class),
                $app->make(RedmineConfig::class),
                $app->make(RedminePayloadMapper::class),
                $app->make(IdempotencyStoreInterface::class),
                $app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../config/redmine_bridge.php' => config_path('redmine_bridge.php'),
        ], 'redmine-bridge-config');

        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }
}
