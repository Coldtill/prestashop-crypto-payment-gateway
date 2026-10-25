# PrestaShop crypto payment gateway: Coldtill for PrestaShop

**Accept USDT, USDC, Bitcoin, Ethereum, Solana, Tron and 200+ coins in PrestaShop, straight to your own wallets.**
No sign-up, no KYB, no custody and no processor that can freeze your money.

This module connects your PrestaShop store to [Coldtill](https://coldtill.com), a
[self-hosted crypto payment gateway](https://github.com/Coldtill/self-hosted-crypto-payment-gateway) that runs on
your own server.

[Download the module](https://github.com/Coldtill/prestashop-crypto-payment-gateway/releases/latest/download/coldtill-prestashop.zip) ·
[Coldtill](https://coldtill.com) · [Docs](https://coldtill.com/docs/) · [Live demo dashboard](https://demo.coldtill.com/)

![Coldtill checkout: choose a network and coin, then scan the QR code](https://coldtill.com/img/checkout-pay.webp)

## Features

- **200+ coins on 35+ chains.** BTC, ETH, SOL, TRX, BNB, POL, USDT and USDC directly, and XRP, TON, Sui, Cardano,
  Dogecoin and more through auto-convert, settled to you as USDC or USDT.
- **Self-custody.** On Ethereum, Base, Polygon, BNB Chain, Tron and Solana each customer pays into a deposit contract
  that can only send to your cold wallet. Bitcoin goes to your own wallet's addresses.
- **No account to freeze.** No sign-up, no KYC or KYB, no reserves and no payout schedule.
- **Built for PrestaShop 8 and 9**, with the standard checkout payment step and its own order statuses.
- **Signed webhooks** (HMAC-SHA256 with a timestamp and replay window). Orders update automatically and are re-checked
  when the customer returns to your store if a webhook is missed.
- **Safe by default.** The order only changes when the reference and amount match; overpayments and partial payments
  are noted on the order.

## Requirements

- PrestaShop 8.0+ or 9.x, PHP 8.1+ with the cURL extension
- Store currency: USD
- A running Coldtill server. The Free edition installs with one command:

```bash
curl -fsSL https://coldtill.com/install.sh | sudo bash -s -- --edition free
```

## Install

1. Download [`coldtill-prestashop.zip`](https://github.com/Coldtill/prestashop-crypto-payment-gateway/releases/latest/download/coldtill-prestashop.zip).
2. In PrestaShop, go to **Modules → Module Manager → Upload a module**, choose the zip and install it.
3. In Coldtill, create a project API key (**API keys**) and copy it.
4. In the module's **Configure** page, enter your Coldtill URL and API key.
5. In Coldtill → **Webhooks**, set the URL shown under "Webhook secret" (it ends in `/module/coldtill/webhook`, or
   `fc=module&module=coldtill&controller=webhook` without friendly URLs) and paste the signing secret into the module
   settings.

## Order statuses

| Coldtill payment | PrestaShop order |
|---|---|
| Created | Awaiting crypto payment |
| Filled or overpaid | Payment accepted |
| Partially paid | Partial crypto payment |
| Cancelled or replaced | Canceled |

A payment that is cancelled or replaced after part of it was paid leaves the order in Partial crypto payment, with a
private message to settle it by hand. Uninstalling the module keeps its order statuses and payment references, so
existing orders keep their history and a reinstall carries on where it left off.

## Security

Report vulnerabilities to [security@coldtill.com](mailto:security@coldtill.com). See
[coldtill.com/security](https://coldtill.com/security).

## Licence

MIT. See [LICENSE](LICENSE).
