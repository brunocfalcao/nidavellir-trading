<?php

namespace Nidavellir\Trading;

use Brunocfalcao\LaravelHelpers\Traits\ForServiceProviders\HasAutoLoaders;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nidavellir\Trading\Commands\Debug\DebugCommand;
use Nidavellir\Trading\Commands\Debug\QueryOpenOrders;
use Nidavellir\Trading\Commands\Debug\QueryOrder;
use Nidavellir\Trading\Commands\Debug\TestOrder;
use Nidavellir\Trading\Commands\System\JobPollerCommand;
use Nidavellir\Trading\Commands\System\REST\UpsertSymbolsTradeDirectionCommand;
use Nidavellir\Trading\Commands\System\UpsertBinanceMarkPricesCommand;
use Nidavellir\Trading\Events\Orders\OrderCreatedEvent;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Listeners\Orders\OrderCreatedListener;
use Nidavellir\Trading\Listeners\Positions\PositionCreatedListener;
use Nidavellir\Trading\Listeners\Traders\LoggedInListener;

/**
 * TradingServiceProvider
 *
 * This class serves as the service provider for
 * the Nidavellir Trading system. It handles
 * registration of commands, events, and
 * migrations for the trading system.
 */
class TradingServiceProvider extends ServiceProvider
{
    use HasAutoLoaders;

    /**
     * Register method.
     *
     * This method is used to register services or bindings
     * into the container. Currently, no bindings are
     * registered in this service provider.
     */
    public function register()
    {
        //
    }

    /**
     * Boot method.
     *
     * This method is executed when the service provider
     * is booted. It loads migrations, autoloads policies,
     * observers, global scopes, and registers commands
     * and events.
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->autoLoadPolicies(__DIR__);
        $this->autoLoadObservers(__DIR__);
        $this->autoLoadGlobalScopes(__DIR__);
        $this->loadCommands();
        $this->registerEvents();
    }

    /**
     * Register Events.
     *
     * This method registers all the event listeners
     * used within the trading system. It listens for
     * position, order, and user login events.
     */
    protected function registerEvents()
    {
        // Position events.
        Event::listen(
            PositionCreatedEvent::class,
            [PositionCreatedListener::class, 'handle']
        );

        // Order events.
        Event::listen(
            OrderCreatedEvent::class,
            [OrderCreatedListener::class, 'handle']
        );

        // User (trader) events.
        Event::listen(
            Login::class,
            [LoggedInListener::class, 'handle']
        );
    }

    /**
     * Load Commands.
     *
     * This method registers all the artisan commands
     * available within the trading system. These commands
     * include the test command and the command to upsert
     * Binance mark prices.
     */
    protected function loadCommands()
    {
        $this->commands([
            DebugCommand::class,
            TestOrder::class,
            QueryOrder::class,
            QueryOpenOrders::class,
            JobPollerCommand::class,
            UpsertSymbolsTradeDirectionCommand::class,
            UpsertBinanceMarkPricesCommand::class,
        ]);
    }
}
