# Your front-end templates

Small Change doesn't decide what your donation form or shop looks like. It comes with ready-made pages that you
install into your own `templates/` folder: plain HTML, a little vanilla JavaScript and no CSS at all. Once
they're installed they're yours, so change the wording, markup and styling as much as you like. We kept the
markup plain so it's easy to restyle.

## Quick start

1. Open **Small Change → Settings → Templates** in the control panel.
2. Tick what you want to offer: **Donations**, **Recurring donor pages**, **Shop**, or any mix of them.
3. Choose where the pages sit. Either use your site's own layout (give its path, such as `_layouts/site`, and the
   name of the block your layout puts page content in), or let the installer add a plain one to start from.
4. Press **Install templates**, then visit `/donate` or `/shop`.

You don't need to add any routes or copy anything into your web root. Even if you plan to write your own pages,
we'd run the installer first: it's the quickest way to see everything working, and then you can start changing
things.

Prefer the terminal? The command asks which sets you want, which layout and which block, or you can pass the
answers as options:

```bash
php craft small-change/templates/install
php craft small-change/templates/install --sets=donations,shop --layout=_layouts/site --block=content
php craft small-change/templates/install --sets=all --layout=          # plain layout
```

| Option | Meaning |
| --- | --- |
| `--sets` | `donations`, `recurring`, `shop` (comma separated) or `all`. |
| `--layout` | Your layout template, like `_layouts/site`. Pass it empty (`--layout=`) to install the plain layout. |
| `--block` | The block in that layout that page content goes in (default `content`). |
| `--folder` | Put everything in a folder inside `templates/`, such as `small-change` (see below). |
| `--overwrite` | Replace files that already exist. Without it they are left alone. |
| `--yes` | Don't ask before overwriting. |

If your site already has pages at `/cart`, `/checkout` or `/account`, give the installer a **Folder**
(`--folder=small-change` on the command line). Everything is written inside that folder and the pages' links,
redirects and includes are rewritten to match, so the shop sits at `/small-change/shop`, the basket at
`/small-change/cart` and so on, and your own templates are left alone. The shared partials then live in
`templates/small-change/_small-change/`. Use the same folder every time you install, and add it to the
template names in the examples below.

The installer never replaces a file unless you ask, so you can safely run it again later to add another set.
Because it writes files, the Templates tab only shows up for admins, and only on an environment where
`allowAdminChanges` is on. Install in development, commit the files and deploy them like any other template.

## What each set installs

| Set | URL | Template | What it is |
| --- | --- | --- | --- |
| Donations | `/donate` | `donate/index.twig` | The donation form, one-off or recurring. |
| | `/donate/payment` | `donate/payment.twig` | Where the donor pays. |
| | `/donate/complete` | `donate/complete.twig` | The thank-you page. It also covers "processing" and "not paid". |
| | | `donate/_form.twig` | The donation form on its own, so it can go on any page (see below). |
| | | `donate/_progress.twig` | An optional "raised so far" bar. Include it with `{% include 'donate/_progress' ignore missing %}` (see below). |
| Recurring donor pages | `/donate/manage` | `donate/manage.twig` | A donor asks for an emailed link to manage their gift. |
| | `/account/donations` | `account/donations.twig` | A signed-in user's gifts and orders. |
| Shop | `/shop` | `shop/index.twig` | Product list with an add-to-basket form. |
| | product URLs | `shop/product.twig` | One product (see below). |
| | | `shop/_add-form.twig` | The add-to-basket form. |
| | `/cart` | `cart.twig` | The basket. |
| | `/checkout` | `checkout/index.twig` | Email, delivery, checkout fields, Gift Aid. |
| | `/checkout/payment` | `checkout/payment.twig` | Where the customer pays. |
| | `/checkout/complete` | `checkout/complete.twig` | The thank-you page. |

The shared pieces (the Gift Aid tick box, address fields, the checkout and donation fields, and the small `csrf`, `dynamic`,
`flash` and `basket-link` partials that keep the pages working behind a cache like Blitz) go in
`templates/_small-change/`, so they can't clash with a folder of your own. If you chose the plain layout, it
lives at `templates/_small-change/layout.twig`. The cache partials are explained in
[Caching and Blitz](caching.md).

Craft maps a URL to a template by path, so `/checkout` finds `checkout/index.twig` and `/donate/payment` finds
`donate/payment.twig` without any routes.

### Including one of these pages from your own templates

If an `{% include %}` names a template that doesn't exist, Twig stops and the whole page returns a 500. For the
"raised so far" bar, write it like this:

```twig
{% include 'donate/_progress' ignore missing %}
```

`ignore missing` makes the include output nothing when the file isn't there, so a page you've already put live
doesn't break while the templates are still being installed. We'd use it on every include of a Small Change
page. Check the name as well: the file is `donate/_progress`, with the underscore, and `donate/progress` is a
different template that won't be found.

### Product pages

To give shop products their own pages, open **Small Change → Product types**, edit the type, and in the **Site
settings** table set the **Product URI format** to `shop/{slug}` and the **Template** to `shop/product`. The
page receives the product as `product`. Donation products usually don't need a page of their own: `/donate`
shows one, and `donate/_form` can put any of them on a page of yours.

You don't have to give products pages at all. Leave the **Product URI format** blank for the type and show the
products you want on pages of your own, as described next. You still need the basket and checkout pages from
the **Shop** set, because that's where the customer pays.

## Putting a product or a donation form on any page

`donate/_form` is the donation form on its own and `shop/_add-form` is the add-to-basket form, so either can go on
any page you like, not only `/donate` or `/shop`. A **Small Change Products** field lets an editor choose which
product or donation a block shows. [Showing one product or a donation form on a page](blocks.md) walks through
it.

## How a payment flows

1. The visitor submits a form (`/donate` or `/checkout`) to a Small Change action. The plugin checks
   everything on the server, works out the total itself, creates the order and a Stripe Checkout Session.
2. The visitor goes to the payment page with the order number in the address (`/donate/payment?number=…`).
   With the **Payment form** setting on **Embedded on your site** (the default, see
   [Settings](settings.md#checkout)), this page shows Stripe's payment form inside your page, and card details
   go straight from the browser to Stripe. With **Stripe-hosted page**, the form sends people to Stripe's own
   page instead and its redirect is ignored. The payment page is then only where they land if they back out.
3. When they've paid, Stripe sends them back and Small Change forwards them to your thank-you page
   (`/donate/complete?number=…`).
4. Stripe's webhook is what actually marks the order paid. The thank-you page can load before or after the
   webhook arrives, so it handles "completed", "processing" and "not paid".

The payment and thank-you pages find the order with `craft.smallChange.recentOrder(number)`. It only returns the
order to the browser session that placed it, or to someone with a signed link that expires. An order number on
its own shows nothing.

## Using your own layout

Each page is a Twig template that extends a layout and puts its content in a block:

```twig
{% extends '_layouts/site' %}
{% set title = 'Donate' %}

{% block content %}
    …
{% endblock %}
```

The installer writes your layout path and block name into every page. Your layout needs three things:

- The block the pages fill (`{% block content %}{% endblock %}`, or whatever name you gave it).
- `{{ head() }}`, `{{ beginBody() }}` and `{{ endBody() }}`. The payment form's JavaScript, and Stripe's, are
  added through `endBody()`, so without it the form never appears. The installer warns if it can't see
  `endBody()` in the layout you name.
- A viewport meta tag, `<meta name="viewport" content="width=device-width, initial-scale=1">`. Without it,
  Stripe's form warns and sizes badly on phones.

Every page sets a `title` variable you can print in your `<title>`.

To switch layout later, edit the `{% extends %}` line near the top of each page and the `{% block %}` name, or
run the installer again with `--overwrite`. That replaces the pages, so keep a copy of any edits first.

## Moving a page to another URL

The pages are plain templates, so moving `/donate` to `/give` means renaming the folder and updating the paths
inside. Where the template lives has to agree with the form's redirect, the two hashed paths (`returnUrl`, the
page Stripe sends people back to, and `paymentUrl`, the payment page), the includes and the links between
pages. Searching the pages for the old path is the easiest way to catch them all.

| Template | Paths inside it |
| --- | --- |
| `donate/index.twig` | The include of `'donate/_form'` |
| `donate/_form.twig` | `redirectInput('donate/payment?number={number}')`, `'donate/complete'\|hash`, `'donate/payment'\|hash` |
| `donate/payment.twig`, `donate/complete.twig` | Links back to `/donate` |
| `donate/manage.twig` | `redirectInput('donate/manage?sent=1')`, `siteUrl('donate/manage')\|hash` |
| `account/donations.twig` | `siteUrl('account/donations')\|hash` |
| `shop/index.twig`, `shop/product.twig` | The include of `'shop/_add-form'`, and in `shop/product.twig` a link back to `/shop`. The product type's **Template** setting points at `shop/product`. |
| `shop/_add-form.twig` | `redirectInput('cart')`, or `'checkout'` when `buyNow` is set |
| `cart.twig` | `redirectInput('cart')`, links to `/shop` and `/checkout` |
| `checkout/index.twig` | `redirectInput('checkout/payment?number={number}')`, `'checkout/complete'\|hash`, `'checkout/payment'\|hash`, links to `/shop` and `/cart` |
| `checkout/payment.twig`, `checkout/complete.twig` | Links back to `/cart` |

## Form reference

If you're building your own markup, these are all the actions a front-end page uses.

Every form is `method="post"` and includes `{{ csrfInput() }}` and `{{ actionInput('small-change/…') }}`. A
`redirectInput()` says where to go on success. Any value that picks a page for Stripe or the plugin to send
someone to (`returnUrl`, `paymentUrl`) is hashed with Craft's `|hash` filter so it can't be tampered with.
Paths are relative to your site (`'donate/complete'|hash`).

On success the visitor is redirected and a message goes in the `notice` flash. On failure the page is shown
again, with the message in the `error` flash and the object that failed in a variable, so you can print errors
next to fields. Send the request with an `Accept: application/json` header (a plain `fetch` doesn't add one)
and you get JSON instead.

### Start a donation: `small-change/donations/start`

| Field | |
| --- | --- |
| `productId` | The donation product. |
| `suggestedAmount` or `amount` | The chosen amount as a decimal (`25`, `12.50`). A typed `amount` wins over a ticked suggestion. |
| `email` | For the receipt. |
| `interval` | Optional: `month` or `year` for a [recurring donation](recurring-donations.md). Empty for one-off. |
| `giftAid`, `billingAddress[…]` | Optional: see [Gift Aid](gift-aid.md). |
| `fields[handle]` | Optional: the answers to your [donation fields](donations.md#asking-donors-for-more). Required ones are enforced. Checkout fields posted here are ignored. |
| `returnUrl` | Hashed: the thank-you page. |
| `paymentUrl` | Hashed: the payment page. |
| redirect input | Where to go next. `{number}` is replaced with the new order's number: `donate/payment?number={number}`. |

On failure the template gets `order`. See [Donations](donations.md) for the amount rules.

### The basket: `small-change/cart/…`

Each of these has a `redirectInput()` (the example pages send you back to `cart`) and reports in the flash.

| Action | Fields |
| --- | --- |
| `add` | `productId`, and `qty` (default 1). For a donation put in the basket, send `amount` or `suggestedAmount` instead of `qty`. |
| `update` | `quantities[productId]` for each line; zero removes it. |
| `remove` | `productId` |
| `apply-discount` | `discountCode` |
| `remove-discount` | none |

A donation can sit in the same basket as goods, and Gift Aid then applies only to the donation lines. To offer
one beside a product, post `productId` (the donation product) and `amount` to `cart/add`.

### Check out: `small-change/checkout/start`

This posts the visitor's basket for payment. Nothing about prices comes from the form.

| Field | |
| --- | --- |
| `email` | For the receipt. |
| `shippingAddress[…]` | Delivery address, when the basket has something to post. See `_small-change/address-fields.twig`. |
| `shippingMethod` | The chosen method's id. Offer only `craft.smallChange.shippingMethods(cart)`. |
| `fields[handle]` | Your [checkout fields](shop.md#checkout-fields). See `_small-change/checkout-fields.twig`. |
| `customerNote` | Optional note. |
| `giftAid`, `billingAddress[…]` | Optional, when the basket has a donation. |
| `returnUrl`, `paymentUrl` | Hashed, as above. |
| redirect input | `checkout/payment?number={number}` |

If something is missing, the page is shown again with the problems marked and everything already saved, so a
delivery address entered on the first go is remembered. On failure the template gets `cart`. The example page
offers no delivery methods until an address is saved, because which ones apply depends on the country.

### Manage a recurring donation: `small-change/portal/…`

- `portal/request-link` (POST): `email`, a hashed `returnUrl` (a full address, `siteUrl('donate/manage')|hash`,
  where Stripe sends them when they leave the portal) and a redirect input. The reply is always the same,
  whether or not the address matches, so the form can't reveal who donates. See
  [Recurring donations](recurring-donations.md).
- `portal/open` (a link, for signed-in users who made a recurring donation while signed in):
  `actionUrl('small-change/portal/open', { returnUrl: siteUrl('account/donations')|hash })`.

### Fetching the payment secret: `small-change/checkout/client-secret`

Use this if your page loads the payment form with JavaScript instead of printing the secret into the HTML. POST
`number` with `Accept: application/json`, and the reply is `{ "clientSecret": "…" }`. The example pages don't
need it: they print the secret with `craft.smallChange.checkoutClientSecret(order)`.

## Scripts

Each page adds the front-end script with one line:

```twig
{% do craft.smallChange.registerScripts() %}        {# pages with a Gift Aid tick box #}
{% do craft.smallChange.registerScripts(true) %}    {# payment pages: Stripe.js first, then ours #}
```

Both go in through your layout's `endBody()`. Stripe.js always loads from Stripe's own address
(`js.stripe.com`), because Stripe doesn't allow a self-hosted copy. The script does two jobs: it hides the Gift
Aid address fields until the box is ticked, and it mounts Stripe's payment form. If you write your own
JavaScript, these are the hooks it looks for:

| Element | Used for |
| --- | --- |
| `[data-gift-aid-toggle]`, `[data-gift-aid-fields]` | The tick box and the fields it reveals. |
| `#payment-form` with `data-publishable-key` and `data-client-secret` | The payment form. |
| `#payment-element`, `#pay-button`, `#payment-message` | Where Stripe's form mounts, the submit button and the error line. |

## Building from scratch

You don't need the installer at all. This is the smallest working donation form:

```twig
{# templates/give/index.twig #}
{% set product = craft.smallChange.products({ kind: 'donation' }).one() %}
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('small-change/donations/start') }}
    {{ redirectInput('give/pay?number={number}') }}
    {{ hiddenInput('returnUrl', 'give/thanks'|hash) }}
    {{ hiddenInput('paymentUrl', 'give/pay'|hash) }}
    {{ hiddenInput('productId', product.id) }}
    <label>Amount <input type="text" name="amount" inputmode="decimal"></label>
    <label>Email <input type="email" name="email" required></label>
    <button type="submit">Donate</button>
</form>
```

```twig
{# templates/give/pay.twig #}
{% set order = craft.smallChange.recentOrder(craft.app.request.getQueryParam('number')) %}
{% do craft.smallChange.registerScripts(true) %}
{% if order and order.state == 'pending' %}
    <form id="payment-form"
          data-publishable-key="{{ craft.smallChange.publishableKey }}"
          data-client-secret="{{ craft.smallChange.checkoutClientSecret(order) }}">
        <div id="payment-element"></div>
        <button type="submit" id="pay-button" disabled>Donate</button>
        <p id="payment-message" role="alert" hidden></p>
    </form>
{% endif %}
```

We've left out the `{% extends %}` and `{% block %}` lines. Both pages still need a layout that outputs
`endBody()`, or the payment form never loads. `give/thanks.twig` then shows `recentOrder(number)` once
`order.state == 'completed'`. The installed `donate/payment.twig` is the full version: it also handles the
Stripe-hosted page, an order that's already paid and one that can't be found.
See the [Twig reference](twig.md) for everything on `craft.smallChange`.
