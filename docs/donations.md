# Donations

A donation is a product of a *Donation* product type. The donor chooses the amount, so the plugin checks it: it
has to be at least the product's minimum, no more than 999,999.99 and, unless you allow free amounts, one of the
suggested amounts. A product needs suggested amounts, a free amount or both.

## The donation form

The installed `donate/index.twig` includes the form from `donate/_form.twig`, which posts to
`small-change/donations/start`. The fields it sends (the amount, email, interval, Gift Aid answer, and the hashed
`returnUrl` and `paymentUrl`) are listed in the
[form reference](templates.md#start-a-donation-small-changedonationsstart), which also shows how to build your own
form. `suggestedAmount` or `amount` is the chosen amount as a decimal (`25`, `12.50`), and a typed
`amount` wins over a ticked suggestion.

People type amounts in all sorts of ways, so Small Change refuses `10,50` instead of guessing (it could mean £10.50 or
£1,050). Use `10.50`. Commas are accepted only as thousands separators (`1,000`).

If a submission fails, the order comes back to the template with its errors, so you can show them next to the
fields. A submission that fails validation saves nothing.

To put the donation form on any page, in a block an editor can add, see
[Showing one product or a donation form on a page](blocks.md).

## Asking donors for more

There are two places to add fields, and they do different jobs:

- In **Small Change → Donation fields** you design what donors fill in (a dedication, "how did you hear about
  us") with Craft's field layout designer. The installed donation page prints them with
  `craft.smallChange.donationFields`, a required field must be answered before the donor can continue, and the
  answers show under **Donation details** on the order. Donors can fill in the common simple field types (Plain
  Text, Number, Range, Dropdown, Radio Buttons, Checkboxes, Multi-select, Button Group, Lightswitch, Date, Time,
  Email and Country), and the settings page warns you about any other type. These fields are asked on donation
  forms only. The shop basket has its own [checkout fields](shop.md#checkout-fields).
- In your donation type under **Small Change → Product types** you add fields for the people who edit the
  donation. They're filled in on the product in the control panel, like a description or an image, and you print
  them in your template with `{{ product.myField }}`. Donors never see them as inputs.

To render the donation fields in your own form, loop over them and name each input `fields[handle]`, as
`_small-change/checkout-fields.twig` does:

```twig
{% include '_small-change/checkout-fields' with { order: order, fields: craft.smallChange.donationFields } only %}
```

## The payment page

Both checkout modes share one flow:

- `elements` (default): the payment page loads Stripe.js and mounts Stripe's payment form inside your own
  page. Card details go straight from the browser to Stripe.
- `hosted_page`: the form sends the donor to Stripe's own page (the form's redirect is ignored) and they
  come back to your `returnUrl` afterwards. Your payment page is only where they land if they back out.

Pick the mode with **Payment form** in **Small Change → Settings → Checkout**. You get the same orders, emails and
events either way.

## After payment

Stripe sends the donor back through a signed link that's valid for two hours. Small Change checks it, lets that
browser see the order, and forwards the donor to your `returnUrl` page with the order number. Small Change never shows an
order to someone who only has its number, so the page works only for the browser session that placed the order,
or one that came back through that signed link. Use `craft.smallChange.recentOrder(number)` to fetch it safely.

Stripe's webhook completes the order, and so does the return trip if it gets there first. Whichever arrives
first does the work, once, and the other finds it already done.

## Refunds and disputes

Open the order and use **Refund…**. Refunds can be partial, they're checked against what is left to refund, and
a double click can't repeat one. Refunds made in the Stripe Dashboard are picked up too. If a donor disputes a
payment with their bank, the order is marked disputed and your admin address gets an email, if you've set one in
**Small Change → Settings → Emails**.
