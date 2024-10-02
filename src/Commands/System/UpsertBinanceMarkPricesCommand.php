<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nidavellir\Trading\ApiSystems\ApiSystemWebsocketWrapper;
use Nidavellir\Trading\ApiSystems\Binance\BinanceWebsocketMapper;
use Nidavellir\Trading\Jobs\System\AdjustAverageWeightPricingProfitOrders;

/**
 * UpsertBinanceMarkPricesCommand is a console command that updates
 * Binance market mark prices for tokens included in the system
 * configuration and those involved in ongoing trading positions.
 *
 * - Connects to Binance's WebSocket to receive live mark prices.
 * - Filters and updates prices for eligible tokens.
 */
class UpsertBinanceMarkPricesCommand extends Command
{
    // Signature to run the command from the CLI.
    protected $signature = 'nidavellir:prices';

    // Description of what the command does.
    protected $description = 'Updates Binance market mark prices for included tokens and ongoing positions.';

    /**
     * Executes the command logic to update mark prices.
     */
    public function handle()
    {
        // Fetch Binance exchange_id from the database.
        $binanceExchangeId = DB::table('api_systems')->where('canonical', 'binance')->value('id');

        // Instantiate the WebSocket client for Binance.
        $client = new ApiSystemWebsocketWrapper(
            new BinanceWebsocketMapper(
                credentials: config('nidavellir.system.api.credentials.binance')
            ),
        );

        // Define the WebSocket callbacks.
        $callbacks = [
            // Handles incoming messages from the WebSocket.
            'message' => function ($conn, $msg) use ($binanceExchangeId) {
                $prices = collect(json_decode($msg, true));

                // Fetch the latest included tokens from config.
                $includedTokens = config('nidavellir.symbols.included');

                // Fetch ongoing positions with status 'synced' or 'new'.
                $ongoingPositionSymbolIds = DB::table('positions')
                    ->whereIn('status', ['synced', 'new'])
                    ->pluck('exchange_symbol_id')
                    ->map(function ($exchangeSymbolId) {
                        return DB::table('exchange_symbols')->where('id', $exchangeSymbolId)->value('symbol_id');
                    })
                    ->filter()
                    ->unique()
                    ->toArray();

                /**
                 * Remove all non-USDT tokens from the received data.
                 */
                $usdtTokens = $prices->filter(function ($item) {
                    return substr($item['s'], -4) === 'USDT';
                })->values();

                // Iterate over each USDT token to update the prices.
                foreach ($usdtTokens as $token) {
                    $tokenSymbol = substr($token['s'], 0, -4);
                    $symbol = DB::table('symbols')->where('token', $tokenSymbol)->first();

                    // Only update if the token is included or part of ongoing positions.
                    if ($symbol && (in_array($symbol->token, $includedTokens) || in_array($symbol->id, $ongoingPositionSymbolIds))) {
                        // Check if the ExchangeSymbol entry exists.
                        $existingExchangeSymbol = DB::table('exchange_symbols')
                            ->where('symbol_id', $symbol->id)
                            ->where('exchange_id', $binanceExchangeId)
                            ->first();

                        // Update the mark price and the synced timestamp if it exists.
                        if ($existingExchangeSymbol) {
                            DB::table('exchange_symbols')
                                ->where('id', $existingExchangeSymbol->id)
                                ->update([
                                    'last_mark_price' => $token['p'],
                                    'price_last_synced_at' => now(),
                                ]);
                        }
                    }
                }

                // Trigger the AdjustAverageWeightPricingProfitOrders job after mark prices are updated
                AdjustAverageWeightPricingProfitOrders::dispatch();
            },
            // Handles ping messages from the WebSocket server.
            'ping' => function ($conn, $msg) {
                echo 'received ping from server'.PHP_EOL;
            },
        ];

        // Connect to Binance WebSocket and start receiving mark prices.
        $client->markPrices($callbacks);
    }
}
