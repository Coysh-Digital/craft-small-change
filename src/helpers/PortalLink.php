<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

/**
 * The signed, expiring link a donor is emailed to reach Stripe's customer portal. It carries the customer and
 * where to send them back to, and nothing else; the signature is what proves it came from this site.
 */
final class PortalLink
{
    public const TTL = 900;

    public static function make(int $customerId, ?string $returnUrl): string
    {
        $payload = StringHelper::base64UrlEncode((string) json_encode(['c' => $customerId, 'e' => time() + self::TTL, 'r' => $returnUrl]));
        $path = trim(Craft::$app->getConfig()->getGeneral()->actionTrigger, '/') . '/small-change/portal/open';

        // The public site's address, even when this runs from the queue in a control panel request.
        return UrlHelper::siteUrl($path, ['link' => Craft::$app->getSecurity()->hashData($payload)]);
    }

    /**
     * @return array{c: int, r: string|null}|null null when the link was altered or has expired
     */
    public static function parse(string $token): ?array
    {
        $payload = Craft::$app->getSecurity()->validateData($token);

        if (!is_string($payload)) {
            return null;
        }

        $data = json_decode(StringHelper::base64UrlDecode($payload), true);

        if (!is_array($data) || !is_int($data['c'] ?? null) || !is_int($data['e'] ?? null) || $data['e'] < time()) {
            return null;
        }

        return ['c' => $data['c'], 'r' => is_string($data['r'] ?? null) ? $data['r'] : null];
    }
}
