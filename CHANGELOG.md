# Changelog

## 1.0.2 — 2026-10-15
- Fixed: after a payment expired or was cancelled, "pay again" got the same expired payment back (PayzCore treats a repeated `external_order_id` as the same order). A new attempt now uses `WHMCS-{invoiceId}-r{8 hex}`; the callback accepts both formats.
- `composer.json` version aligned with the module header.

## 1.0.1 — 2026-03-07
- Fixed QR code visibility on light backgrounds (standard black-on-white QR)

## 1.0.0 — 2026-02-15
- Initial release
- Multi-network support: TRC20, BEP20, ERC20, Polygon, Arbitrum
- Multi-token support: USDT + USDC
- Webhook signature verification (HMAC-SHA256)
- Static wallet support (dedicated + pool modes)
- Payment status polling with countdown timer
