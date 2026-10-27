<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use Stripe\Event as StripeEvent;
use yii\base\Event;

class WebhookEvent extends Event
{
    public StripeEvent $stripeEvent;
    /**
     * Named to avoid yii\base\Event::$handled, which stops other listeners from running.
     */
    public bool $actedOn = false;
}
