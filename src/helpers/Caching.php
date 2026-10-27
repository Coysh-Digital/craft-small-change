<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use Craft;
use craft\web\Response;
use Throwable;
use yii\base\Module;

/**
 * Keeps a page that shows one visitor's basket, order or payment secret out of any cache that would hand it to
 * the next visitor: browsers and CDNs through headers, and Blitz (when it is installed) by asking it to skip the page.
 */
final class Caching
{
    public const BLITZ_HANDLE = 'blitz';

    public static function keepPrivate(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return;
        }

        $response = Craft::$app->getResponse();

        if ($response instanceof Response) {
            $response->setNoCacheHeaders();
        }

        self::skipBlitz();
    }

    /**
     * The same thing as `{% do craft.blitz.options({ cachingEnabled: false }) %}` in a template. Blitz is never
     * required: with it absent, or if its internals change, the headers above still apply.
     */
    private static function skipBlitz(): void
    {
        $blitz = Craft::$app->getPlugins()->getPlugin(self::BLITZ_HANDLE);

        if (!$blitz instanceof Module) {
            return;
        }

        try {
            /** @var mixed $generateCache */
            $generateCache = $blitz->get('generateCache');
            $generateCache->options->cachingEnabled = false;
        } catch (Throwable $e) {
            Craft::warning('Could not ask Blitz to skip this page: ' . $e->getMessage(), __METHOD__);
        }
    }
}
