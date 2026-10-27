<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\Plugin;
use craft\web\Controller;
use yii\web\Response;

final class WebhooksController extends Controller
{
    /**
     * Stripe keeps delivering while the site is offline for maintenance, so the endpoint stays open then too.
     */
    protected array|bool|int $allowAnonymous = ['stripe' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE];

    /**
     * Stripe cannot send a CSRF token; the signature check is what authenticates the request.
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'stripe') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionStripe(): Response
    {
        $this->requirePostRequest();

        $result = Plugin::getInstance()->webhooks->handleRequest(
            $this->request->getRawBody(),
            $this->request->getHeaders()->get('Stripe-Signature'),
        );

        $this->response->setStatusCode($result['status']);

        return $this->asRaw($result['message']);
    }
}
