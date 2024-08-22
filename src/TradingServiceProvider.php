<?php

namespace Nidavellir\Trading;

use Brunocfalcao\LaravelHelpers\Traits\ForServiceProviders\HasAutoLoaders;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nidavellir\Trading\Commands\System\CycleCommand;
use Nidavellir\Trading\Commands\System\TestCommand;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Listeners\Positions\PositionCreatedListener;
use Nidavellir\Trading\Listeners\Traders\LoggedInListener;

class TradingServiceProvider extends ServiceProvider
{
    use HasAutoLoaders;

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->autoLoadPolicies(__DIR__);
        $this->autoLoadObservers(__DIR__);
        $this->autoLoadGlobalScopes(__DIR__);
        $this->loadCommands();
        $this->registerEvents();
    }

    protected function registerEvents()
    {
        // Position events.
        Event::listen(
            PositionCreatedEvent::class,
            [PositionCreatedListener::class, 'handle']
        );

        // User (trader) events.
        Event::listen(
            Login::class,
            [LoggedInListener::class, 'handle']
        );
    }

    protected function loadCommands()
    {
        $this->commands([
            TestCommand::class,
            CycleCommand::class,
        ]);
    }
}
