<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nidavellir\Trading\Database\Seeders\TradingGenesisSeeder;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();

            $table->string('block')
                ->nullable()
                ->comment('Block that groups a full application log task activities');

            $table->foreignId('trader_id')->nullable();
            $table->foreignId('exchange_id')->nullable();
            $table->foreignId('exchange_symbol_id')->nullable();
            $table->foreignId('symbol_id')->nullable();
            $table->foreignId('position_id')->nullable();
            $table->foreignId('order_id')->nullable();

            $table->string('action_canonical')
                ->nullable();

            $table->string('description')
                ->nullable();

            $table->string('return_value')
                ->nullable();

            $table->longText('return_data')
                ->nullable();

            $table->text('comments')
                ->nullable();

            $table->longText('debug_backtrace')
                ->nullable();

            $table->timestamps();
        });

        Schema::create('exceptions_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loggable_id')->nullable();
            $table->string('loggable_type')->nullable();
            $table->longText('title')->nullable();
            $table->longText('error_message')->nullable();
            $table->string('exception_class')->nullable();
            $table->string('file')->nullable();
            $table->integer('line')->nullable();
            $table->json('attributes')->nullable();
            $table->longText('trace')->nullable();
            $table->timestamps();
        });

        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();

            $table->string('caller_name');

            $table->foreignId('exchange_id')
                ->nullable();

            $table->foreignId('trader_id')
                ->nullable();

            $table->foreignId('position_id')
                ->nullable();

            $table->foreignId('exchange_symbol_id')
                ->nullable();

            $table->foreignId('order_id')
                ->nullable();

            $table->longText('mapper_properties')
                ->nullable();

            $table->longText('response')
                ->nullable();

            $table->longText('exception')
                ->nullable();

            $table->string('result')
                ->nullable();

            $table->timestamps();
        });

        Schema::create('system', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('fear_greed_index')
                ->default(0)
                ->comment('Updated daily, so nidavellir knows what trade configuration should be used');

            $table->timestamp('fear_greed_index_updated_at')
                ->default(now())
                ->comment('Last F&G update date');

            $table->unsignedTinyInteger('fear_greed_index_threshold')
                ->comment('F&G threshold to change from bearish trading configuration to bullish trading configuration, or vice-versa');

            $table->timestamps();
        });

        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();

            $table->string('name')
                ->comment('Exchange commerical name');

            $table->string('canonical')
                ->nullable()
                ->comment('Unique natural identifier');

            $table->string('full_qualified_class_name_rest')
                ->nullable()
                ->comment('E.g: Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper');

            $table->string('full_qualified_class_name_websocket')
                ->nullable()
                ->comment('E.g: Nidavellir\Trading\Exchanges\Binance\BinanceWebsocketMapper');

            $table->string('futures_url_rest_prefix')
                ->nullable();

            $table->string('futures_url_websockets_prefix')
                ->nullable();

            $table->string('generic_url_prefix')
                ->nullable()
                ->comment('Used for fallback cases, like for coinmarketcap calls');

            $table->timestamps();
        });

        Schema::create('symbols', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('coinmarketcap_id')
                ->nullable();

            $table->string('name')
                ->comment('E.g.: Ethereum');

            $table->string('token')
                ->comment('E.g.: ETH');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Master status that will define if a symbol is globally active or not');

            $table->string('website')
                ->nullable()
                ->comment('Token website');

            $table->unsignedInteger('rank')
                ->nullable();

            $table->longText('description')
                ->nullable();

            $table->string('image_url')
                ->nullable();

            // New indicator columns
            $table->decimal('indicator_atr', 20, 8)
                ->nullable()
                ->comment('Average True Range (ATR) value for the symbol');

            $table->decimal('indicator_bbands_upper', 20, 8)
                ->nullable()
                ->comment('Bollinger Bands upper value for the symbol');

            $table->decimal('indicator_bbands_middle', 20, 8)
                ->nullable()
                ->comment('Bollinger Bands middle value for the symbol');

            $table->decimal('indicator_bbands_lower', 20, 8)
                ->nullable()
                ->comment('Bollinger Bands lower value for the symbol');

            $table->decimal('indicator_rsi', 20, 8)
                ->nullable()
                ->comment('Relative Strength Index (RSI) value for the symbol');

            $table->decimal('indicator_stochastic_k', 20, 8)
                ->nullable()
                ->comment('Stochastic Oscillator %K value for the symbol');

            $table->decimal('indicator_stochastic_d', 20, 8)
                ->nullable()
                ->comment('Stochastic Oscillator %D value for the symbol');

            // MACD values
            $table->decimal('indicator_macd', 20, 8)
                ->nullable()
                ->comment('MACD value for the symbol');

            $table->decimal('indicator_macd_signal', 20, 8)
                ->nullable()
                ->comment('MACD Signal value for the symbol');

            $table->decimal('indicator_macd_hist', 20, 8)
                ->nullable()
                ->comment('MACD Histogram value for the symbol');

            $table->decimal('price_amplitude_highest', 10, 4)
                ->nullable();

            $table->decimal('price_amplitude_lowest', 10, 4)
                ->nullable();

            $table->decimal('price_amplitude_percentage', 10, 4)
                ->nullable()
                ->comment('Price amplitude percentage (high - low / low * 100) for the symbol on the day');

            $table->timestamps();
        });

        Schema::create('exchange_symbols', function (Blueprint $table) {
            $table->id();

            $table->foreignId('symbol_id');
            $table->foreignId('exchange_id');

            $table->unsignedInteger('precision_price');
            $table->unsignedInteger('precision_quantity');
            $table->unsignedInteger('precision_quote');
            $table->decimal('tick_size', 20, 8);

            $table->longText('api_symbol_information')
                ->nullable()
                ->comment('The raw api data symbol information from this symbol data');

            $table->longText('api_notional_and_leverage_symbol_information')
                ->nullable()
                ->comment('The raw exchange api data from this symbol notional and leverage data');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Active means the symbol will be syncronized with the exchange (price, precision, etc)');

            $table->boolean('is_eligible')
                ->default(true)
                ->comment('Eligible means the symbol is a candidate to be traded at the moment');

            $table->decimal('last_mark_price', 20, 8)
                ->nullable()
                ->comment('Last mark price fetched from the exchange');

            $table->timestamp('price_last_synced_at')->nullable();

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {

            // Dropping columns that somehow can't be changed.
            $table->dropColumn([
                'email_verified_at',
                'email',
                'password',
                'created_at',
                'updated_at',
            ]);

            $table->string('name')
                ->nullable()
                ->change();

            $table->timestamp('previous_logged_in_at')
                ->nullable()
                ->after('remember_token')
                ->comment('This column and the last_logged_in_at allows to create a date interval to compute actions that happened between the current and last login');

            $table->timestamp('last_logged_in_at')
                ->nullable()
                ->after('previous_logged_in_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')
                ->nullable()
                ->unique()
                ->after('name');

            $table->string('password')
                ->nullable()
                ->after('email');

            $table->foreignId('exchange_id')
                ->nullable();

            $table->text('binance_api_key')
                ->nullable();

            $table->text('binance_secret_key')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::rename('users', 'traders');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('position_id')
                ->nullable()
                ->comment('Before an order is created, a nidavellir position is opened (that will aggregate several parameters from different orders)');

            $table->string('status')
                ->default('new')
                ->comment('New (not processed yet), active (processed by api, successfully), filled (processed and got filled), cancelled (processed and got cancelled)');

            $table->uuid()
                ->comment('Auto generated UUID, for query reasons');

            $table->string('type')
                ->comment('Order type, limit-buy, market, etc');

            $table->decimal('price_ratio_percentage', 6, 3)
                ->comment('Price percentage ratio from the market order. Market order, the price ratio is zero');

            $table->unsignedTinyInteger('amount_divider')
                ->comment('How much the total trade amount will be divided for this trade. The take profit is one because we sell the total position');

            $table->decimal('entry_average_price', 20, 8)
                ->nullable()
                ->comment('The order price where when the order was placed (but not filled yet)');

            $table->decimal('entry_quantity', 20, 8)
                ->nullable()
                ->comment('The order entry amount that will be filled');

            $table->decimal('filled_average_price', 20, 8)
                ->nullable()
                ->comment('The order price where it was actually filled');

            $table->decimal('filled_quantity', 20, 8)
                ->nullable()
                ->comment('The order entry amount that wsa actually filled');

            $table->string('order_exchange_id')
                ->nullable()
                ->comment('API generated order id for reference purposes, generated as P:xxx where P means position id on the database');

            $table->longText('api_result')
                ->nullable()
                ->comment('The exchange api json complete response');

            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trader_id');

            $table->foreignId('exchange_symbol_id')
                ->nullable();

            $table->string('status')
                ->default('new')
                ->comment('Current status canonical: NEW, ACTIVE, ERROR, CLOSED');

            $table->string('side')
                ->nullable()
                ->comment('Long (buy) or Short (sell)');

            $table->decimal('initial_mark_price', 20, 8)
                ->nullable()
                ->comment('First mark price fetched from the exchange before triggering the orders');

            $table->longText('trade_configuration')
                ->nullable()
                ->comment('Trade configuration at the moment of the position creation');

            $table->unsignedInteger('total_trade_amount')
                ->nullable()
                ->comment('The total trade amount available for this trade (meaning the sum of all the margins from all the orders except the limit sell)');

            $table->unsignedTinyInteger('leverage')
                ->nullable()
                ->comment('The maximum possible leverage for this total trade amount');

            $table->text('comments')
                ->nullable();

            $table->timestamps();
        });

        // Run initial framework schema seeder.
        $seeder = new TradingGenesisSeeder;
        $seeder->run();
    }
};
