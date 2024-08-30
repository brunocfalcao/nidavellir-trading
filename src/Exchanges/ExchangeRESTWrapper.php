<?php

namespace Nidavellir\Trading\Exchanges;

use Nidavellir\Trading\Abstracts\AbstractRESTWrapper;

class ExchangeRESTWrapper extends AbstractRESTWrapper
{
    public function getExchangeInformation()
    {
        return $this->mapper->getExchangeInformation($this);
    }

    public function getAccountBalance()
    {
        return $this->mapper->getAccountBalance($this);
    }

    // TODO / Testing.
    public function placeSingleOrder(array $options = [])
    {
        return $this->mapper->newOrder($options);
    }
}
