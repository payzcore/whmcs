# PayzCore WHMCS Module

Blockchain transaction monitoring integration for WHMCS. Accept stablecoin payments (USDT/USDC) on multiple networks (TRC20, BEP20, ERC20, Polygon, Arbitrum) through your WHMCS billing system.

PayzCore is a **non-custodial** monitoring API -- it watches blockchain addresses for incoming transfers and notifies your WHMCS installation via webhooks. It does not hold, transmit, or custody any funds.

## Important

**PayzCore is a blockchain monitoring service, not a payment processor.** All payments are sent directly to your own wallet addresses. PayzCore never holds, transfers, or has access to your funds.

- **Your wallets, your funds** — You provide your own wallet (HD xPub or static addresses). Customers pay directly to your addresses.
- **Read-only monitoring** — PayzCore watches the blockchain for incoming transactions and sends webhook notifications. That's it.
- **Protection Key security** — Sensitive operations like wallet management, address changes, and API key regeneration require a Protection Key that only you set. PayzCore cannot perform these actions without your authorization.
- **Your responsibility** — You are responsible for securing your own wallets and private keys. PayzCore provides monitoring and notification only.

## Requirements

- WHMCS 7.x or 8.x
- PHP 7.4 or higher
- cURL extension enabled
- A PayzCore account with an active project ([app.payzcore.com](https://app.payzcore.com))

## Installation

### 1. Copy Module Files

Copy the `modules/gateways/` contents into your WHMCS installation:

```
your-whmcs/
├── modules/
│   └── gateways/
│       ├── payzcore.php                    ← Copy this
│       ├── payzcore/
│       │   ├── PayzCoreApi.php             ← Copy this
│       │   └── hooks.php                   ← Copy this
│       └── callback/
│           ├── payzcore.php                ← Copy this
│           ├── payzcore_poll.php           ← Copy this
│           └── payzcore_confirm.php        ← Copy this
```

**Quick install via command line:**

```bash
# From the module package directory
cp -r modules/gateways/payzcore.php /path/to/whmcs/modules/gateways/
cp -r modules/gateways/payzcore/ /path/to/whmcs/modules/gateways/
cp modules/gateways/callback/payzcore.php /path/to/whmcs/modules/gateways/callback/
cp modules/gateways/callback/payzcore_poll.php /path/to/whmcs/modules/gateways/callback/
cp modules/gateways/callback/payzcore_confirm.php /path/to/whmcs/modules/gateways/callback/
```

### 2. (Optional) Install Hooks

To enable admin invoice blockchain explorer links and cleanup on invoice deletion, copy the hooks file to the WHMCS includes directory:

```bash
cp modules/gateways/payzcore/hooks.php /path/to/whmcs/includes/hooks/payzcore.php
```

### 3. Activate in WHMCS Admin

1. Log into WHMCS Admin
2. Navigate to **Setup** > **Payments** > **Payment Gateways**
3. Click the **All Payment Gateways** tab
4. Find **PayzCore (USDT/USDC)** and click **Activate**

### 4. Configure the Module

After activation, configure these settings:

| Setting | Description | Example |
|---------|-------------|---------|
| **API URL** | PayzCore API base URL | `https://api.payzcore.com` |
| **API Key** | Your project API key | `pk_live_abc123...` |
| **Webhook Secret** | Webhook signing secret | `whsec_xyz789...` |
| **Static Wallet Address** | Fixed wallet address for static wallet mode (optional) | *(empty)* |
| **Payment Expiry** | Minutes before monitoring expires | `60` |
| **Test Mode** | Enable verbose logging | Off |

Available networks and tokens are detected automatically from your PayzCore project wallet configuration — no manual network/token selection needed.

You can find your API Key and Webhook Secret in the PayzCore dashboard under **Projects** > your project > **Settings**.

### 5. Configure Webhook URL in PayzCore

In your PayzCore project settings, set the webhook URL to:

```
https://yourdomain.com/modules/gateways/callback/payzcore.php
```

Replace `yourdomain.com` with your actual WHMCS domain.

## How It Works

### Payment Flow

1. Customer views an unpaid invoice and selects PayzCore
2. The module creates a monitoring request via the PayzCore API
3. Customer sees a payment page with:
   - Deposit address (with copy-to-clipboard)
   - QR code for easy wallet scanning
   - Exact stablecoin amount to send
   - Countdown timer showing time remaining
   - Real-time status polling (every 15 seconds)
4. Customer sends the stablecoin from their wallet to the displayed address
5. PayzCore detects the incoming transfer on the blockchain
6. PayzCore sends a signed webhook to your WHMCS callback URL
7. The callback handler verifies the signature and credits the invoice

### Static Wallet Mode

If your PayzCore project uses a static (fixed) wallet address instead of HD-derived addresses, you can configure it in the module settings:

1. Enter your static wallet address in the **Static Wallet Address** field
2. The module will include this address in every payment creation request
3. When the API response includes `requires_txid`, customers will see a form to submit their transaction hash after sending payment
4. Any `notice` returned by the API (e.g., "Send exactly 50.003 USDT") is displayed to the customer on the payment page

This mode is useful when you want all payments to go to a single address rather than generating a new address for each payment.

### Webhook Events

| Event | Action |
|-------|--------|
| `payment.completed` | Invoice marked as paid, transaction recorded |
| `payment.overpaid` | Invoice marked as paid (overpayment logged) |
| `payment.partial` | Logged only -- invoice remains unpaid |
| `payment.expired` | Logged only -- customer can retry |
| `payment.cancelled` | Payment cancelled by the merchant |

### Security

- All webhook payloads are verified using **HMAC-SHA256** signature
- Timing-safe comparison prevents timing attacks
- Invoice ID validation ensures the invoice exists
- Transaction ID deduplication prevents double-crediting
- All user inputs are sanitized and escaped

## Currency Configuration

PayzCore monitors stablecoin transfers (USDT/USDC). Your WHMCS invoices **must be in USD** currency for the amounts to match correctly.

To configure USD in WHMCS:
1. Go to **Setup** > **Payments** > **Currencies**
2. Ensure USD is your default currency, or available as a currency option
3. Client invoices in other currencies will see an informational message

## Troubleshooting

### Check Gateway Logs

1. Go to **Utilities** > **Logs** > **Gateway Log**
2. Filter by "PayzCore" to see all API interactions and webhook deliveries

### Common Issues

**"Module Not Active" in webhook logs**
- Ensure the gateway is activated in Setup > Payments > Payment Gateways

**"Invalid Signature" in webhook logs**
- Verify the Webhook Secret in WHMCS matches the one in your PayzCore project
- Ensure no proxy or WAF is modifying the request body

**"Cannot determine invoice ID"**
- The webhook is from a payment not created by this WHMCS module
- This is normal if you use PayzCore for other integrations on the same project

**Payment page shows currency error**
- Your WHMCS invoice is not in USD. Switch client billing to USD

**Timeout or network errors**
- Check that your server can reach the PayzCore API URL
- Verify firewall rules allow outbound HTTPS to api.payzcore.com
- Increase the cURL timeout if needed (default: 30s)

### Enable Test Mode

Toggle **Test Mode** in the gateway settings to enable verbose logging. All API requests and responses will be logged to the WHMCS Gateway Log for debugging.

## File Structure

```
modules/gateways/
├── payzcore.php                  # Main gateway module
│                                 # - payzcore_MetaData()
│                                 # - payzcore_config()
│                                 # - payzcore_link() → payment page
├── payzcore/
│   ├── PayzCoreApi.php           # API client (cURL-based)
│   │                             # - createPayment()
│   │                             # - getPayment()
│   │                             # - verifyWebhookSignature()
│   └── hooks.php                 # Admin hooks (optional)
│                                 # - Explorer links on invoice
│                                 # - Cleanup on invoice delete
└── callback/
    ├── payzcore.php              # Webhook callback handler
    │                             # - Signature verification
    │                             # - Invoice payment application
    ├── payzcore_poll.php         # Payment status polling proxy
    │                             # - Server-side API key protection
    └── payzcore_confirm.php      # TX hash confirmation proxy
                                  # - Static wallet tx_hash submission
```

## API Reference

This module uses the following PayzCore API endpoints:

- **POST /v1/payments** -- Create a monitoring request
- **GET /v1/payments/:id** -- Check payment status (real-time)
- **POST /v1/payments/:id/confirm** -- Submit transaction hash (static wallet mode)

Full API documentation: [docs.payzcore.com](https://docs.payzcore.com)

## Before Going Live

**Always test your setup before accepting real payments:**

1. **Verify your wallet** — In the PayzCore dashboard, verify that your wallet addresses are correct. For HD wallets, click "Verify Key" and compare address #0 with your wallet app.
2. **Run a test order** — Place a test order for a small amount ($1–5) and complete the payment. Verify the funds arrive in your wallet.
3. **Test sweeping** — Send the test funds back out to confirm you control the addresses with your private keys.

> **Warning:** Wrong wallet configuration means payments go to addresses you don't control. Funds sent to incorrect addresses are permanently lost. PayzCore is watch-only and cannot recover funds. Please test before going live.

## License

MIT

## See Also

- [Getting Started](https://docs.payzcore.com/getting-started) — Account setup and first payment
- [Webhooks Guide](https://docs.payzcore.com/guides/webhooks) — Events, headers, and signature verification
- [Supported Networks](https://docs.payzcore.com/guides/networks) — Available networks and tokens
- [Error Reference](https://docs.payzcore.com/guides/errors) — HTTP status codes and troubleshooting
- [API Reference](https://docs.payzcore.com) — Interactive API documentation

## Support

- Documentation: [docs.payzcore.com](https://docs.payzcore.com)
- Website: [payzcore.com](https://payzcore.com)
- Email: support@payzcore.com
