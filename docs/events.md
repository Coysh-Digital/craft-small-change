# Events

These are the hooks for pushing data into a CRM, adding your own logic or changing what gets sent. Register
listeners in your own module or plugin with `yii\base\Event::on()`.

```php
use coyshdigital\smallchange\events\OrderEvent;
use coyshdigital\smallchange\services\Orders;
use yii\base\Event;

Event::on(Orders::class, Orders::EVENT_AFTER_COMPLETE_ORDER, function(OrderEvent $event) {
    // $event->order, $event->giftAidDeclared, $event->giftAidEligibleAmount,
    // $event->giftAidDeclarationText, $event->billingAddress
});
```

## Orders (`services\Orders`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_BEFORE_COMPLETE_ORDER` | `OrderEvent` (cancelable) | Just before a paid order is completed. Carries the same properties as the after event. Set `isValid = false` to hold it back (the next webhook or return trip will retry it). |
| `EVENT_AFTER_COMPLETE_ORDER` | `OrderEvent` | The order is completed and paid. Carries `order`, `giftAidDeclared` (null when Gift Aid wasn't offered), `giftAidEligibleAmount` (0 unless they said yes), `giftAidDeclarationText` (the wording shown) and `billingAddress` (the donor's home address). This is where to send a Gift Aid declaration to your CRM, because the plugin keeps none. |

## Payments (`services\Payments`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_AFTER_PAYMENT_SUCCEEDED` | `PaymentEvent` | Fires once for each payment, including every recurring payment. Carries `payment`, `order`, `giftAidDeclared` and `giftAidAmount`, so a CRM can record each payment of a recurring donation. |
| `EVENT_AFTER_PAYMENT_FAILED` | `PaymentEvent` | The same properties as above. |
| `EVENT_AFTER_REFUND` | `RefundEvent` | `refund`, `payment`, `order`. Fires when a refund is first recorded and again when its status changes. |

## Recurring donations (`services\Subscriptions`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_AFTER_STATUS_CHANGE` | `SubscriptionEvent` | `subscription`, `oldStatus` (null when first recorded) and `newStatus`. |

## Checkout (`services\Checkout`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_BUILD_CHECKOUT_SESSION` | `BuildCheckoutSessionEvent` | Carries `order` and `params`. Change `params` to change what's sent to Stripe (for example add to `metadata`, or set `locale`). Anything you do to `line_items` (and so the amounts), `mode` or the plugin's own `discounts` is put back afterwards, because the totals are the server's. Don't add `discounts` to a session that has none either. Don't set `payment_method_types`: the API version Small Change pins no longer accepts it, and Stripe picks the methods from your Dashboard settings. |

## Webhooks (`services\Webhooks`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_BEFORE_PROCESS_EVENT` | `WebhookEvent` | Every stored event for the site's Stripe mode, including replayed ones, before the plugin handles it. Carries `stripeEvent`. Set `actedOn = true` to have the event recorded as processed rather than ignored. |
| `EVENT_AFTER_PROCESS_EVENT` | `WebhookEvent` | After the plugin's handler; `actedOn` says whether anything acted on it. React to other Stripe event types here. |

Both run inside the same database transaction as the plugin's handler. If your listener throws, the whole event is rolled back, marked failed and answered with a 500, so Stripe retries it.

## Emails (`services\Emails`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_BEFORE_SEND` | `EmailEvent` | `key` (for example `receipt`) and `message`. Set `isValid = false` to cancel; the send then counts as done. |

## Privacy (`services\Privacy`)

| Constant | Event class | Notes |
| --- | --- | --- |
| `EVENT_AFTER_ANONYMISE_ORDER` | `OrderEvent` | An order's personal data has just been erased. Only `order` is set, and its email is already the anonymised one, so match the donor in your CRM by something you stored earlier, such as the order number. |

## Craft's own events

Orders and products are normal elements, so Craft's element events (`Elements::EVENT_AFTER_SAVE_ELEMENT`
and the rest) work on them too.
