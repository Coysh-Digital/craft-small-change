<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\exceptions;

use yii\base\UserException;

/**
 * A refund that can't be made, with a reason a member of staff can act on.
 */
final class RefundException extends UserException
{
}
