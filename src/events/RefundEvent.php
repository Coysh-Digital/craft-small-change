<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Refund;
use yii\base\Event;

class RefundEvent extends Event
{
    public Refund $refund;
    public Payment $payment;
    public ?Order $order = null;
}
