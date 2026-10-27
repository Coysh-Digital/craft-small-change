<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\events;

use yii\base\Event;
use yii\mail\MessageInterface;

class EmailEvent extends Event
{
    public string $key;
    public MessageInterface $message;
    public bool $isValid = true;
}
