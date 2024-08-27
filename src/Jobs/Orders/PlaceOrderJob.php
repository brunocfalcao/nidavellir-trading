<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\ExchangeRESTMapper;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;

class PlaceOrderJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $ratio;

    public $exchangeSymbol;

    public $position;

    public function __construct(
        Position $position,
        ExchangeSymbol $exchangeSymbol,
        array $ratio
    ) {
        $this->ratio = $ratio;
        $this->position = $position;
        $this->exchangeSymbol = $exchangeSymbol;
    }

    public function handle()
    {
        /**
         * The order placement is the beginning of the traders'
         * future. It's the lambo arriving. It's the bills
         * getting paid. It's the mortgage being reduced,
         * it's the future of being a millionaire.
         */

        /**
         * Each order have specific mandatory data:
         * 1. exchange symbol. That will be the exchange
         * symbol model, that will be used as the order
         * token.
         *
         * 2. percentage ratio. That will be the percentage
         * ratio of the order, compared to the market order
         * mark price.
         *
         * 3. position id. This will be related position id,
         * to obtain, the total trade amount.
         *
         * 4. amount division ratio. How much will we divide
         * the total trade amount for this trade.
         *
         * If this order is not a market order (percentage ratio=0)
         * then before this order is created we will fetch the
         * market order information to obtain the mark price
         * at which it was opened. Then we can make the math
         * for the correct price that this order should be
         * opened given the passed percentage ratio.
         */

        // Obtain the position total trade amount.
        $tradeAmount = $this->position->total_trade_amount;

        $exchangeRESTMapper = new ExchangeRESTMapper(
            $this->position->trader->getExchangeRESTMapper()
        );

        // Grab the token name.
        $symbol = strtoupper($this->exchangeSymbol->symbol->token);

        /**
         * Compute the quantity, given the last mark price of the
         * token and the trade amount divided by the amount ratio.
         */
        $quantity = floor($tradeAmount / $ratio[1] / 143);

        // Market order?
        if ($ratio[0] == 0) {
            $data = $exchangeRESTMapper->placeSingleOrder(
                [
                    'symbol' => "{$symbol}USDT",
                    'type' => 'MARKET',
                    'side' => 'BUY',
                    'quantity' => 1,
                ]
            );
        }
    }
}
