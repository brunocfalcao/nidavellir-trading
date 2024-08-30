<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

class ApiLog extends AbstractModel
{
    protected $casts = [
        'mapper_properties' => 'array',
    ];
}
