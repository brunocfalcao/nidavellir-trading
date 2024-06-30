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
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();

            $table->string('name')
                ->comment('Exchange commerical name');

            $table->string('canonical')
                ->nullable()
                ->comment('Unique natural identifier');

            $table->string('futures_url_prefix')
                ->nullable();

            $table->string('spot_url_prefix')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();
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
            $table->softDeletes();
        });

        Schema::create('exchange_symbol', function (Blueprint $table) {
            $table->id();

            $table->foreignId('symbol_id');
            $table->foreignId('exchange_id');

            $table->unsignedInteger('precision_price');
            $table->unsignedInteger('precision_quantity');
            $table->unsignedInteger('precision_quote');

            $table->boolean('was_synced')
                  ->default(false);

            $table->boolean('is_active')
                  ->default(true);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {

            // Dropping columns that somehow can't be changed.
            $table->dropColumn([
                'email_verified_at',
                'email',
                'password',
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

            $table->text('binance_api_key')
                ->nullable()
                ->after('password');

            $table->text('binance_secret_key')
                ->after('binance_api_key')
                ->nullable();
        });

        Schema::rename('users', 'traders');

        Schema::create('klines_15m', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->bigInteger('open_time');
            $table->decimal('open', 20, 10);
            $table->decimal('high', 20, 10);
            $table->decimal('low', 20, 10);
            $table->decimal('close', 20, 10);
            $table->decimal('volume', 20, 10);
            $table->bigInteger('close_time');
            $table->decimal('quote_volume', 20, 10);
            $table->integer('count');
            $table->decimal('taker_buy_volume', 20, 10);
            $table->decimal('taker_buy_quote_volume', 20, 10);
            $table->decimal('ignore', 20, 10);
            $table->timestamps();
        });
        // Run initial framework schema seeder.
        $seeder = new TradingGenesisSeeder();
        $seeder->run();
    }
};
