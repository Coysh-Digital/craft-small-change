<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\exceptions;

use yii\base\Exception;

final class StripeNotConfiguredException extends Exception
{
    public function getName(): string
    {
        return 'Stripe not configured';
    }
}
