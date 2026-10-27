<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use coyshdigital\smallchange\elements\Order;
use yii\base\Event;

class BuildCheckoutSessionEvent extends Event
{
    public Order $order;

    /**
     * @var array<string, mixed> the parameters for the Checkout Session; amount and line-item changes are discarded
     */
    public array $params = [];
}
