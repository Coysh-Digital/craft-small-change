<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\web\assets\frontend;

use craft\web\AssetBundle;

/**
 * The one front-end script: the Gift Aid toggle and Stripe's payment form. Registered by
 * craft.smallChange.registerScripts() so a site never has to copy it into its web root.
 */
final class FrontEndAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['small-change.js'];

        parent::init();
    }
}
