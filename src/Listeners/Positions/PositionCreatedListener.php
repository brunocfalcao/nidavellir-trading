<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;
use Nidavellir\Trading\Jobs\Positions\DispatchPositionJob;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\Nidavellir;

/**
* Class: PositionCreatedListener
*
* This listener is triggered when a new position is created. It sets the
* trade configuration for the position, creates the relevant orders based on
* predefined ratios, and dispatches a job to finalize the position and prepare
* it for order placement on the exchange.
*
* Important points:
* - Updates the position with trade configuration.
* - Creates Market, Limit, and Profit orders based on the configuration.
* - Dispatches the position for further processing.
*/
class PositionCreatedListener extends AbstractListener
{
    /**
    * Handles the position creation event by updating the configuration,
    * creating the necessary orders, and dispatching the position job.
    *
    * @param PositionCreatedEvent $event The event containing the position data.
    */
    public function handle(PositionCreatedEvent $event)
    {
        $position = $event->position;
        $trader = $position->trader;
        $configuration = Nidavellir::getTradeConfiguration();

        /**
        * Update the position with the trade configuration.
        */
        $position->update([
            'trade_configuration' => $configuration,
        ]);

        /**
        * Loop through the order ratios from the configuration and
        * create Market, Profit, and Limit orders accordingly.
        */
        foreach ($configuration['orders']['ratios'] as $type => $ratio) {
            /**
            * Create Market and Profit orders, which have a single ratio.
            */
            if ($type == 'MARKET' || $type == 'PROFIT') {
                $this->createOrder(
                    $position->id,
                    $type,
                    $ratio[0],
                    $ratio[1]
                );
            }

            /**
            * Create Limit orders, which can have multiple ratios.
            */
            if ($type == 'LIMIT') {
                foreach ($ratio as $limitOrder) {
                    $this->createOrder(
                        $position->id,
                        $type,
                        $limitOrder[0],
                        $limitOrder[1]
                    );
                }
            }
        }

        /**
        * After creating all orders, dispatch the position job to prepare
        * the remaining trade data and sync it with the exchange.
        */
        DispatchPositionJob::dispatch($position->id);
    }

    /**
    * Creates an order associated with the given position, using the
    * provided type, price ratio, and amount divider.
    *
    * @param int $positionId The ID of the position.
    * @param string $type The type of the order (MARKET, LIMIT, PROFIT).
    * @param float $pricePercentageRatio The percentage ratio for the order price.
    * @param float $amountDivider The divider for the order amount.
    */
    private function createOrder($positionId, $type, $pricePercentageRatio, $amountDivider)
    {
        Order::create([
            'position_id' => $positionId,
            'type' => $type,
            'price_ratio_percentage' => $pricePercentageRatio,
            'amount_divider' => $amountDivider,
        ]);
    }
}
