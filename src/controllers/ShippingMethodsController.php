<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\ShippingMethod;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class ShippingMethodsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SHIPPING);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('small-change/shipping/index', [
            'methods' => Plugin::getInstance()->shippingMethods->getAllMethods(),
        ]);
    }

    public function actionEdit(?int $methodId = null, ?ShippingMethod $method = null): Response
    {
        if ($method === null) {
            if ($methodId !== null) {
                $method = Plugin::getInstance()->shippingMethods->getMethodById($methodId);

                if (!$method) {
                    throw new NotFoundHttpException('Shipping method not found');
                }
            } else {
                $method = new ShippingMethod();
            }
        }

        $currency = Plugin::getInstance()->getSettings()->currency;

        return $this->renderTemplate('small-change/shipping/_edit', [
            'method' => $method,
            'currency' => $currency,
            'title' => $method->id ? $method->name : Craft::t('small-change', 'New shipping method'),
            'priceValue' => $method->id || $method->price ? Money::toDecimal($method->price, $currency) : '',
            'countriesValue' => implode(', ', $method->countryCodes ?? []),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->shippingMethods;
        $currency = Plugin::getInstance()->getSettings()->currency;
        $id = $this->request->getBodyParam('methodId');
        $method = $id ? $service->getMethodById((int) $id) : new ShippingMethod();

        if (!$method) {
            throw new BadRequestHttpException("Invalid shipping method ID: $id");
        }

        $method->name = trim((string) $this->request->getBodyParam('name'));
        $method->handle = trim((string) $this->request->getBodyParam('handle'));
        $method->enabled = (bool) $this->request->getBodyParam('enabled');

        $countries = preg_split('/[\s,;]+/', strtoupper((string) $this->request->getBodyParam('countryCodes')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $method->countryCodes = $countries ? array_values(array_unique($countries)) : null;

        $price = trim((string) $this->request->getBodyParam('price'));
        $priceError = null;

        if ($price === '' || preg_match('/^[0.,\s]*$/', $price) === 1) {
            $method->price = 0;
        } else {
            $minor = Money::parse($price, $currency);
            $method->price = $minor ?? 0;
            $priceError = $minor === null ? Craft::t('small-change', 'Enter a price such as 3.50, or 0 for free delivery.') : null;
        }

        $valid = $method->validate();

        if ($priceError !== null) {
            $method->addError('price', $priceError);
            $valid = false;
        }

        if (!$valid || !$service->saveMethod($method, false)) {
            return $this->asModelFailure($method, Craft::t('small-change', "Couldn't save the shipping method."), 'method');
        }

        $this->setSuccessFlash(Craft::t('small-change', 'Shipping method saved.'));

        return $this->redirectToPostedUrl($method);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decodeIfJson($this->request->getRequiredBodyParam('ids'));

        if (!is_array($ids)) {
            throw new BadRequestHttpException('Invalid shipping method IDs');
        }

        Plugin::getInstance()->shippingMethods->reorder(array_map('intval', $ids));

        return $this->asSuccess();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = Plugin::getInstance()->shippingMethods;
        $method = $service->getMethodById((int) $this->request->getRequiredBodyParam('id'));

        if (!$method) {
            throw new BadRequestHttpException('Invalid shipping method ID');
        }

        $service->deleteMethod($method);

        return $this->asSuccess();
    }
}
