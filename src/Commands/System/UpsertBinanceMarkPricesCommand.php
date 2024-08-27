<?php

namespace Nidavellir\Trading\Commands\System;

use Illuminate\Console\Command;
use Nidavellir\Trading\Exchanges\Binance\BinanceWebsocketMapper;
use Nidavellir\Trading\Exchanges\ExchangeWebsocketMapper;
use Nidavellir\Trading\Models\Exchange;

class UpsertBinanceMarkPricesCommand extends Command
{
    protected $signature = 'nidavellir:upsert-binance-mark-prices';

    protected $description = 'Updates all Binance market mark prices (websocket)';

    public function handle()
    {
        $exchange = Exchange::where('canonical', 'binance');

        $client = new ExchangeWebsocketMapper(
            new BinanceWebsocketMapper(
                credentials: config('nidavellir.system.api.credentials.binance')
            ),
        );

        $callbacks = [
            'message' => function ($conn, $msg) use ($exchange) {

                $prices = collect(json_decode($msg, true));

                /**
                 * Remove the USDT from the token name.
                 */
                $usdtTokens = $prices->filter(function ($item) {
                    return substr($item['s'], -4) === 'USDT';
                })->values();

                foreach ($usdtTokens as $token) {
                    $symbol = Symbol::where('token', $token);

                    ExchangeSymbol::updateOrCreate(
                        [//where:
                            'symbol_id' => $symbol->id,
                            'exchange_id' => $exchange->id,
                        ],
                        [//update:
                            'last_mark_price' => $token['p'],
                        ]
                    );
                }

                echo 'prices updated '.date('H:m:s').PHP_EOL;
            },
            'ping' => function ($conn, $msg) {
                echo 'received ping from server'.PHP_EOL;
            },
        ];

        $client->markPrices($callbacks);
    }
}
