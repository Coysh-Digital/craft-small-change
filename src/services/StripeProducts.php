<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\StripeProductRecord;
use Craft;
use craft\base\Component;
use Stripe\Exception\ApiErrorException;
use yii\db\IntegrityException;

/**
 * Recurring donations hang off a Stripe Product so the Dashboard groups them by campaign. One is
 * created the first time a product is given to monthly, per Stripe mode, and reused after that.
 */
class StripeProducts extends Component
{
    /**
     * @throws ApiErrorException
     */
    public function ensure(Product $product, string $mode): string
    {
        $record = StripeProductRecord::findOne(['productId' => $product->id, 'stripeMode' => $mode]);

        if ($record) {
            return $record->stripeProductId;
        }

        $created = Plugin::getInstance()->stripe->createProduct([
            'name' => mb_substr((string) $product->title, 0, 250) ?: 'Donation',
            'metadata' => ['smallChangeProductId' => (string) $product->id],
        ], 'product-' . $product->id . '-' . $mode);

        $record = new StripeProductRecord();
        $record->productId = (int) $product->id;
        $record->stripeMode = $mode;
        $record->stripeProductId = (string) $created->id;

        // Its own (nested) transaction: on PostgreSQL a failed insert poisons whatever transaction it is in.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->save(false);
            $transaction->commit();
        } catch (IntegrityException) {
            $transaction->rollBack();
            // A concurrent request created the same link first; theirs is the one to use.
            $record = StripeProductRecord::findOne(['productId' => $product->id, 'stripeMode' => $mode]) ?? $record;
        }

        return $record->stripeProductId;
    }

    /**
     * Makes Stripe's products match ours: creates the missing ones for products that allow recurring
     * donations and renames any whose title has changed.
     *
     * @return array{created: int, updated: int, failed: int}
     */
    public function syncAll(string $mode): array
    {
        $result = ['created' => 0, 'updated' => 0, 'failed' => 0];
        $stripe = Plugin::getInstance()->stripe;

        /** @var Product[] $products */
        $products = Product::find()->kind(ProductType::KIND_DONATION)->status(null)->all();

        foreach ($products as $product) {
            $record = StripeProductRecord::findOne(['productId' => $product->id, 'stripeMode' => $mode]);

            try {
                if ($record) {
                    $stripe->updateProduct($record->stripeProductId, ['name' => mb_substr((string) $product->title, 0, 250) ?: 'Donation']);
                    $result['updated']++;
                } elseif ($product->allowRecurring) {
                    $this->ensure($product, $mode);
                    $result['created']++;
                }
            } catch (ApiErrorException $e) {
                Craft::warning('Could not sync product ' . $product->id . ' to Stripe: ' . $e->getMessage(), __METHOD__);
                $result['failed']++;
            }
        }

        return $result;
    }
}
