<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Payment;
use yii\base\Event;

class PaymentEvent extends Event
{
    public Payment $payment;
    public ?Order $order = null;
    public bool $giftAidDeclared = false;
    public int $giftAidAmount = 0;

    public function init(): void
    {
        parent::init();

        $this->giftAidDeclared = $this->payment->giftAidDeclared;
        $this->giftAidAmount = $this->payment->giftAidAmount;
    }
}
