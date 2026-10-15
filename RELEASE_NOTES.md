# PayzCore WHMCS module v1.0.2

## Fixed
- **Pay again after expiry:** when a payment expired or was cancelled, the invoice page created a "new" payment with the same `external_order_id` (`WHMCS-{invoiceId}`). PayzCore returns the existing payment for a repeated order id, so the client got the expired payment back and could not pay. A new attempt now uses `WHMCS-{invoiceId}-r{8 hex}` (derived from the previous payment id, so reloading the page does not create duplicates). The callback accepts both formats, and still falls back to `metadata.invoiceId`.
- `composer.json` version aligned with the module header.

## Included from 1.0.1 (never uploaded to the WHMCS Marketplace)
- QR code visible on light backgrounds (standard black-on-white QR, white container with padding).

**Marketplace:** the WHMCS Marketplace listing (product/8483) still shows v1.0.0; upload this ZIP there.

Docs: https://docs.payzcore.com/integrations/whmcs
