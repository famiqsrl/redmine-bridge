<?php
declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser;

use Famiq\ActiveDirectoryUser\RedmineBridge\RedmineBridgeFacade;
use Famiq\RedmineBridge\RedmineClienteService;
use Famiq\RedmineBridge\RedmineTicketService;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor de servicios para integrar FamiqADUser en Laravel.
 */
class FamiqADUserServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios del paquete.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/redmine_bridge.php', 'redmine_bridge');

        if (class_exists(RedmineClienteService::class)) {
            $this->app->singleton(RedmineBridgeFacade::class, function ($app) {
                return new RedmineBridgeFacade(
                    $app->make(RedmineClienteService::class),
                    $app->make(RedmineTicketService::class),
                );
            });
        }
    }

    /**
     * Inicializa el paquete cuando la aplicación se ejecuta en consola.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ExportConfigCommand::class,
                Commands\GetUserInfoCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/ldap.php' => config_path('ldap.php'),
                __DIR__.'/redmine_bridge.php' => config_path('redmine_bridge.php'),
            ], 'famiqaduser-config');
        }

        if ($this->app->bound('config') && (bool) config('redmine_bridge.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/routes/redmine_bridge.php');
        }
    }
}
