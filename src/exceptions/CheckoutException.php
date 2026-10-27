<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\exceptions;

use yii\base\UserException;

/**
 * Something the donor can be told: the order can't be paid, or Stripe refused it.
 */
final class CheckoutException extends UserException
{
}
