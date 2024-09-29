<?php

namespace Nidavellir\Trading\ApiSystems\Taapi\Callers;

use Nidavellir\Trading\Abstracts\AbstractCaller;
use Nidavellir\Trading\ApiSystems\Taapi\REST\API;

class GetMa extends AbstractCaller
{
    protected string $callerName = 'Get Taapi MA';

    public function call()
    {
        $api = new API($this->mapper->connectionDetails());
        $this->result = $api->getMa($this->mapper->properties);
    }
}
