# Emails

Small Change sends its own emails from Craft's mailer, through the queue, so a slow mail server never holds up a
payment. Make sure your queue runner is working (`php craft queue/listen`, or a cron job running
`php craft queue/run`).

## What is sent

The same list is on **Small Change → Settings → Emails**, with a switch beside each email. The name in brackets
is the email's template name, which the tab shows under each label. You'll need it if you
[change the look](#changing-the-look).

| Email | To | When |
| --- | --- | --- |
| Receipt (`receipt`) | Donor | Each successful payment, once, including each payment of a recurring donation. Includes the Gift Aid wording if declared. |
| Payment failed (`payment-failed`) | Donor | A payment method that clears slowly, such as a bank debit, failed after checkout. |
| Recurring payment failed (`subscription-payment-failed`) | Donor | Stripe couldn't take a payment of a recurring donation. Links to Stripe's invoice page. |
| Recurring payment needs confirming (`subscription-action-required`) | Donor | The donor's bank wants them to confirm a recurring payment. Links to Stripe's invoice page. |
| Recurring donation ended (`subscription-cancelled`) | Donor | A recurring donation was cancelled, by the donor, by you or by Stripe. |
| Manage link (`portal-link`) | Donor | The donor asked for a link to manage a recurring donation. |
| New order or donation (`admin-new-order`) | `adminEmail` | An order is paid or a donation comes in. Later payments of a recurring donation don't send it. |
| Refund failed (`admin-refund-failed`) | `adminEmail` | Stripe couldn't complete a refund. |
| Dispute opened (`admin-dispute`) | `adminEmail` | A donor disputed a payment with their bank. |
| Recurring donation ended (`admin-subscription-cancelled`) | `adminEmail` | The same cancellation, for you. |

A receipt belongs to one particular payment. A recurring donor gets one receipt for each payment, and a repeated
job or delivery never sends the same receipt twice. **Resend receipt** on an order sends a deliberate extra copy.

## Turning emails off

On the Emails tab, **Send emails** is the master switch. With it off, nothing is sent and nothing is queued. That
includes the manage link, so a donor who asks for one is told it can't be emailed and to get in touch with you
instead. **Resend receipt** is hidden on orders while receipts are off.

Each email also has its own switch there, stored as `disabledEmails`. That list uses the email keys, which aren't
the template names: `receipt`, `paymentFailed`, `subscriptionPaymentFailed`, `subscriptionActionRequired`,
`subscriptionCancelled`, `portalLink`, `adminNewOrder`, `adminRefundFailed`, `adminDispute` and
`adminSubscriptionCancelled`. The staff emails need an admin address; leave it empty and they aren't sent.

An email that's off when something happens is skipped for good. Switching it back on doesn't send the ones you missed.

Donors shouldn't get two receipts. If you'd rather use Stripe's, switch off `receipt` here. Otherwise turn off
Stripe's successful-payment emails in the Dashboard (Settings → Business → **Customer emails**).

## Changing the wording

Click an email in the table on the Emails tab (or its **Edit** link) to change its subject, heading and opening
message. The preview underneath updates as you type. It uses the latest order that suits the email, or made-up
details if there isn't one, and nothing is sent. **Preview** in the table opens the saved version in a new tab.
You can do this in production too, even where admin changes aren't allowed. The screen is read-only, and the link
says **View**, only when `emailContent` is set in `config/small-change.php`.

Leave a field blank to keep the built-in wording. The receipt's subject field starts out with the `receiptSubject`
setting, and a subject you save here wins. The message replaces the opening paragraphs only: the facts a donor
needs, such as the order summary, the Gift Aid declaration and any links, are always included. Write it as plain
text with a blank line between paragraphs. These placeholders work in all three fields:

| Placeholder | Becomes |
| --- | --- |
| `{charityName}` | The charity name from the General tab, or the site name. |
| `{siteName}` | The name of the site the order was placed on. |
| `{orderNumber}` | The order's reference. |
| `{firstName}` | The donor's first name, if they gave a home address for Gift Aid. Otherwise blank. |
| `{amount}` | The payment or refund amount, formatted, such as £25.00. |

The manage link isn't tied to an order, so only `{charityName}` and `{siteName}` have anything in them there.

The text is stored in the database (`emailContent`), not project config, so each environment keeps its own wording
and a deployment doesn't overwrite what was changed in production. The sender fields and admin address are kept the
same way.

## Changing the look

Settings cover the envelope: sender name and address, reply-to and the admin address. Leave the sender fields
blank to use Craft's own email settings.

To change more than the wording, put your own template in `templates/_small-change/emails/` (the path is the
`emailTemplatePath` setting). That folder doesn't exist until you create it; installing the front-end templates
doesn't make it. Name the file after the email's template name from the table above, such as `receipt.twig` or
`admin-new-order.twig`. If a template with that name exists there, it wins over the shipped one, and anything
you haven't overridden falls back to the shipped version. Your template can extend the shipped layout and reuse
its order summary:

```twig
{% extends 'small-change/emails/_layout' %}

{% block body %}
    <h1>Thank you, {{ billingAddress.firstName ?? 'friend' }}!</h1>
    {% include 'small-change/emails/_summary' %}
    <p>Reference {{ order.number }}. Registered charity 1234567.</p>
{% endblock %}
```

The wording from the edit screen only appears in your template if it prints `content.heading` and
`content.message`. The edit screen warns you when an override is in use.

Every email also goes out as plain text, made from the HTML, with links kept as `Label: https://…`.

### Variables

| Variable | Contents |
| --- | --- |
| `order` | The order element. |
| `payment` | The payment this email is about (`amount`, `paidAt`, `giftAidAmount`…), or null. |
| `lineItems` | The order's lines. |
| `billingAddress` | The Gift Aid home address, if one was given. |
| `giftAid` | `declared`, `amount` and `text` (the wording in your settings when the email is sent, which on a resend may be newer than the one the donor saw). |
| `siteName`, `settings` | The site name and the plugin's settings, without the Stripe secrets. |
| `content` | The wording from the control panel: `subject`, `heading` and `message` (already escaped and split into paragraphs). Each is empty when the built-in wording applies, so print `{{ content.heading ?: 'Your own default' }}` to honour both. |
| `context` | Extra facts for some emails: `amount`, `interval` and `invoiceUrl` on the recurring payment emails, `amount` and `interval` on the recurring donation ended emails, `amount` and `reason` on the refund-failed and dispute emails. |
| `url` | On `portal-link`: the signed link. That email has only `url`, `siteName`, `settings` and `content`. |

## Changing an email in code

`Emails::EVENT_BEFORE_SEND` fires with the message before it goes. You can add a BCC, change a header, or set
`isValid = false` to stop it. See [Events](events.md).
