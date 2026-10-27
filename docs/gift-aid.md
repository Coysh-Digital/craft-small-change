# Gift Aid

Gift Aid lets a UK charity claim an extra 25p for every £1 given by a UK taxpayer. In Small Change it's an option:
it asks the donor, passes the answer on and keeps almost nothing. We left record keeping out on purpose.

## What it does

Switch on **Offer Gift Aid on donations** in **Small Change → Settings → Gift Aid**. The wording names your charity
using **Charity or organisation name** on the General tab, which takes the name itself or an environment variable
such as `$CHARITY_NAME`. Leave it blank and the site name is used.

After that, any donation form that includes the installed `_small-change/gift-aid.twig` partial (the donation and
checkout pages already do) behaves like this:

- The donor sees a declaration for the donations you choose under **Declaration covers**:
  - **This donation only**, using HMRC's single-donation wording.
  - **This donation and future ones**, for charities that don't claim on past gifts.
  - **This donation, future ones and those in the past 4 years (HMRC model)**, HMRC's model multiple-donation
    wording. This is the default.
  - **Custom wording**, written by you. Keeping it valid is then your job.

  The tab shows the wording donors will see for the option you pick. On a recurring donation the declaration
  covers each of its payments, whichever you choose, and each payment counts as eligible in full.
- Ticking the box requires their full name and home address (first name, last name, house and street, postcode).
  The server checks them.
- Only the donation part of an order is ever eligible. Payments for goods never are, and the box isn't offered on
  an order with no donation in it.

The answer ends up in three places:

1. On the order, as two fields only: `giftAidDeclared` (true, false, or null when it wasn't offered) and
   `giftAidEligibleAmount`. Each payment row copies the yes/no and the eligible amount for that payment.
2. In Stripe, as PaymentIntent (and subscription) metadata: `smallChangeGiftAid` (`yes` or `no`) and
   `smallChangeGiftAidAmount`. That makes it visible on every payment in your Dashboard.
3. In your own code. The order event (`afterCompleteOrder`) carries the declared flag, the eligible amount, the
   exact wording shown, and the donor's name and home address. Payment events carry the flag and the amount for
   that payment. Use them to push the declaration into your CRM. See [Events](events.md).

The receipt repeats the donor's answer and the wording.

## What it does not do

Please don't treat Small Change as your Gift Aid records.

- It doesn't store declarations, produce a Gift Aid schedule, export anything, or make claims to HMRC.
- It doesn't keep the records HMRC expects a charity to hold (for example the date and wording of each
  declaration, and an audit trail). Those belong in your CRM, fed from the events above.
- The home address the donor typed is held on the order (and sent to the Stripe Customer) like any other order
  detail. It's removed along with the rest of their personal data if you erase them (see [Privacy](privacy.md)).
