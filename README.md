# FOSSBilling Yoco Gateway

Yoco payment gateway integration for FOSSBilling.

This extension lets FOSSBilling accept one-time Yoco Checkout payments in South African Rand (ZAR). It also supports optional USD invoice conversion into ZAR before checkout.

## Features

- One-time Yoco Checkout payments
- ZAR invoice support
- Optional USD-to-ZAR invoice conversion
- Yoco webhook verification for payment confirmation
- Test and live API key fields

## Contents

```text
library/Payment/Adapter/Yoco.php
data/assets/gateways/yoco.png
```

## Installation

1. Download the latest release ZIP.
2. Extract the archive into your FOSSBilling root so the paths merge into:
   - `library/Payment/Adapter/Yoco.php`
   - `data/assets/gateways/yoco.png`
3. Clear the FOSSBilling cache.
4. In the FOSSBilling admin panel, go to **System > Payment gateways**.
5. Install or enable the **Yoco** payment gateway.
6. Add your Yoco live or test keys.
7. Configure your Yoco webhook and add the webhook secret in the gateway settings.

## Currency notes

Yoco checkout is processed in ZAR. ZAR invoices are charged directly. USD invoices can be converted to ZAR using the configured gateway conversion rate or the FOSSBilling currency rates.

## Webhook notes

The gateway verifies Yoco webhook signatures when a webhook secret is configured. Payment confirmation is handled through the `payment.succeeded` event.

## License

Apache License 2.0. See [LICENSE](LICENSE).

## Disclaimer

This extension is not affiliated with FOSSBilling or Yoco.
