<?php

namespace Nidavellir\Trading\Models;

use Nidavellir\Trading\Abstracts\AbstractModel;

/**
 * @property int $fear_greed_index
 * @property string $fear_greed_index_updated_at
 * @property int $fear_greed_index_threshold
 */
class System extends AbstractModel
{
    protected $table = 'system';

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestsLog::class, 'loggable');
    }
}
