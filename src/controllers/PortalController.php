<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\helpers\PortalLink;
use coyshdigital\smallchange\helpers\RateLimit;
use coyshdigital\smallchange\models\Customer;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Stripe\Exception\ApiErrorException;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;
use yii\web\TooManyRequestsHttpException;

/**
 * Lets a donor manage their own recurring donation through Stripe's customer portal. There are no
 * donor accounts: they ask for a link by email address, and the link is the proof they own it.
 */
final class PortalController extends Controller
{
    private const MAX_REQUESTS = 5;
    private const WINDOW_SECONDS = 3600;

    protected array|bool|int $allowAnonymous = ['request-link', 'open'];

    /**
     * Emails a signed link that expires. The answer is the same whether or not the address has a recurring
     * donation, so the form can't be used to find out who donates.
     */
    public function actionRequestLink(): ?Response
    {
        $this->requirePostRequest();

        if (RateLimit::exceeded('portal', self::MAX_REQUESTS, self::WINDOW_SECONDS)) {
            throw new TooManyRequestsHttpException(Craft::t('small-change', 'Too many attempts. Please wait a while and try again.'));
        }

        $plugin = Plugin::getInstance();

        // The same answer for every address, so this doesn't reveal who donates; it only says the link can't be sent at all.
        if (!$plugin->getSettings()->emailEnabled(Emails::KEY_PORTAL_LINK)) {
            return $this->asFailure(Craft::t('small-change', "We can't email links to manage a recurring donation at the moment. Please get in touch with us instead."));
        }

        $email = (string) $this->request->getBodyParam('email', '');
        $mode = $plugin->stripe->mode();
        $customer = $mode !== null && $email !== '' ? $plugin->customers->getByEmail($email, $mode) : null;

        if ($customer !== null && $this->hasLiveSubscription($customer)) {
            $returnUrl = $this->request->getValidatedBodyParam('returnUrl');
            $plugin->emails->queuePortalLink($customer, is_string($returnUrl) ? $returnUrl : null);
        }

        return $this->asSuccess(
            Craft::t('small-change', "If that email address has a recurring donation, we've emailed you a link to manage it."),
            [],
            $this->request->getValidatedBodyParam('redirect') ?: null,
        );
    }

    /**
     * Follows an emailed link (not named `token`, which Craft reserves for its own token routes), or a signed-in user's own click, to the Stripe portal.
     */
    public function actionOpen(): Response
    {
        $plugin = Plugin::getInstance();
        $token = (string) $this->request->getParam('link', '');
        $customer = null;
        $returnUrl = null;

        if ($token !== '') {
            $data = PortalLink::parse($token);

            if ($data === null) {
                throw new BadRequestHttpException(Craft::t('small-change', 'This link has expired. Please ask for a new one.'));
            }

            $mode = $plugin->stripe->mode();
            $customer = $plugin->customers->getById($data['c']);
            $returnUrl = $data['r'];

            if ($customer === null || $mode === null || $customer->stripeMode !== $mode) {
                throw new BadRequestHttpException(Craft::t('small-change', "This link isn't valid."));
            }
        } else {
            $user = Craft::$app->getUser()->getIdentity();
            $mode = $plugin->stripe->mode();
            $customer = $user !== null && $mode !== null ? $this->customerOf($user, $mode) : null;
            $returnUrl = $this->validatedReturnUrl();

            if ($customer === null) {
                throw new BadRequestHttpException(Craft::t('small-change', 'There is nothing to manage for this account.'));
            }
        }

        if ($customer->stripeCustomerId === null) {
            throw new BadRequestHttpException(Craft::t('small-change', 'There is nothing to manage for this account.'));
        }

        try {
            $session = $plugin->stripe->createPortalSession($customer->stripeCustomerId, is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : UrlHelper::siteUrl());
        } catch (ApiErrorException $e) {
            Craft::warning('Could not open the billing portal: ' . $e->getMessage(), __METHOD__);

            throw new ServerErrorHttpException(Craft::t('small-change', "We couldn't open the billing portal. Please try again, or contact us."));
        }

        return $this->redirect((string) $session->url);
    }

    /**
     * The signed `returnUrl` query param, or null when none was sent. An unsigned or tampered value is
     * rejected rather than followed, so a link can't send a donor on to someone else's site.
     */
    private function validatedReturnUrl(): ?string
    {
        $value = $this->request->getQueryParam('returnUrl');

        if ($value === null) {
            return null;
        }

        $validated = is_string($value) ? Craft::$app->getSecurity()->validateData($value) : false;

        if ($validated === false) {
            throw new BadRequestHttpException('Request contained an invalid query param');
        }

        return $validated;
    }

    /**
     * The customer behind a recurring gift the user made while signed in. Matching the account's email address
     * alone would hand a donor's cards and invoices to anyone who registers with it, on a site that doesn't verify
     * addresses. Someone who gave as a guest asks for an emailed link instead, which does prove they own it.
     */
    private function customerOf(User $user, string $mode): ?Customer
    {
        $order = Order::find()
            ->userId($user->id)
            ->kind(Order::KIND_RECURRING)
            ->stripeMode($mode)
            ->isCompleted()
            ->status(null)
            ->orderBy(['smallchange_orders.dateCompleted' => SORT_DESC])
            ->one();

        return $order && $order->email ? Plugin::getInstance()->customers->getByEmail($order->email, $mode) : null;
    }

    private function hasLiveSubscription(Customer $customer): bool
    {
        foreach (Plugin::getInstance()->subscriptions->getSubscriptionsByCustomerId((int) $customer->id) as $subscription) {
            if ($subscription->getIsLive()) {
                return true;
            }
        }

        return false;
    }
}
