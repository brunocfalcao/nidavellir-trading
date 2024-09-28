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
        Schema::create('job_queue', function (Blueprint $table) {
            $table->id();
            $table->string('class'); // Job class name
            $table->json('arguments'); // Job arguments
            $table->string('status')->default('pending'); // Job status: pending, running, completed, failed
            $table->uuid('block_uuid')->nullable(); // Block UUID to group jobs
            $table->bigInteger('started_at')->nullable(); // Store as numerical timestamp
            $table->bigInteger('completed_at')->nullable(); // Sto
            $table->unsignedBigInteger('duration')->nullable();
            $table->text('error_message')->nullable();
            $table->string('hostname')->nullable(); // Hostname of the server processing the job
            $table->timestamps(); // Created and updated timestamps
        });

        Schema::create('api_requests_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loggable_id')->nullable();
            $table->string('loggable_type')->nullable();
            $table->string('path')->nullable();
            $table->json('payload')->nullable();
            $table->string('http_method')->nullable();
            $table->json('http_headers_sent')->nullable();
            $table->integer('http_response_code')->nullable();
            $table->json('response')->nullable();
            $table->json('http_headers_returned')->nullable();
            $table->string('hostname')->nullable();
            $table->timestamps();

            $table->index(['loggable_id', 'loggable_type']);
            $table->index(['http_response_code']);
        });

        Schema::create('api_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Exchange commercial name');
            $table->string('canonical')->nullable()->comment('Unique natural identifier');
            $table->boolean('is_exchange')->default(false);
            $table->string('namespace_prefix_jobs')->nullable();
            $table->string('namespace_class_rest')->nullable()->comment('E.g: Nidavellir\Trading\ApiSystems\Binance\BinanceRESTMapper');
            $table->string('namespace_class_websocket')->nullable()->comment('E.g: Nidavellir\Trading\ApiSystems\Binance\BinanceWebsocketMapper');
            $table->string('futures_url_rest_prefix')->nullable();
            $table->string('futures_url_websockets_prefix')->nullable();
            $table->string('taapi_canonical')->nullable();
            $table->string('other_url_prefix')->nullable();
            $table->longText('other_information')->nullable();
            $table->timestamps();

            $table->unique(['canonical']);
        });

        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();
            $table->string('block')->nullable()->comment('Block that groups a full application log task activities');
            $table->foreignId('trader_id')->nullable();
            $table->foreignId('api_system_id')->nullable();
            $table->foreignId('exchange_symbol_id')->nullable();
            $table->foreignId('symbol_id')->nullable();
            $table->foreignId('position_id')->nullable();
            $table->foreignId('order_id')->nullable();
            $table->string('action_canonical')->nullable();
            $table->text('description')->nullable();
            $table->text('return_value')->nullable();
            $table->longText('return_data')->nullable();
            $table->text('comments')->nullable();
            $table->longText('debug_backtrace')->nullable();
            $table->timestamps();

            $table->index(['block']);
            $table->index(['trader_id', 'api_system_id']);
            $table->index(['exchange_symbol_id', 'symbol_id']);
        });

        Schema::create('exceptions_log', function (Blueprint $table) {
            $table->id();
            $table->longText('exception_message');
            $table->string('filename');
            $table->json('additional_data')->nullable();
            $table->json('stack_trace');
            $table->timestamps();

            $table->index(['filename']);
        });

        Schema::create('system', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('coinmarketcap_id')->nullable();
            $table->string('name')->comment('E.g.: Ethereum');
            $table->string('token')->comment('E.g.: ETH');
            $table->string('website')->nullable()->comment('Token website');
            $table->unsignedInteger('rank')->nullable();
            $table->longText('description')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->unique(['token']);
            $table->index(['rank']);
        });

        Schema::create('exchange_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id');
            $table->unsignedInteger('exchange_id');
            $table->unsignedInteger('precision_price');
            $table->unsignedInteger('precision_quantity');
            $table->unsignedInteger('precision_quote');
            $table->decimal('tick_size', 20, 8);
            $table->longText('api_symbol_information')->nullable();
            $table->longText('api_notional_and_leverage_symbol_information')->nullable();
            $table->decimal('last_mark_price', 20, 8)->nullable();
            $table->decimal('ma_28_2days_ago', 20, 8)->nullable()->comment('EMA 28 1D closed candle, 2 days ago');
            $table->decimal('ma_28_yesterday', 20, 8)->nullable()->comment('EMA 28 1D closed candle, yesterday');
            $table->decimal('ma_56_2days_ago', 20, 8)->nullable()->comment('EMA 56 1D closed candle, 2 days ago');
            $table->decimal('ma_56_yesterday', 20, 8)->nullable()->comment('EMA 56 1D closed candle, yesterday');
            $table->decimal('ma_amplitude_interval_percentage', 20, 8)->nullable();
            $table->decimal('ma_amplitude_interval_absolute', 20, 8)->nullable();
            $table->string('side')->default('LONG')->comment('Defines the direction of the trade when using it (BUY as long/SELL as short)');
            $table->timestamp('indicator_last_synced_at')->nullable();
            $table->timestamp('price_last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol_id', 'exchange_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at',
                'email',
                'password',
                'created_at',
                'updated_at',
            ]);

            $table->string('name')->nullable()->change();
            $table->timestamp('previous_logged_in_at')->nullable()->after('remember_token');
            $table->timestamp('last_logged_in_at')->nullable()->after('previous_logged_in_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('name');
            $table->string('password')->nullable()->after('email');
            $table->foreignId('api_system_id')->nullable();
            $table->text('binance_api_key')->nullable();
            $table->text('binance_secret_key')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::rename('users', 'traders');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable();
            $table->string('status')->default('new');
            $table->uuid()->comment('Auto generated UUID, for query reasons');
            $table->string('type');
            $table->decimal('price_ratio_percentage', 6, 3);
            $table->unsignedTinyInteger('amount_divider');
            $table->decimal('entry_average_price', 20, 8)->nullable();
            $table->decimal('entry_quantity', 20, 8)->nullable();
            $table->decimal('filled_average_price', 20, 8)->nullable();
            $table->decimal('filled_quantity', 20, 8)->nullable();
            $table->string('order_exchange_system_id')->nullable();
            $table->longText('api_result')->nullable();
            $table->timestamps();

            $table->index(['position_id', 'status']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trader_id');
            $table->foreignId('exchange_symbol_id')->nullable();
            $table->string('status')->default('new');
            $table->string('side')->nullable();
            $table->decimal('initial_mark_price', 20, 8)->nullable();
            $table->longText('trade_configuration')->nullable();
            $table->unsignedInteger('total_trade_amount')->nullable();
            $table->unsignedTinyInteger('leverage')->nullable();
            $table->decimal('initial_profit_percentage_ratio', 20, 8)->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index(['trader_id', 'exchange_symbol_id']);
            $table->index(['status']);
        });

        $seeder = new TradingGenesisSeeder;
        $seeder->run();
    }
};
