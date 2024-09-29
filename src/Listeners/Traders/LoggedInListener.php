<?php

namespace Nidavellir\Trading\Listeners\Traders;

use Illuminate\Auth\Events\Login;
use Nidavellir\Trading\Abstracts\AbstractListener;

/**
 * Class: LoggedInListener
 *
 * This listener handles the actions to be performed when a trader logs in.
 * It updates the `last_logged_in_at` timestamp and records the previous login time.
 *
 * Important points:
 * - Tracks the trader's last and previous login timestamps.
 * - Updates the user's information upon login.
 */
class LoggedInListener extends AbstractListener
{
    // Handles the login event by updating the user's login timestamps.
    public function handle(Login $event)
    {
        // Store the previous login time before updating to the current login time.
        $event->user->previous_logged_in_at = $event->user->last_logged_in_at;

        // Update the user's last login time to the current time.
        $event->user->last_logged_in_at = now();

        // Save the updated login times to the user's record.
        $event->user->save();
    }
}
