<?php

declare(strict_types=1);

use craft\services\ProjectConfig;

// Tests never need project config written to disk, and doing so on a mounted volume made runs flaky.
return [
    'components' => [
        'projectConfig' => [
            'class' => ProjectConfig::class,
            'writeYamlAutomatically' => false,
        ],
    ],
];
