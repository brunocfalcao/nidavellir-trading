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
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exchange_id')
                ->nullable();

            $table->foreignId('trader_id')
                ->nullable();

            $table->longText('payload')
                ->nullable();

            $table->longText('response')
                ->nullable();

            $table->longText('other_data')
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
                ->comment('E.g: Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper');

            $table->string('full_qualified_class_name_websocket')
                ->comment('E.g: Nidavellir\Trading\Exchanges\Binance\BinanceWebsocketMapper');

            $table->string('futures_url_rest_prefix')
                ->nullable();

            $table->string('futures_url_websockets_prefix')
                ->nullable();

            $table->string('spot_url_prefix')
                ->nullable();

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

            $table->string('website')
                ->nullable()
                ->comment('Token website');

            $table->unsignedInteger('rank')
                ->nullable();

            $table->longText('description')
                ->nullable();

            $table->string('image_url')
                ->nullable();

            $table->timestamps();
        });

        Schema::create('exchange_symbol', function (Blueprint $table) {
            $table->id();

            $table->foreignId('symbol_id');
            $table->foreignId('exchange_id');

            $table->unsignedInteger('precision_price');
            $table->unsignedInteger('precision_quantity');
            $table->unsignedInteger('precision_quote');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Active means the symbol will be syncronized with the exchange (price, precision, etc)');

            $table->boolean('is_eligible')
                ->default(false)
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

        Schema::create('orders_log_binance', function (Blueprint $table) {
            $table->id();

            $table->string('order_id')
                ->comment('Exchange system generated order id');

            $table->string('client_order_id')
                ->comment('Generated order id for reference purposes, generated as P:xxx where P means position id on the database');

            $table->string('status')
                ->comment('Order current status. E.g.: NEW, FILLED, EXPIRED');

            $table->decimal('price', 15, 8)
                ->comment('The token targeted price when the order was created');

            $table->decimal('avg_price', 15, 8)
                ->comment('The actually filled average price for this order, most of the cases is the same as the targetted price');

            $table->unsignedBigInteger('requested_quantity')
                ->comment('The token order quantity that was requested or filled');

            $table->unsignedBigInteger('filled_quantity')
                ->comment('The actually filled quantity, most of the case the same as the requested quantity');

            $table->unsignedBigInteger('cumulative_quantity')
                ->comment('The cumulative quantity that was filled for this token until now');

            $table->unsignedBigInteger('cumulative_quote')
                ->comment('The cumulative quote that was filled for this token until now');

            $table->string('time_in_force')
                ->comment('Time in force for the order, GTC (good till cancelled) or GTD (good till date)');

            $table->string('type')
                ->comment('Order type, MARKET or LIMIT');

            $table->boolean('reduce_only')
                ->comment('In case the position can only be reduced. On the DCA this parameter needs to be set to false since we will increase the position size in case more entries are filled');

            $table->boolean('close_position')
                ->comment('Meaning if this order, when filled, will close the position');

            $table->string('side')
                ->comment('Order side: BUY, SELL');

            $table->string('position_side')
                ->comment('Used in case of hedge mode, most of the case will be the same as the position side');

            $table->decimal('stop_price', 15, 8)
                ->comment('If the order is type STOP LIMIT, then a stop price needs to be applied. Most of the case the nidavellir will not use this');

            $table->string('working_type')
                ->comment('Different from futures and spot, CONTRACT_PRICE');

            $table->boolean('price_protected')
                ->comment('In case the order has a price protection, specifically from market manipulations');

            $table->string('original_type')
                ->comment('Same as the order type since nidavellir doesnt allow hedge mode');

            $table->string('price_match')
                ->comment('In case the price was matched or not, indicating how the system will match prices for this order');

            $table->timestamp('good_till_date')
                ->comment('In case there is a order date for the order to be cancelled');

            $table->timestamp('exchange_updated_at')
                ->comment('System returned timestamp for the order creation. Nidavellir should use this date and not any other custom/user/system generated date');

            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('orderable_exchange_type')
                ->nullable()
                ->comment('Related exchange order log model class');

            $table->unsignedBigInteger('orderable_exchange_id')
                ->nullable()
                ->comment('Related exchange order log model id');

            $table->uuid()
                ->comment('Auto generated UUID, for query reasons');

            $table->string('order_type')
                ->comment('E.g.: limit-buy, limit-sell or market');

            $table->foreignId('exchange_symbol_id')
                ->comment('Related exchange symbol id');

            $table->foreignId('position_id')
                ->nullable()
                ->comment('Before an order is created, a nidavellir position is opened (that will aggregate several parameters from different orders)');

            $table->decimal('laddering_percentage_ratio', 3, 3)
                ->comment('The percentage ratio gap between this (laddered) order and the market order. The market order has a percentage ratio of zero');

            $table->string('system_order_id')
                ->comment('System generated order id for reference purposes, generated as P:xxx where P means position id on the database');

            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trader_id');

            $table->decimal('take_profit_percentage', 6, 3)
                ->nullable()
                ->comment('Take profit percentage, given from the trade configuration');

            $table->decimal('total_trade_amount', 20, 8)
                ->nullable()
                ->comment('The total trade amount available for this trade (meaning the sum of all the margins from all the orders except the limit sell)');

            $table->string('status')
                ->default('new')
                ->comment('Current status canonical: NEW, ACTIVE, ERROR, CLOSED');

            $table->text('comments')
                ->nullable();

            $table->timestamps();
        });

        // Run initial framework schema seeder.
        $seeder = new TradingGenesisSeeder;
        $seeder->run();
    }
};
