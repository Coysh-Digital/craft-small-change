<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\db;

/**
 * Table names, so migrations, records and queries never repeat a literal.
 */
abstract class Table
{
    public const PRODUCT_TYPES = '{{%smallchange_producttypes}}';
    public const PRODUCT_TYPES_SITES = '{{%smallchange_producttypes_sites}}';
    public const PRODUCTS = '{{%smallchange_products}}';
    public const STRIPE_PRODUCTS = '{{%smallchange_stripeproducts}}';
    public const ORDERS = '{{%smallchange_orders}}';
    public const LINE_ITEMS = '{{%smallchange_lineitems}}';
    public const PAYMENTS = '{{%smallchange_payments}}';
    public const REFUNDS = '{{%smallchange_refunds}}';
    public const SUBSCRIPTIONS = '{{%smallchange_subscriptions}}';
    public const CUSTOMERS = '{{%smallchange_customers}}';
    public const DISCOUNTS = '{{%smallchange_discounts}}';
    public const DISCOUNT_RESERVATIONS = '{{%smallchange_discountreservations}}';
    public const SHIPPING_METHODS = '{{%smallchange_shippingmethods}}';
    public const WEBHOOK_EVENTS = '{{%smallchange_webhookevents}}';
    public const STATE = '{{%smallchange_state}}';
}
