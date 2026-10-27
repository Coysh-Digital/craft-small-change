<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use coyshdigital\smallchange\models\Subscription;
use yii\base\Event;

class SubscriptionEvent extends Event
{
    public Subscription $subscription;

    /**
     * Null when the subscription has only just been recorded.
     */
    public ?string $oldStatus = null;
    public string $newStatus = '';
}
