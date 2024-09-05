<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Jobs\Positions\DispatchPositionJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Nidavellir;

class PositionCreatedListener extends AbstractListener
{
    public function handle(PositionCreatedEvent $event)
    {
        $position = $event->position;
        $trader = $position->trader;
        $configuration = Nidavellir::getTradeConfiguration();

        $position->update([
            'trade_configuration' => $configuration,
        ]);

        foreach ($configuration['orders']['ratios'] as $type => $ratio) {
            /**
             * The side is not part of the order directly,
             * but will be picked from the position by the
             * order on the moment the order is created.
             */
            if ($type == 'MARKET' || $type == 'LIMIT-SELL') {
                $this->createOrder(
                    $trader->exchange->id,
                    $position->id,
                    $type,
                    $ratio[0],
                    $ratio[1]
                );
            }

            if ($type == 'LIMIT-BUY') {
                foreach ($ratio as $limitOrder) {
                    $this->createOrder(
                        $trader->exchange->id,
                        $position->id,
                        $type,
                        $limitOrder[0],
                        $limitOrder[1]
                    );
                }
            }
        }

        /**
         * All done for the position. Now, we need to
         * activate the position to finish getting
         * the remaining trade data ready for the
         * orders to be created on the exchange.
         */
        DispatchPositionJob::dispatch($position->id);
    }

    private function createOrder($exchangeId, $positionId, $type, $pricePercentageRatio, $amountDivider)
    {
        Order::create([
            'position_id' => $positionId,
            'type' => $type,
            'price_percentage_ratio' => $pricePercentageRatio,
            'amount_divider' => $amountDivider,
        ]);
    }
}
