<?php

declare(strict_types=1);

return [
    'securityKey' => 'small-change-tests-not-a-secret',
    'devMode' => true,
    // Functional tests post forms without a browser's CSRF cookie. Which actions opt out is checked in ControllersTest.
    'enableCsrfProtection' => false,
    'defaultWeekStartDay' => 1,
];
