<?php

namespace Nidavellir\Trading\ApiSystems;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;
use Nidavellir\Trading\ApiSystems\Concerns\HasCoinmarketCapOperations;
use Nidavellir\Trading\ApiSystems\Concerns\HasTaapiOperations;

class ApiSystemWebsocketWrapper extends AbstractRESTWrapper
{
    public function markPrices($callbacks)
    {
        return $this->mapper->markPrices($callbacks);
    }
}
