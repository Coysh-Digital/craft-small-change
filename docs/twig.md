# Twig reference

Everything is on `craft.smallChange`.

## Products

```twig
{% set funds = craft.smallChange.products({ kind: 'donation' }).all() %}
{% set mug = craft.smallChange.products({ sku: 'MUG-1' }).one() %}
```

`products()` is an ordinary Craft element query. Extra parameters: `type` (a product type handle),
`typeId`, `kind` (`donation`, `physical`, `nonPhysical`) and `sku`. A product has `title`, `price`, `sku`,
`type`, and for donations `suggestedAmounts`, `minimumAmount` (null when unset), `effectiveMinimum`,
`allowCustomAmount`, `allowRecurring` and `allowedIntervals`, plus any custom fields on its layout.

## Orders

```twig
{# The signed-in user's own completed orders. Nothing for a guest. #}
{% for order in craft.smallChange.orders({ orderBy: 'dateCompleted desc' }).all() %}
```

On the site, `orders()` is always limited to the signed-in user's completed orders. A visitor who isn't signed in
gets nothing, whatever criteria you pass, so a template can't be coaxed into listing other people's orders. In
the control panel and on the command line there's no such limit. An order's properties include
`number`, `email`, `state`, `paymentStatus`, `kind`, `total`, `totalPaid`, `totalRefunded`, `currency`,
`dateCompleted`, `giftAidDeclared`, `giftAidEligibleAmount` and `lineItems`.

```twig
{# After payment: only returns the order if this browser session placed it. #}
{% set order = craft.smallChange.recentOrder(craft.app.request.getParam('number')) %}
```

Order numbers show up in receipts and in Stripe, so Small Change never lets one grant access on its own.

## Totals for counters

```twig
{{ craft.smallChange.formatMoney(craft.smallChange.donationTotal(fund), null, true) }}
{{ craft.smallChange.donationTotal(fund, entry.campaignStart, entry.campaignEnd) }}
```

`donationTotal(product, from, to)` returns what has been given to a donation product (pass the product or its
ID), in minor units, as one number, which is what you want for a "raised so far" bar. It counts the donation line
of each completed one-off order and each recurring payment that succeeded (less refunds on recurring payments). A one-off order that has
been fully refunded is left out. A partial refund on a one-off order isn't deducted, because it can't be traced to
the donation line. Gifts made with the other kind of Stripe key never count, so test donations on a live site
stay out of your totals.

`from` and `to` are optional. In the second example they come from date fields on an entry, but a `DateTime` or
any date string Craft can read works too. `from` is included and `to` is excluded, so two periods that meet never
count the same gift twice. The function returns only the total and nothing about the donors.

## Basket and checkout

| | |
| --- | --- |
| `cart` / `getCart(true)` | The visitor's basket, or null (pass `true` to start one). |
| `cartSummary()` | A plain array for scripts: lines, totals, never the customer's details. Null without a basket. |
| `shippingMethods(order?)` | Delivery methods that serve the order's delivery country. Without one, the methods that serve every country. |
| `checkoutFields()` | The custom checkout fields to render in the basket. |
| `donationFields()` | The custom fields to render on the donation form. |
| `checkoutClientSecret(order)` | The secret the payment form needs (embedded mode). |
| `checkoutUrl(order)` | Stripe's payment page (hosted mode). |
| `registerScripts(payment?)` | Adds the front-end script through your layout's `endBody()`. Pass `true` on a payment page to load Stripe.js first. See [Scripts](templates.md#scripts). |

Both return null unless the order is pending and this browser session placed it.

Anything on this list that reads the visitor's basket or order (and `orders()` above) is personal to them, so a
page that calls it is kept out of static caches. See [Caching and Blitz](caching.md).

A basket (and any order) has `lineItems`, `itemSubtotal`, `discountAmount`, `discountCode`, `shippingAmount`,
`shippingMethodId`, `shippingMethodName`, `shippingCountryCode`, `shippingAddress`, `requiresShipping`,
`customerNote`, `email` and `total`. A line item has `description`, `kind` (`donation`, `physical` or
`nonPhysical`), `qty`, `unitPrice`, `subtotal` and `purchasableId`.

## Settings and formatting

| | |
| --- | --- |
| `currency`, `settings`, `checkoutMode` | Configuration. `settings` leaves out the secret Stripe values and gives `charityName` as the name itself, never an environment variable. |
| `formatMoney(amount, currency?, stripZeros?)` | Turns `1250` into `£12.50`; with `stripZeros`, `1200` becomes `£12`. |
| `decimal(amount, currency?)` | Turns `1250` into `12.50`, for form values. |
| `isTestMode`, `publishableKey`, `stripeJsUrl` | For the payment page. `publishableKey` is null unless the setting holds a `pk_` key. |
| `giftAid.enabled`, `giftAid.appliesTo(order)`, `giftAid.declarationText` | For the Gift Aid tick box. `appliesTo` is true when Gift Aid is on and the order has a donation line. `declarationText` is the wording for the scope you chose, with the charity name filled in. See [Gift Aid](gift-aid.md). |

Money is always whole minor units (pence), and a single currency applies to the whole install.
