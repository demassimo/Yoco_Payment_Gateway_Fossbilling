FOSSBilling Yoco Gateway

Contents:
- library/Payment/Adapter/Yoco.php
- data/assets/gateways/yoco.png

Install:
1. Extract the archive into your FOSSBilling root so the paths merge into:
   - library/Payment/Adapter/Yoco.php
   - data/assets/gateways/yoco.png
2. Clear FOSSBilling cache.
3. Install or enable the Yoco payment gateway in FOSSBilling admin.

Notes:
- Supports one-time Yoco checkout payments.
- Supports ZAR invoices and USD invoices converted into ZAR for checkout.
- Uses Yoco webhook verification for payment confirmation.
