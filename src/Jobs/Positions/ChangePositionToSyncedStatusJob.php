<?php

namespace Nidavellir\Trading\Jobs\Positions;

use Illuminate\Support\Facades\Bus;
use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\PositionNotSyncedException;
use Nidavellir\Trading\Exchanges\Binance\BinanceRESTMapper;
use Nidavellir\Trading\Exchanges\ExchangeRESTWrapper;
use Nidavellir\Trading\Jobs\Orders\DispatchOrderJob;
use Nidavellir\Trading\Models\ApplicationLog;
use Nidavellir\Trading\Models\ExchangeSymbol;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\Nidavellir;
use Throwable;

class ChangePositionToSyncedStatusJob extends AbstractJob
{
    public int $positionId;

    public function __construct(int $positionId)
    {
        $this->positionId = $positionId;
    }

    public function handle()
    {
        try {
            //
        } catch (Throwable $e) {
            $position->update(['status' => 'error']);
            throw new PositionNotSyncedException(
                $e->getMessage(),
                ['position_id' => $this->positionId],
                $position
            );
        }
    }
}
