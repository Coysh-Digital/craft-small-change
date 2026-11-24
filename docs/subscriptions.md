# Subscription products

A product with no delivery can be sold as a subscription: a fixed price that Stripe takes every month or every
year. Use it for a membership, a course fee paid in instalments, or anything else that is bought once and paid for
over time. It can stop by itself after a set number of payments. It is different from a
[recurring donation](recurring-donations.md), where the donor chooses the amount and Gift Aid can apply.

## Setting one up

1. Use a **non-physical** product type. A product type that delivers goods can't be a subscription.
2. In **Small Change → Product types**, open the type and add the **Subscription** field to its layout, from the
   native fields in the layout designer. Existing types don't get it until you add it.
3. On the product, set a **Price** (the amount of each payment) and choose **Billing**: *Every month* or *Every
   year*. *One-off payment* sells it once, as before.
4. Set **Number of payments** to end the subscription by itself after that many payments. Leave it blank to keep
   going until it's cancelled.

Add it to a page the way you would any other product: the [product block](blocks.md), or the shop's add-to-basket
form. The example forms hide the quantity box for a subscription, and `product.subscriptionTerms` prints the plan
in words, such as "a month, for 10 payments".

## What the customer sees

- A subscription has to be bought **on its own**. Adding it to a basket that has other things in it, or adding
  something else to a basket that has one, is refused with a message.
- The first payment is taken at checkout, and the same amount is taken on that day of the month (or year) after.
- There is no Gift Aid, and no delivery.
- Each payment has its own receipt, as for a recurring donation.

## Discount codes

A discount code on a subscription takes its share off **every** payment, not only the first. A 50% code on a £50 a
month plan makes each payment £25. A fixed-amount code takes that amount off each payment. Use a code's product
limit to keep it to the right plan, and its maximum uses to cap how many plans can take it: one plan is one use.

## How a plan ends

When a plan with a set number of payments takes its last payment, Small Change tells Stripe to stop at the end of
the period just paid for, and emails the customer the *Payment plan complete* message. Nothing more is taken and
it is not reported as cancelled. If the last payment is refunded it still counts as taken.

A plan with no set number runs until the customer, or you, cancel it from **Small Change → Recurring**.
Cancelling earlier sends the usual "ended" emails.

If Stripe can't be told to stop, the webhook is retried by Stripe until it can. Look at **Utilities → Stripe**
if a plan runs past its number of payments.

## Good to know

- The terms are copied to the basket when the item is added. If you change a product's price, billing or number of
  payments afterwards, a customer with it in their basket is told the terms changed before they pay. Plans already
  started aren't changed.
- A product's price is the amount of one payment, so a minimum spend on a discount code is checked against one
  payment.
- Stripe groups the payments under a Stripe Product named after your product, like recurring donations.
