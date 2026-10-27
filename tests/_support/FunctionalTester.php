<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests;

use Codeception\Actor;

/**
 * @method void wantTo($text)
 * @method void amGoingTo($argumentation)
 */
class FunctionalTester extends Actor
{
    use _generated\FunctionalTesterActions;
}
