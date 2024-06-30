<?php

namespace Nidavellir\Trading;

use Brunocfalcao\LaravelHelpers\Traits\ForServiceProviders\HasAutoLoaders;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nidavellir\Trading\Commands\System\FetchTokenKlines;
use Nidavellir\Trading\Commands\System\ImportSymbols;
use Nidavellir\Trading\Commands\System\RankSymbols;
use Nidavellir\Trading\Commands\System\TestCommand;
use Nidavellir\Trading\Commands\System\UpdateExchangesInformation;
use Nidavellir\Trading\Commands\System\UpdateSymbolThumbnails;
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
        $this->loadLoginEvent();
        $this->loadCommands();
    }

    protected function loadLoginEvent()
    {
        Event::listen(
            Login::class,
            [LoggedInListener::class, 'handle']
        );
    }

    protected function loadCommands()
    {
        $this->commands([
            ImportSymbols::class,
            UpdateSymbolThumbnails::class,
            TestCommand::class,
            UpdateExchangesInformation::class,
            FetchTokenKlines::class,
            RankSymbols::class,
        ]);
    }
}
