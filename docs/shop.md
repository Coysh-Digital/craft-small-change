# Selling products

Small Change sells a handful of products to a basket and takes one payment through Stripe. It isn't a catalogue
engine: there are no variants, stock levels, tax or multiple currencies. We left them out on purpose, and if you
need them, Craft Commerce is the right tool.

## Products

Under **Small Change → Product types**, create a type of the *Physical product (delivered)* kind, which asks for
a delivery address, or the *Non-physical product (no delivery)* kind for things like tickets and memberships,
which never does. A product has a title, a price, a SKU and whatever custom fields you add to the type's layout.
SKUs are unique across all products, and optional unless you turn on **SKU required** for the type. Product types
and their layouts live in project config.

A type can have its own URLs and templates like any Craft section, or you can list products with
`craft.smallChange.products()` on a page of your own. Product pages are optional: leave the
**Product URI format** blank for the type and let editors choose a product with a **Small Change Products**
field. See [Showing one product or a donation form on a page](blocks.md).

## The basket

The basket is an ordinary order in the *cart* state, held in the visitor's session. Nobody else can reach it:
there is no basket URL to guess. The actions are `small-change/cart/add`, `update`, `remove`, `apply-discount`
and `remove-discount`; the [form reference](templates.md#the-basket-small-changecart) lists what each posts.

You refer to a line by its product. Line numbers don't work, because line rows are rewritten whenever the basket
is saved.

Every change recalculates prices, delivery, discount and total on the server, and checkout does it again just
before payment. If a price changed while someone was checking out, they see the new total and are asked to
confirm, so nobody is charged something they didn't agree to.

A donation can sit in the same basket as goods. Gift Aid then applies only to the donation lines.

## Delivery

In **Small Change → Shipping**, each delivery method has a name, a flat price and, if you want, the countries it
serves. A customer is offered the methods that serve their delivery country, in your order, and if only one does
it's chosen for them. Before an address is known, only methods that serve every country are offered. Delivery is
a line in the Stripe session, so Stripe's total matches the one on your site.

## Discount codes

In **Small Change → Discount codes** you can set a percentage or a fixed amount, optionally a minimum spend,
start and end dates and a maximum number of uses. Codes discount products only; donations are never discounted.
When a code can't be used, the answer is deliberately vague, so nobody can probe for valid ones.

A code with a usage limit is *reserved* while someone is paying and released if they abandon the payment, so
two customers can't both get the last use. Stripe is shown the discount as a one-use coupon that is deleted
afterwards.

## Checkout fields

In **Small Change → Checkout fields** you design extra fields for every basket order (a gift message,
dietary needs) with Craft's field layout designer. Customers can fill in the common simple field types. Anything
the public form can't safely render is ignored there, and the settings page warns you about it. Values show on
the order in the control panel. Donation forms have their own set under **Donation fields**, so a question meant
for donors never shows up in the basket.

## Orders

**Small Change → Orders** is a normal Craft element index, with sources for all orders, **Needs dispatch**,
donations, Gift Aid declared and test orders. Open an order for its items, delivery details, payments and
refunds. Dispatch is manual: **Mark as dispatched** records the date. There's also a **Note for staff**,
**Resend receipt** (while the receipt email is switched on) and **Cancel order** for one still awaiting payment.

Order states are *cart* (a basket), *pending* (payment started), *completed* and *cancelled*. Payment status is
shown separately: unpaid, processing, paid, partially refunded, refunded, failed or disputed.

## Templates

Install the shop pages (product list, product page, basket, checkout, payment and thank-you) from **Small Change →
Settings → Templates**. They need no routes. See [Your front-end templates](templates.md), including how to give products
their own pages.
