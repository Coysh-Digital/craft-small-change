<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\models\ProductTypeSite;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class ProductTypesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('small-change/settings/product-types/index', [
            'productTypes' => Plugin::getInstance()->productTypes->getAllProductTypes(),
            'kindLabels' => ProductType::kindLabels(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionEdit(?int $productTypeId = null, ?ProductType $productType = null): Response
    {
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        if ($productTypeId === null && $readOnly) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }

        $brandNew = false;

        if ($productTypeId !== null) {
            $productType ??= Plugin::getInstance()->productTypes->getProductTypeById($productTypeId);

            if (!$productType) {
                throw new NotFoundHttpException('Product type not found');
            }

            $title = trim((string) $productType->name) ?: Craft::t('small-change', 'Edit product type');
        } else {
            if ($productType === null) {
                $productType = new ProductType();
                $brandNew = true;
            }

            $title = Craft::t('small-change', 'Create a product type');
        }

        return $this->renderTemplate('small-change/settings/product-types/_edit', [
            'productType' => $productType,
            'productTypeId' => $productTypeId,
            'brandNew' => $brandNew,
            'readOnly' => $readOnly,
            'title' => $title,
            'kindOptions' => array_map(
                static fn(string $kind, string $label): array => ['value' => $kind, 'label' => $label],
                array_keys(ProductType::kindLabels()),
                ProductType::kindLabels(),
            ),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $service = Plugin::getInstance()->productTypes;
        $id = $this->request->getBodyParam('productTypeId');

        if ($id) {
            $productType = $service->getProductTypeById((int) $id);

            if (!$productType) {
                throw new BadRequestHttpException("Invalid product type ID: $id");
            }
        } else {
            $productType = new ProductType();
        }

        $productType->name = (string) $this->request->getBodyParam('name');
        $productType->handle = (string) $this->request->getBodyParam('handle');
        $productType->kind = (string) ($this->request->getBodyParam('kind') ?? $productType->kind);
        $productType->skuRequired = (bool) $this->request->getBodyParam('skuRequired');

        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $posted = $this->request->getBodyParam('sites.' . $site->handle) ?? [];

            $settings = new ProductTypeSite(['siteId' => $site->id]);
            $settings->hasUrls = !empty($posted['uriFormat']);

            if ($settings->hasUrls) {
                $settings->uriFormat = $posted['uriFormat'];
                $settings->template = $posted['template'] ?? null;
            }

            $siteSettings[$site->id] = $settings;
        }

        $productType->setSiteSettings($siteSettings);

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Product::class;
        $productType->setFieldLayout($fieldLayout);

        if (!$service->saveProductType($productType)) {
            return $this->asModelFailure($productType, Craft::t('small-change', "Couldn't save the product type."), 'productType');
        }

        $this->setSuccessFlash(Craft::t('small-change', 'Product type saved.'));

        return $this->redirectToPostedUrl($productType);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $service = Plugin::getInstance()->productTypes;
        $productType = $service->getProductTypeById((int) $this->request->getRequiredBodyParam('id'));

        if (!$productType) {
            throw new BadRequestHttpException('Invalid product type ID');
        }

        $service->deleteProductType($productType);

        return $this->asSuccess();
    }
}
