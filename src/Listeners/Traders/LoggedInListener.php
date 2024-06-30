<?php

namespace Nidavellir\Trading\Listeners\Traders;

use Illuminate\Auth\Events\Login;
use Nidavellir\Trading\Abstracts\AbstractListener;

class LoggedInListener extends AbstractListener
{
    public function handle(Login $event)
    {
        $event->user->previous_logged_in_at = $event->user->last_logged_in_at;
        $event->user->last_logged_in_at = now();
        $event->user->save();
    }
}
