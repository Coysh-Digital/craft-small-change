<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

/**
 * What a template install did, for the control panel page and the console command to show.
 */
final class InstallResult
{
    /**
     * @param string[] $created paths relative to the templates folder
     * @param string[] $overwritten
     * @param string[] $skipped files that already existed and were left alone
     * @param string[] $warnings
     * @param string[] $nextSteps
     */
    public function __construct(
        public array $created = [],
        public array $overwritten = [],
        public array $skipped = [],
        public array $warnings = [],
        public array $nextSteps = [],
    ) {
    }

    public function written(): int
    {
        return count($this->created) + count($this->overwritten);
    }
}
