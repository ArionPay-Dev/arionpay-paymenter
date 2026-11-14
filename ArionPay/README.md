# ArionPay for Paymenter

Lets your clients pay Paymenter invoices with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on a real Paymenter 1.5.9 (PHP 8.3, MariaDB): payment, reuse of an open payment, late payment, payment tolerance, edited invoice, double payment and webhook checks.

## What you need

- Paymenter 1.x
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- Paymenter on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

1. Unzip `arionpay-paymenter.zip` into `extensions/Gateways/` of your Paymenter folder, so you get `extensions/Gateways/ArionPay/`.
2. In the admin area, go to **Gateways**, press **New**, name it `ArionPay`, choose the type **ArionPay**, fill in the keys (see below), tick **Test mode**, and press **Create**.
3. Open the ArionPay gateway again and press **Save** once more. Paymenter encrypts the secret fields only when a gateway is saved from its edit page.

## Where the keys come from

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

Leave **ArionPay address** empty. You do not need to paste anything into ArionPay: every invoice tells ArionPay to send its payment updates to `https://your-paymenter/extensions/gateways/arionpay/webhook`. You can also paste that address on the ArionPay **Webhooks** page to use **Send test webhook** there.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. Keep **Test mode** ticked. It makes ArionPay test invoices: no real money moves.
2. As a client, open an unpaid invoice, press **Pay**, choose **ArionPay** and **Pay Now**.
3. On the ArionPay page, choose a coin and use the test payment buttons.
4. The invoice should be **Paid**, with a transaction `arionpay:<invoice id>`.
5. Untick **Test mode** before you take real payments.

## How payment works

- The client pays the amount still open on the invoice. Pressing **Pay** again while that ArionPay invoice is open reuses it; after it expires, a new one is made.
- When ArionPay sees the payment on the network, the invoice shows a **processing** transaction. When ArionPay confirms the full payment, that same transaction becomes **succeeded** and Paymenter marks the invoice paid.
- The invoice is paid only if the ArionPay invoice still matches it: same currency and the same amount still open. If the invoice was edited after the client went to pay, no payment is added; a **failed** transaction and a warning in Paymenter's log explain why. Add the payment by hand after checking.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the invoice is paid here too.
- A late payment (after the ArionPay invoice expired) still pays the invoice.
- If a paid invoice is paid again, the second payment is not added; a failed transaction and a log warning ask you to check your wallet and refund the client.
- Each payment is one transaction, so a repeated update from ArionPay never adds money twice.
- Coming back from the payment page never marks an invoice paid.

## Good to know

- The invoice currency must be one ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR.
- Automatic renewals (billing agreements) are not supported: crypto payments are one-off. Renewal invoices are paid like any other invoice.
- Refunds are made from your own wallet. The gateway does not send refunds.
- The gateway sends ArionPay the invoice number, amount and currency. It does not send your client's name or email.
- Paymenter's own gateway edit page shows saved keys in its form; keep admin access to trusted staff.
