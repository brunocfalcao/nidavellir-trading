<?php

namespace Nidavellir\Trading\Jobs\Orders;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
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

        /**
         * Obtain the position total trade amount.
         * Always floor rounded.
         */
        $tradeAmount = $this->position->total_trade_amount;

        $exchangeRESTMapper = new ExchangeRESTWrapper(
            $this->position->trader->getExchangeRESTWrapper()
        );

        // Grab the token name.
        $exchangeSymbol = $this->exchangeSymbol;
        $symbol = $this->exchangeSymbol->symbol;

        /**
         * Compute the quantity, given the last mark price of the
         * token and the trade amount divided by the amount ratio.
         *
         * We need to format the quantity given the symbol
         * precision (ExchangeSymbol->precision_quantity).
         */
        $orderQuantity = round(
            $tradeAmount / $this->ratio[1] / $exchangeSymbol->last_mark_price,
            $exchangeSymbol->precision_quantity
        );

        /**
         * Compute order parameters.
         * For now, only for Binance.
         */
        $orderSymbol = "{$symbol->token}USDT";
        $orderType = $this->ratio[0] == 0 ? 'MARKET' : 'LIMIT';
        $orderSide = $this->ratio[1] == 1 ? config('nidavellir.positions.side.to_sell') :
                                      config('nidavellir.positions.side.to_buy');

        info_multiple(
            'Position id: '.$this->position->id,
            'Trade total amount: '.$tradeAmount,
            'Ratio (Quantity division): '.$this->ratio[1], // Quantity ratio
            'Ratio (Percentage): '.$this->ratio[0],
            'Symbol: '.$symbol->token,
            'Mark Price: '.$exchangeSymbol->last_mark_price,
            'Quantity: '.$orderQuantity,
            'Type: '.$orderType,
            'Side: '.$orderSide
        );

        $orderData =
            [
                'symbol' => $orderSymbol,
                'type' => $orderType,
                'side' => $orderSide,
                'quantity' => $orderQuantity,
            ];

        /**
         * In case we are opening a limit order we need to
         * compute the price variation given the percentage
         * price ratio (ratio[0]).
         */
        if ($type == 'LIMIT') {
        }

        $data = $exchangeRESTMapper->placeSingleOrder(
            $orderData
        );

        dd($data);
    }
}
