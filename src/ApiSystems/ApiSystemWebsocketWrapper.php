<?php

namespace Nidavellir\Trading\ApiSystems;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;

class ApiSystemWebsocketWrapper extends AbstractRESTWrapper
{
    public function markPrices($callbacks)
    {
        return $this->mapper->markPrices($callbacks);
    }
}
