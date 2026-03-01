<?php
/**
 * PayzCore WHMCS Gateway Module
 *
 * Blockchain transaction monitoring integration for WHMCS.
 * Watches for incoming stablecoin transfers (USDT/USDC) on multiple
 * networks (TRC20, BEP20, ERC20, Polygon, Arbitrum) and notifies
 * WHMCS when payments are detected via webhook callbacks.
 *
 * PayzCore is a non-custodial monitoring API. It does not hold,
 * transmit, or custody any funds.
 *
 * @package    PayzCore
 * @author     PayzCore <support@payzcore.com>
 * @copyright  2026 PayzCore
 * @license    MIT
 * @link       https://payzcore.com
 * @version    1.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/payzcore/PayzCoreApi.php';

/**
 * Module metadata.
 *
 * @return array
 */
function payzcore_MetaData()
{
    return [
        'DisplayName'                => 'PayzCore - Accept USDT & USDC Crypto Payments',
        'APIVersion'                 => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'           => false,
    ];
}

/**
 * Gateway configuration fields displayed in WHMCS admin.
 *
 * @return array
 */
function payzcore_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'PayzCore (USDT/USDC)',
        ],
        'setupGuide' => [
            'FriendlyName' => '<strong style="color:#06b6d4;">Setup Guide</strong>',
            'Type'         => '',
            'Description'  => '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px 16px;margin:4px 0;font-size:13px;line-height:1.7;">'
                            . '<strong>Before you begin:</strong><br>'
                            . '1. Create a PayzCore account at <a href="https://app.payzcore.com/register" target="_blank">app.payzcore.com</a><br>'
                            . '2. Create a <strong>Project</strong> and add a <strong>Wallet</strong> (HD xPub or static addresses) for the blockchain network you want to use<br>'
                            . '3. Copy your <strong>API Key</strong> and <strong>Webhook Secret</strong> from the project settings<br>'
                            . '4. Set your <strong>Webhook URL</strong> in the PayzCore project to: <code>' . htmlspecialchars(($_SERVER['HTTP_HOST'] ?? 'yourdomain.com'), ENT_QUOTES, 'UTF-8') . '/modules/gateways/callback/payzcore.php</code><br>'
                            . '5. Fill in the fields below and activate the gateway<br><br>'
                            . '<span style="color:#d97706;">⚠</span> <strong>You must have a wallet configured for the selected blockchain network in your PayzCore project.</strong> If no wallet is set up, payments will fail at checkout.'
                            . '</div>',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://api.payzcore.com',
            'Description'  => 'PayzCore API base URL. Change only if using a self-hosted instance.',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Found at <a href="https://app.payzcore.com" target="_blank">app.payzcore.com</a> → Projects → your project → Settings.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Shown once when you create a project (or regenerate keys) at <a href="https://app.payzcore.com" target="_blank">app.payzcore.com</a>.',
        ],
        'expiryMinutes' => [
            'FriendlyName' => 'Payment Expiry (minutes)',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '60',
            'Description'  => 'Time window for the customer to send payment (10-1440 minutes).',
        ],
        'staticAddress' => [
            'FriendlyName' => 'Static Wallet Address (Optional)',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'If set, all payments use this fixed address. Customers submit their transaction hash (TxID) after sending. Leave empty to use HD wallet addresses derived automatically by PayzCore.',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Enable test mode logging. Payments are still created normally.',
        ],
        'configSync' => [
            'FriendlyName' => '<strong style="color:#06b6d4;">Connection Status</strong>',
            'Type'         => '',
            'Description'  => isset($_GET['module']) && $_GET['module'] === 'payzcore'
                ? _payzcore_renderConfigStatus()
                : '<em style="color:#6b7280;">Open gateway settings to view connection status.</em>',
        ],

        // --- Customizable Text Fields ---
        'textPaymentTitle' => [
            'FriendlyName' => 'Text: Payment Title',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Send exactly this amount to the address below',
            'Description'  => 'Subtitle shown on the payment page.',
        ],
        'textDepositAddress' => [
            'FriendlyName' => 'Text: Address Label',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'Deposit Address',
            'Description'  => 'Label above the wallet address.',
        ],
        'textCopied' => [
            'FriendlyName' => 'Text: Copied',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => 'Address copied!',
            'Description'  => 'Feedback shown when address is copied.',
        ],
        'textStatusWaiting' => [
            'FriendlyName' => 'Text: Waiting',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Waiting for transfer...',
            'Description'  => 'Status text while waiting for payment.',
        ],
        'textStatusConfirming' => [
            'FriendlyName' => 'Text: Confirming',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Transfer detected, confirming...',
            'Description'  => 'Status when transfer is being confirmed.',
        ],
        'textStatusConfirmed' => [
            'FriendlyName' => 'Text: Confirmed',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Transfer confirmed!',
            'Description'  => 'Status when payment is confirmed.',
        ],
        'textStatusExpired' => [
            'FriendlyName' => 'Text: Expired',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Monitoring window expired',
            'Description'  => 'Status when payment window expires.',
        ],
        'textStatusPartial' => [
            'FriendlyName' => 'Text: Partial',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'Partial transfer received',
            'Description'  => 'Status when partial payment detected.',
        ],
        'textTimeRemaining' => [
            'FriendlyName' => 'Text: Time Remaining',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => 'Time Remaining',
            'Description'  => 'Label for countdown timer.',
        ],
        'textWarning' => [
            'FriendlyName' => 'Text: Warning',
            'Type'         => 'textarea',
            'Rows'         => '3',
            'Cols'         => '60',
            'Default'      => 'Send only {token} on {network} to this address. Sending other tokens or using the wrong network may result in loss.',
            'Description'  => 'Warning text. Use {token} and {network} as placeholders.',
        ],
        'textTestMode' => [
            'FriendlyName' => 'Text: Test Mode Banner',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'TEST MODE - Monitoring is active but logging is verbose',
            'Description'  => 'Banner shown when test mode is enabled.',
        ],
    ];
}

/**
 * Render the connection status HTML for the admin config page.
 *
 * Auto-syncs config from the API when credentials are available
 * (triggered every time admin views/saves the gateway settings).
 * Shows cached network info or a prompt to configure.
 *
 * @return string HTML
 */
function _payzcore_renderConfigStatus()
{
    try {
    // Auto-sync: attempt fresh fetch when admin views the config page
    $gatewayParams = getGatewayVariables('payzcore');
    $didFetch = false;
    if (!empty($gatewayParams['apiKey'])) {
        $apiUrl = isset($gatewayParams['apiUrl']) ? trim($gatewayParams['apiUrl'], '/') : 'https://api.payzcore.com';
        $didFetch = (_payzcore_fetchConfig($apiUrl, $gatewayParams['apiKey']) !== null);
    }

    // Force-refresh static cache after a successful API fetch
    $cached = _payzcore_getCachedConfig($didFetch);
    $cachedAt = '';

    $result = full_query("SELECT value FROM tblconfiguration WHERE setting='PayzCoreCachedAt' LIMIT 1");
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $cachedAt = $row['value'];
    }

    if (!empty($cached['networks']) && is_array($cached['networks'])) {
        $networkNames = [];
        foreach ($cached['networks'] as $c) {
            $name = $c['name'] ?? $c['network'];
            $tokens = isset($c['tokens']) ? implode(', ', $c['tokens']) : 'USDT';
            $networkNames[] = $name . ' (' . $tokens . ')';
        }
        $networkList = implode(', ', $networkNames);
        $syncTime = htmlspecialchars($cachedAt, ENT_QUOTES, 'UTF-8');

        return '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 16px;font-size:13px;line-height:1.6;">'
             . '<strong style="color:#16a34a;">&#10003; Connected</strong><br>'
             . '<strong>Available networks:</strong> ' . htmlspecialchars($networkList, ENT_QUOTES, 'UTF-8') . '<br>'
             . '<strong>Last sync:</strong> ' . $syncTime . '<br><br>'
             . '<em style="color:#6b7280;">Network list syncs automatically when you open this page. Available networks are determined by your wallet configuration at <a href="https://app.payzcore.com" target="_blank">app.payzcore.com</a>.</em>'
             . '</div>';
    }

    return '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;font-size:13px;line-height:1.6;">'
         . '<strong style="color:#d97706;">&#9888; Not connected</strong><br>'
         . 'Enter a valid API key and save settings to connect to your PayzCore project.<br>'
         . 'Available networks are determined by your wallet configuration at <a href="https://app.payzcore.com" target="_blank">app.payzcore.com</a>.'
         . '</div>';
    } catch (\Exception $e) {
        return '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;font-size:13px;">'
             . '<strong style="color:#d97706;">&#9888; Unable to check connection status</strong><br>'
             . '<small style="color:#6b7280;">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</small>'
             . '</div>';
    }
}

/**
 * Generate payment HTML for the invoice page.
 *
 * Called when the customer views an unpaid invoice and selects PayzCore.
 * Creates a monitoring request via the PayzCore API and renders
 * address + QR code + countdown timer.
 *
 * @param array $params WHMCS gateway parameters
 * @return string HTML output
 */
function payzcore_link($params)
{
    // Gateway configuration
    $apiUrl        = trim($params['apiUrl'], '/');
    $apiKey        = $params['apiKey'];
    $staticAddress = trim($params['staticAddress'] ?? '');
    $testMode      = ($params['testMode'] === 'on');
    $expiryMinutes = max(10, min(1440, intval($params['expiryMinutes'] ?: 60)));
    $expirySeconds = $expiryMinutes * 60;

    // Multi-network: get enabled networks and default token from cached API config
    $enabledNetworks = _payzcore_getEnabledNetworks($params);
    $defaultToken    = _payzcore_getDefaultToken($params);

    // Invoice details
    $invoiceId   = $params['invoiceid'];
    $amount      = $params['amount'];
    $currency    = $params['currency'];
    $email       = $params['clientdetails']['email'];
    $clientId    = $params['clientdetails']['userid'];

    // Network labels for display
    $networkLabels = [
        'TRC20'    => 'Tron (TRC20)',
        'BEP20'    => 'BSC (BEP20)',
        'ERC20'    => 'Ethereum (ERC20)',
        'POLYGON'  => 'Polygon',
        'ARBITRUM' => 'Arbitrum',
    ];

    // Customizable text fields (admin-editable for i18n) — built with placeholder token/network
    // These will be finalized once network/token are determined
    $warningRaw = $params['textWarning']
        ?: 'Send only {token} on {network} to this address. Sending other tokens or using the wrong network may result in loss.';

    // Only process USD amounts (stablecoin monitoring)
    if (strtoupper($currency) !== 'USD') {
        return '<div style="padding:20px;color:#ef4444;background:#1a1a1a;border-radius:8px;text-align:center;">'
             . '<strong>This payment method only supports USD.</strong><br>'
             . 'Your invoice currency (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ') is not supported. '
             . 'Please contact support.'
             . '</div>';
    }

    // Check for existing PayzCore payment ID stored for this invoice
    $existingPaymentId = _payzcore_getStoredPaymentId($invoiceId);

    $api = new PayzCoreApi($apiUrl, $apiKey);

    try {
        if ($existingPaymentId) {
            // Fetch existing payment status
            $payment = $api->getPayment($existingPaymentId);

            // Determine network/token from the existing payment for text rendering
            $network = $payment['network'] ?? $enabledNetworks[0];
            $token = $payment['token'] ?? $defaultToken;
            $networkLabel = isset($networkLabels[$network]) ? $networkLabels[$network] : $network;
            $texts = _payzcore_buildTexts($params, $token, $networkLabel, $warningRaw);

            // If already completed, show success
            if (in_array($payment['status'], ['paid', 'overpaid'])) {
                return _payzcore_renderSuccess($payment, $texts);
            }

            // If expired or cancelled, create a new one (fall through to network selection or creation)
            if (in_array($payment['status'], ['expired', 'cancelled'])) {
                $existingPaymentId = null;
            } else {
                // Load static wallet fields from stored mapping
                $mapping = _payzcore_getMappingByPaymentId($existingPaymentId);
                if ($mapping) {
                    $payment['_notice']           = $mapping['notice'] ?? '';
                    $payment['_requires_txid']    = !empty($mapping['requires_txid']);
                    $payment['_confirm_endpoint'] = $mapping['confirm_endpoint'] ?? '';
                }

                // Still pending/confirming/partial - show existing payment details
                return _payzcore_renderPaymentPage($payment, $invoiceId, $testMode, $texts);
            }
        }

        // --- Determine network and token for new payment ---

        // Check if customer submitted the network selector form
        $selectedNetwork = isset($_POST['payzcore_network']) ? $_POST['payzcore_network'] : null;
        $selectedToken = isset($_POST['payzcore_token']) ? $_POST['payzcore_token'] : null;

        // If multiple networks enabled and customer hasn't selected yet, show selector
        if (count($enabledNetworks) > 1 && $selectedNetwork === null) {
            return _payzcore_renderNetworkSelector($params, $enabledNetworks, $defaultToken, $invoiceId, $amount, $testMode);
        }

        // Resolve final network and token
        if ($selectedNetwork !== null) {
            // Validate submitted network is in the enabled list
            $network = in_array($selectedNetwork, $enabledNetworks, true) ? $selectedNetwork : $enabledNetworks[0];
            $token = ($selectedToken === 'USDT' || $selectedToken === 'USDC') ? $selectedToken : $defaultToken;
        } else {
            // Single network enabled, use it directly
            $network = $enabledNetworks[0];
            $token = $defaultToken;
        }

        // TRC20 only supports USDT
        if ($network === 'TRC20') {
            $token = 'USDT';
        }

        // Build texts with resolved network/token
        $networkLabel = isset($networkLabels[$network]) ? $networkLabels[$network] : $network;
        $texts = _payzcore_buildTexts($params, $token, $networkLabel, $warningRaw);

        // Create new monitoring request
        $createParams = [
            'amount'            => floatval($amount),
            'network'           => $network,
            'token'             => $token,
            'external_ref'      => 'whmcs-client-' . $clientId,
            'external_order_id' => 'WHMCS-' . $invoiceId,
            'expires_in'        => $expirySeconds,
            'metadata'          => [
                'invoiceId' => $invoiceId,
                'clientId'  => $clientId,
                'source'    => 'whmcs',
            ],
        ];

        // Include static wallet address if configured
        if (!empty($staticAddress)) {
            $createParams['address'] = $staticAddress;
        }

        $paymentData = $api->createPayment($createParams);

        $payment = $paymentData['payment'];

        // Extract static wallet response fields (nested inside payment object)
        $notice          = isset($payment['notice']) ? $payment['notice'] : '';
        $requiresTxid    = !empty($payment['requires_txid']);
        $confirmEndpoint = isset($payment['confirm_endpoint']) ? $payment['confirm_endpoint'] : '';

        // Store payment ID for this invoice (with extra fields for mapping table)
        _payzcore_storePaymentId($invoiceId, $payment['id'], [
            'amount'           => $payment['amount'],
            'network'          => $payment['network'],
            'token'            => $payment['token'] ?? $token,
            'address'          => $payment['address'],
            'notice'           => $notice,
            'requires_txid'    => $requiresTxid ? 1 : 0,
            'confirm_endpoint' => $confirmEndpoint,
        ]);

        if ($testMode) {
            logTransaction('payzcore', [
                'action'           => 'create_monitoring',
                'payment_id'       => $payment['id'],
                'invoice_id'       => $invoiceId,
                'amount'           => $payment['amount'],
                'network'          => $payment['network'],
                'token'            => $payment['token'] ?? $token,
                'address'          => $payment['address'],
                'notice'           => $notice,
                'requires_txid'    => $requiresTxid,
                'confirm_endpoint' => $confirmEndpoint,
            ], 'Payment monitoring created (test mode)');
        }

        // Pass static wallet fields to the renderer
        $payment['_notice']           = $notice;
        $payment['_requires_txid']    = $requiresTxid;
        $payment['_confirm_endpoint'] = $confirmEndpoint;

        return _payzcore_renderPaymentPage($payment, $invoiceId, $testMode, $texts);

    } catch (PayzCoreApiException $e) {
        logTransaction('payzcore', [
            'error'      => $e->getMessage(),
            'invoice_id' => $invoiceId,
        ], 'API Error');

        return '<div style="padding:20px;color:#ef4444;background:#1a1a1a;border-radius:8px;text-align:center;">'
             . '<strong>Unable to create monitoring request.</strong><br>'
             . 'Please try again or contact support.<br>'
             . '<small style="color:#71717a;">Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</small>'
             . '</div>';
    }
}

/**
 * Build the admin-customizable text array with resolved token and network placeholders.
 *
 * @param array  $params     WHMCS gateway parameters
 * @param string $token      Resolved token (USDT/USDC)
 * @param string $networkLabel Human-readable network label
 * @param string $warningRaw Raw warning text with {token}/{network} placeholders
 * @return array
 */
function _payzcore_buildTexts($params, $token, $networkLabel, $warningRaw)
{
    return [
        'payment_title'    => $params['textPaymentTitle'] ?: 'Send exactly this amount to the address below',
        'deposit_address'  => $params['textDepositAddress'] ?: 'Deposit Address',
        'copied'           => $params['textCopied'] ?: 'Address copied!',
        'status_waiting'   => $params['textStatusWaiting'] ?: 'Waiting for transfer...',
        'status_confirming'=> $params['textStatusConfirming'] ?: 'Transfer detected, confirming...',
        'status_confirmed' => $params['textStatusConfirmed'] ?: 'Transfer confirmed!',
        'status_expired'   => $params['textStatusExpired'] ?: 'Monitoring window expired',
        'status_partial'   => $params['textStatusPartial'] ?: 'Partial transfer received',
        'time_remaining'   => $params['textTimeRemaining'] ?: 'Time Remaining',
        'warning'          => str_replace(['{token}', '{network}'], [$token, $networkLabel], $warningRaw),
        'test_mode'        => $params['textTestMode'] ?: 'TEST MODE - Monitoring is active but logging is verbose',
    ];
}

/**
 * Render the network and token selector form for multi-network checkout.
 *
 * Displayed when multiple networks are enabled and the customer has not yet
 * selected a network. The form posts back to the same invoice page.
 *
 * @param array  $params          WHMCS gateway parameters
 * @param array  $enabledNetworks List of enabled network identifiers
 * @param string $defaultToken    Default token (USDT/USDC)
 * @param int    $invoiceId       WHMCS invoice ID
 * @param string $amount          Invoice amount
 * @param bool   $testMode        Whether test mode is enabled
 * @return string HTML output
 */
function _payzcore_renderNetworkSelector($params, $enabledNetworks, $defaultToken, $invoiceId, $amount, $testMode)
{
    $networkOptions = [
        'TRC20'    => ['label' => 'TRC20 (Tron)',       'desc' => 'Most popular'],
        'BEP20'    => ['label' => 'BEP20 (BSC)',        'desc' => 'Low fees'],
        'ERC20'    => ['label' => 'ERC20 (Ethereum)',    'desc' => 'Higher gas fees'],
        'POLYGON'  => ['label' => 'Polygon',            'desc' => 'Lowest fees'],
        'ARBITRUM' => ['label' => 'Arbitrum (L2)',       'desc' => 'Low fees'],
    ];

    // Build network <option> tags
    $networkOptionsHtml = '';
    foreach ($enabledNetworks as $network) {
        $info  = isset($networkOptions[$network]) ? $networkOptions[$network] : ['label' => $network, 'desc' => ''];
        $label = htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8');
        $desc  = !empty($info['desc']) ? ' - ' . htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8') : '';
        $val   = htmlspecialchars($network, ENT_QUOTES, 'UTF-8');
        $networkOptionsHtml .= '<option value="' . $val . '">' . $label . $desc . '</option>';
    }

    $defaultTokenEsc = htmlspecialchars($defaultToken, ENT_QUOTES, 'UTF-8');
    $amountEsc       = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
    $csrfToken       = generate_token('link');

    // Determine if TRC20 is the first/default network (to set initial token visibility)
    $firstNetwork    = $enabledNetworks[0];
    $tokenRowDisplay = ($firstNetwork === 'TRC20') ? 'none' : 'block';
    $usdcSelected    = ($defaultToken === 'USDC') ? ' selected' : '';
    $usdtSelected    = ($defaultToken !== 'USDC') ? ' selected' : '';

    $testBanner = '';
    if ($testMode) {
        $testModeText = htmlspecialchars(
            $params['textTestMode'] ?: 'TEST MODE - Monitoring is active but logging is verbose',
            ENT_QUOTES,
            'UTF-8'
        );
        $testBanner = '<div style="background:#92400e;color:#fbbf24;padding:8px 16px;border-radius:6px;'
                    . 'font-size:12px;margin-bottom:16px;text-align:center;">'
                    . $testModeText
                    . '</div>';
    }

    // Shared inline styles for form elements
    $selectStyle = 'width:100%;background:#000000;border:1px solid rgba(255,255,255,0.1);'
                 . 'border-radius:8px;padding:10px 14px;font-size:14px;color:#ffffff;'
                 . 'appearance:none;-webkit-appearance:none;cursor:pointer;outline:none;'
                 . 'background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' fill=\'%2371717a\' viewBox=\'0 0 16 16\'%3E%3Cpath d=\'M8 11L3 6h10z\'/%3E%3C/svg%3E");'
                 . 'background-repeat:no-repeat;background-position:right 12px center;padding-right:36px;';

    $labelStyle = 'display:block;font-size:11px;color:#71717a;text-transform:uppercase;'
                . 'letter-spacing:1px;margin-bottom:6px;';

    $buttonStyle = 'display:block;width:100%;padding:12px;background:#06b6d4;color:#000000;'
                 . 'border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;'
                 . 'transition:background 0.15s;letter-spacing:0.5px;';

    $html = <<<HTML
<div id="payzcore-network-selector" style="
    max-width: 440px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #e4e4e7;
">
    {$testBanner}

    <div style="
        background: #09090b;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 28px;
    ">
        <!-- Header -->
        <div style="text-align:center;margin-bottom:24px;">
            <div style="font-size:28px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">
                {$amountEsc} <span style="color:#06b6d4;" id="payzcore-amount-token">{$defaultTokenEsc}</span>
            </div>
            <div style="font-size:13px;color:#71717a;margin-top:4px;">
                Select your preferred blockchain network
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="token" value="{$csrfToken}">
            <!-- Network selector -->
            <div style="margin-bottom:16px;">
                <label style="{$labelStyle}">Blockchain Network</label>
                <select name="payzcore_network" id="payzcore-network-select" style="{$selectStyle}">
                    {$networkOptionsHtml}
                </select>
            </div>

            <!-- Token selector (hidden for TRC20) -->
            <div id="payzcore-token-row" style="margin-bottom:16px;display:{$tokenRowDisplay};">
                <label style="{$labelStyle}">Stablecoin</label>
                <select name="payzcore_token" id="payzcore-token-select" style="{$selectStyle}">
                    <option value="USDT"{$usdtSelected}>USDT (Tether)</option>
                    <option value="USDC"{$usdcSelected}>USDC (Circle)</option>
                </select>
            </div>

            <!-- Network info hint -->
            <div id="payzcore-network-hint" style="
                background: rgba(6,182,212,0.05);
                border: 1px solid rgba(6,182,212,0.15);
                border-radius: 8px;
                padding: 10px 14px;
                margin-bottom: 20px;
                font-size: 12px;
                color: #a1a1aa;
                text-align: center;
            "></div>

            <!-- Submit button -->
            <button type="submit" style="{$buttonStyle}"
                onmouseover="this.style.background='#22d3ee'"
                onmouseout="this.style.background='#06b6d4'">
                Continue to Payment
            </button>
        </form>

        <!-- Footer -->
        <div style="
            text-align: center;
            font-size: 11px;
            color: #52525b;
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 16px;
            margin-top: 20px;
        ">
            Secure blockchain payment &mdash; Send to the exact address shown
        </div>
    </div>
</div>

<script>
(function() {
    var networkSelect = document.getElementById('payzcore-network-select');
    var tokenRow      = document.getElementById('payzcore-token-row');
    var tokenSelect   = document.getElementById('payzcore-token-select');
    var amountToken   = document.getElementById('payzcore-amount-token');
    var hintEl        = document.getElementById('payzcore-network-hint');

    var networkHints = {
        'TRC20':    'Most widely used for USDT. Fast and affordable transactions.',
        'BEP20':    'Binance Smart Chain. Low gas fees, fast confirmations.',
        'ERC20':    'Ethereum mainnet. Most secure but higher gas fees.',
        'POLYGON':  'Polygon network. Very low fees, good for small amounts.',
        'ARBITRUM': 'Ethereum L2 rollup. Low fees with Ethereum security.'
    };

    function updateUI() {
        var network = networkSelect.value;

        // TRC20 only supports USDT
        if (network === 'TRC20') {
            tokenRow.style.display = 'none';
            tokenSelect.value = 'USDT';
            if (amountToken) amountToken.textContent = 'USDT';
        } else {
            tokenRow.style.display = 'block';
            if (amountToken) amountToken.textContent = tokenSelect.value;
        }

        // Update hint
        if (hintEl && networkHints[network]) {
            hintEl.textContent = networkHints[network];
            hintEl.style.display = 'block';
        } else if (hintEl) {
            hintEl.style.display = 'none';
        }
    }

    networkSelect.addEventListener('change', updateUI);
    tokenSelect.addEventListener('change', function() {
        if (amountToken) amountToken.textContent = tokenSelect.value;
    });

    // Initialize
    updateUI();
})();
</script>
HTML;

    return $html;
}

/**
 * Module activation: create dedicated mapping table.
 *
 * @return array Status message for WHMCS admin.
 */
function payzcore_activate()
{
    full_query("CREATE TABLE IF NOT EXISTS `mod_payzcore_mappings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `payment_id` varchar(255) NOT NULL,
        `amount` decimal(20,8) NOT NULL DEFAULT '0.00000000',
        `network` varchar(20) NOT NULL DEFAULT 'TRC20',
        `token` varchar(10) NOT NULL DEFAULT 'USDT',
        `address` varchar(255) DEFAULT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `notice` text DEFAULT NULL,
        `requires_txid` tinyint(1) NOT NULL DEFAULT 0,
        `confirm_endpoint` varchar(500) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `invoice_id` (`invoice_id`),
        KEY `payment_id` (`payment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Add columns for existing installations (MySQL 5.x compatible — no IF NOT EXISTS)
    @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `notice` text DEFAULT NULL");
    @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `requires_txid` tinyint(1) NOT NULL DEFAULT 0");
    @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `confirm_endpoint` varchar(500) DEFAULT NULL");

    // Try to sync config if credentials already set
    $gatewayParams = getGatewayVariables('payzcore');
    if (!empty($gatewayParams['apiKey'])) {
        $apiUrl = $gatewayParams['apiUrl'] ?? 'https://api.payzcore.com';
        _payzcore_fetchConfig($apiUrl, $gatewayParams['apiKey']);
    }

    return ['status' => 'success', 'description' => 'PayzCore gateway activated. Mapping table created.'];
}

/**
 * Module deactivation: drop mapping table.
 *
 * @return array Status message for WHMCS admin.
 */
function payzcore_deactivate()
{
    // Mapping table is preserved on deactivation to prevent data loss.
    // Payment history and invoice mappings remain intact for auditing.
    // The table will be reused if the module is reactivated.

    return ['status' => 'success', 'description' => 'PayzCore gateway deactivated. Payment mappings preserved.'];
}

/**
 * Get the list of enabled blockchain networks from cached API config.
 *
 * Reads from cached /v1/config response. If no cache exists,
 * attempts a fresh API fetch. Falls back to TRC20 as a last resort.
 *
 * @param array $params WHMCS gateway parameters
 * @return array List of network identifiers (e.g. ['TRC20', 'BEP20'])
 */
function _payzcore_getEnabledNetworks($params)
{
    // Try cached config first
    $cached = _payzcore_getCachedConfig();
    if (!empty($cached['networks'])) {
        $networks = [];
        foreach ($cached['networks'] as $c) {
            if (isset($c['network'])) {
                $networks[] = $c['network'];
            }
        }
        if (!empty($networks)) {
            return $networks;
        }
    }

    // No cache — try fresh fetch
    $apiUrl = isset($params['apiUrl']) ? trim($params['apiUrl'], '/') : 'https://api.payzcore.com';
    $apiKey = isset($params['apiKey']) ? $params['apiKey'] : '';
    if (!empty($apiKey)) {
        $config = _payzcore_fetchConfig($apiUrl, $apiKey);
        if (!empty($config['networks'])) {
            $networks = [];
            foreach ($config['networks'] as $c) {
                if (isset($c['network'])) {
                    $networks[] = $c['network'];
                }
            }
            if (!empty($networks)) {
                return $networks;
            }
        }
    }

    // Last resort fallback
    return ['TRC20'];
}

/**
 * Get the default token from cached API config or fallback.
 *
 * @param array $params WHMCS gateway parameters
 * @return string Token identifier (USDT or USDC)
 */
function _payzcore_getDefaultToken($params)
{
    $cached = _payzcore_getCachedConfig();
    if (!empty($cached['default_token'])) {
        return $cached['default_token'];
    }

    // No cache — try fresh fetch
    $apiUrl = isset($params['apiUrl']) ? trim($params['apiUrl'], '/') : 'https://api.payzcore.com';
    $apiKey = isset($params['apiKey']) ? $params['apiKey'] : '';
    if (!empty($apiKey)) {
        $config = _payzcore_fetchConfig($apiUrl, $apiKey);
        if (!empty($config['default_token'])) {
            return $config['default_token'];
        }
    }

    return 'USDT';
}

/**
 * Fetch project config from PayzCore API and store in cache.
 *
 * @param string $apiUrl  API base URL
 * @param string $apiKey  Project API key
 * @return array|null Decoded config response or null on failure
 */
function _payzcore_fetchConfig($apiUrl, $apiKey)
{
    $url = rtrim($apiUrl, '/') . '/v1/config';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Accept: application/json',
            'User-Agent: PayzCore-WHMCS/1.0.0',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logTransaction('payzcore', ['error' => $curlError], 'Config fetch cURL error');
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        logTransaction('payzcore', ['http_code' => $httpCode, 'body' => $body], 'Config fetch HTTP error');
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }

    // Cache the result
    _payzcore_storeCachedConfig($data);

    return $data;
}

/**
 * Store cached config in the WHMCS database.
 *
 * @param array $config Config response from API
 */
function _payzcore_storeCachedConfig($config)
{
    $json = json_encode($config);
    $escaped = db_escape_string($json);
    $time = date('Y-m-d H:i:s');

    // Use tblconfiguration for cached config
    $result = full_query("SELECT value FROM tblconfiguration WHERE setting='PayzCoreCachedConfig' LIMIT 1");
    if (mysqli_num_rows($result) > 0) {
        full_query("UPDATE tblconfiguration SET value='{$escaped}' WHERE setting='PayzCoreCachedConfig'");
    } else {
        full_query("INSERT INTO tblconfiguration (setting, value) VALUES ('PayzCoreCachedConfig', '{$escaped}')");
    }

    // Store sync timestamp
    $result2 = full_query("SELECT value FROM tblconfiguration WHERE setting='PayzCoreCachedAt' LIMIT 1");
    $timeEscaped = db_escape_string($time);
    if (mysqli_num_rows($result2) > 0) {
        full_query("UPDATE tblconfiguration SET value='{$timeEscaped}' WHERE setting='PayzCoreCachedAt'");
    } else {
        full_query("INSERT INTO tblconfiguration (setting, value) VALUES ('PayzCoreCachedAt', '{$timeEscaped}')");
    }
}

/**
 * Get cached config from the WHMCS database.
 *
 * @param bool $forceRefresh If true, bypass static cache and re-read from DB
 * @return array|null Cached config or null
 */
function _payzcore_getCachedConfig($forceRefresh = false)
{
    static $cache = null;
    if ($cache !== null && !$forceRefresh) {
        return $cache;
    }

    $result = full_query("SELECT value FROM tblconfiguration WHERE setting='PayzCoreCachedConfig' LIMIT 1");
    $row = mysqli_fetch_assoc($result);

    if ($row && !empty($row['value'])) {
        $cache = json_decode($row['value'], true);
        return $cache;
    }

    $cache = [];
    return null;
}

/**
 * Store PayzCore payment ID associated with a WHMCS invoice.
 *
 * @param int    $invoiceId
 * @param string $paymentId
 * @param array  $extra     Optional extra fields (amount, network, token, address)
 */
function _payzcore_storePaymentId($invoiceId, $paymentId, $extra = [])
{
    $invoiceId = intval($invoiceId);
    $paymentId = preg_replace('/[^a-zA-Z0-9\-]/', '', $paymentId);

    // Ensure mapping table exists (idempotent)
    _payzcore_ensureMappingTable();

    $data = [
        'invoice_id'       => $invoiceId,
        'payment_id'       => $paymentId,
        'amount'           => isset($extra['amount']) ? floatval($extra['amount']) : 0,
        'network'          => isset($extra['network']) ? $extra['network'] : 'TRC20',
        'token'            => isset($extra['token']) ? $extra['token'] : 'USDT',
        'address'          => isset($extra['address']) ? $extra['address'] : null,
        'status'           => 'pending',
        'notice'           => isset($extra['notice']) ? $extra['notice'] : null,
        'requires_txid'    => !empty($extra['requires_txid']) ? 1 : 0,
        'confirm_endpoint' => isset($extra['confirm_endpoint']) ? $extra['confirm_endpoint'] : null,
    ];

    // Upsert: if invoice_id already exists, update it
    full_query(
        "INSERT INTO `mod_payzcore_mappings` (`invoice_id`, `payment_id`, `amount`, `network`, `token`, `address`, `status`, `notice`, `requires_txid`, `confirm_endpoint`)
         VALUES ('" . intval($data['invoice_id']) . "', '" . db_escape_string($data['payment_id']) . "',
                 '" . floatval($data['amount']) . "', '" . db_escape_string($data['network']) . "',
                 '" . db_escape_string($data['token']) . "', '" . db_escape_string($data['address'] ?? '') . "',
                 'pending',
                 '" . db_escape_string($data['notice'] ?? '') . "',
                 '" . intval($data['requires_txid']) . "',
                 '" . db_escape_string($data['confirm_endpoint'] ?? '') . "')
         ON DUPLICATE KEY UPDATE
             `payment_id` = VALUES(`payment_id`),
             `amount` = VALUES(`amount`),
             `network` = VALUES(`network`),
             `token` = VALUES(`token`),
             `address` = VALUES(`address`),
             `status` = 'pending',
             `notice` = VALUES(`notice`),
             `requires_txid` = VALUES(`requires_txid`),
             `confirm_endpoint` = VALUES(`confirm_endpoint`)"
    );

    logTransaction('payzcore', [
        'action'     => 'store_payment_id',
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
    ], 'Payment ID stored');
}

/**
 * Retrieve stored PayzCore payment ID for a WHMCS invoice.
 *
 * @param int $invoiceId
 * @return string|null
 */
function _payzcore_getStoredPaymentId($invoiceId)
{
    $invoiceId = intval($invoiceId);

    // Ensure mapping table exists (idempotent)
    _payzcore_ensureMappingTable();

    $result = select_query('mod_payzcore_mappings', 'payment_id', ['invoice_id' => $invoiceId]);
    $row = mysqli_fetch_assoc($result);

    if ($row && !empty($row['payment_id'])) {
        return $row['payment_id'];
    }

    return null;
}

/**
 * Retrieve stored mapping by PayzCore payment ID.
 *
 * @param string $paymentId
 * @return array|null
 */
function _payzcore_getMappingByPaymentId($paymentId)
{
    $paymentId = preg_replace('/[^a-zA-Z0-9\-]/', '', $paymentId);

    $result = select_query('mod_payzcore_mappings', '*', ['payment_id' => $paymentId]);
    $row = mysqli_fetch_assoc($result);

    return $row ?: null;
}

/**
 * Update mapping status.
 *
 * @param int    $invoiceId
 * @param string $status
 */
function _payzcore_updateMappingStatus($invoiceId, $status)
{
    $invoiceId = intval($invoiceId);
    update_query('mod_payzcore_mappings', ['status' => $status], ['invoice_id' => $invoiceId]);
}

/**
 * Ensure the mapping table exists (for upgrades from older versions).
 */
function _payzcore_ensureMappingTable()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $result = full_query("SHOW TABLES LIKE 'mod_payzcore_mappings'");
    if (mysqli_num_rows($result) === 0) {
        payzcore_activate();
    } else {
        // Add new columns for existing installations (MySQL 5.x compatible)
        @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `notice` text DEFAULT NULL");
        @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `requires_txid` tinyint(1) NOT NULL DEFAULT 0");
        @full_query("ALTER TABLE `mod_payzcore_mappings` ADD COLUMN `confirm_endpoint` varchar(500) DEFAULT NULL");
    }
}

/**
 * Render the payment page with address, QR code, and countdown.
 *
 * @param array  $payment
 * @param int    $invoiceId
 * @param bool   $testMode
 * @param array  $texts  Admin-customizable text strings
 * @return string
 */
function _payzcore_renderPaymentPage($payment, $invoiceId, $testMode, $texts = [])
{
    $address          = htmlspecialchars($payment['address'], ENT_QUOTES, 'UTF-8');
    $amount           = htmlspecialchars($payment['amount'], ENT_QUOTES, 'UTF-8');
    $network          = htmlspecialchars($payment['network'], ENT_QUOTES, 'UTF-8');
    $status           = htmlspecialchars($payment['status'], ENT_QUOTES, 'UTF-8');
    $paymentId        = htmlspecialchars($payment['id'], ENT_QUOTES, 'UTF-8');
    $expiresAt        = htmlspecialchars($payment['expires_at'], ENT_QUOTES, 'UTF-8');
    $qrCode           = $payment['qr_code'] ?? '';
    $tokenName        = htmlspecialchars($payment['token'] ?? 'USDT', ENT_QUOTES, 'UTF-8');
    $notice           = $payment['_notice'] ?? '';
    $requiresTxid     = !empty($payment['_requires_txid']);
    $confirmEndpoint  = $payment['_confirm_endpoint'] ?? '';

    // JS-safe versions for embedding in inline <script> — json_encode prevents XSS in JS context.
    $jsPaymentId    = json_encode($payment['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $jsExpiresAt    = json_encode($payment['expires_at'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $jsAddress      = json_encode($payment['address'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $jsTokenName    = json_encode($payment['token'] ?? 'USDT', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $jsAmount       = json_encode($payment['amount'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $jsPollUrl      = json_encode(
        '../modules/gateways/callback/payzcore_poll.php?payment_id=' . urlencode($payment['id']),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $jsConfirmUrl   = $requiresTxid
        ? json_encode(
            '../modules/gateways/callback/payzcore_confirm.php?payment_id=' . urlencode($payment['id']),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        )
        : '""';
    $jsInvoiceId    = json_encode((string) $invoiceId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $networkLabels = [
        'TRC20'    => 'Tron (TRC20)',
        'BEP20'    => 'BSC (BEP20)',
        'ERC20'    => 'Ethereum (ERC20)',
        'POLYGON'  => 'Polygon',
        'ARBITRUM' => 'Arbitrum',
    ];
    $networkLabel = isset($networkLabels[$network]) ? $networkLabels[$network] : $network;

    // HTML-escaped proxy URLs (for use in HTML attributes if needed).
    $pollProxyUrl = htmlspecialchars(
        '../modules/gateways/callback/payzcore_poll.php?payment_id=' . urlencode($payment['id']),
        ENT_QUOTES, 'UTF-8'
    );
    $confirmProxyUrl = $requiresTxid
        ? htmlspecialchars('../modules/gateways/callback/payzcore_confirm.php?payment_id=' . urlencode($payment['id']), ENT_QUOTES, 'UTF-8')
        : '';

    // Text defaults (fallback if $texts not provided)
    $texts = array_merge([
        'payment_title'    => 'Send exactly this amount to the address below',
        'deposit_address'  => 'Deposit Address',
        'copied'           => 'Address copied!',
        'status_waiting'   => 'Waiting for transfer...',
        'status_confirming'=> 'Transfer detected, confirming...',
        'status_confirmed' => 'Transfer confirmed!',
        'status_expired'   => 'Monitoring window expired',
        'status_partial'   => 'Partial transfer received',
        'time_remaining'   => 'Time Remaining',
        'warning'          => 'Send only ' . $tokenName . ' on ' . $networkLabel . ' to this address. Sending other tokens or using the wrong network may result in loss.',
        'test_mode'        => 'TEST MODE - Monitoring is active but logging is verbose',
    ], $texts);

    // HTML-escape all text values for safe output
    $t = [];
    foreach ($texts as $key => $val) {
        $t[$key] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    }

    // JS-safe text values (for embedding in inline <script>)
    $jsEncoded = [];
    foreach ($texts as $key => $val) {
        $jsEncoded[$key] = json_encode($val, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    $testBanner = '';
    if ($testMode) {
        $testBanner = '<div style="background:#92400e;color:#fbbf24;padding:8px 16px;border-radius:6px;'
                    . 'font-size:12px;margin-bottom:16px;text-align:center;">'
                    . $t['test_mode']
                    . '</div>';
    }

    // Build the inline HTML (no external template dependency for maximum compatibility)
    $html = <<<HTML
<div id="payzcore-payment-container" style="
    max-width: 440px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #e4e4e7;
">
    {$testBanner}

    <div style="
        background: #09090b;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 28px;
    ">
        <!-- Header -->
        <div style="text-align:center;margin-bottom:24px;">
            <div style="
                display: inline-block;
                background: rgba(6,182,212,0.1);
                border: 1px solid rgba(6,182,212,0.2);
                border-radius: 20px;
                padding: 4px 14px;
                font-size: 12px;
                color: #06b6d4;
                margin-bottom: 12px;
            ">
                {$networkLabel}
            </div>
            <div style="font-size:28px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;display:flex;align-items:center;justify-content:center;gap:8px;">
                <span id="payzcore-amount-display">{$amount} <span style="color:#06b6d4;">{$tokenName}</span></span>
                <button type="button" onclick="payzCoreCopyAmount()" title="Copy amount" style="
                    background:none;border:none;cursor:pointer;color:#71717a;font-size:14px;
                    padding:4px 6px;border-radius:4px;transition:color 0.15s;line-height:1;
                " onmouseover="this.style.color='#06b6d4'" onmouseout="this.style.color='#71717a'">&#x2398;</button>
            </div>
            <div id="payzcore-amount-feedback" style="
                font-size:11px;color:#06b6d4;margin-top:2px;text-align:center;opacity:0;transition:opacity 0.3s;
            ">Amount copied!</div>
            <div style="font-size:13px;color:#71717a;margin-top:4px;">
                {$t['payment_title']}
            </div>
        </div>
HTML;

    // Notice from API (e.g., "Send exactly 50.003 USDT")
    if (!empty($notice)) {
        $noticeEscaped = htmlspecialchars($notice, ENT_QUOTES, 'UTF-8');
        $html .= '<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);'
               . 'border-radius:8px;padding:10px 14px;margin-bottom:20px;text-align:center;'
               . 'font-size:13px;color:#fbbf24;">'
               . $noticeEscaped
               . '</div>';
    }

    $html .= <<<HTML

        <!-- QR Code -->
        <div style="text-align:center;margin-bottom:20px;">
HTML;

    if (!empty($qrCode)) {
        $html .= '<img src="' . htmlspecialchars($qrCode, ENT_QUOTES, 'UTF-8') . '" alt="Payment QR Code" '
               . 'style="width:200px;height:200px;border-radius:8px;background:#000;" />';
    }

    $html .= <<<HTML
        </div>

        <!-- Address -->
        <div style="margin-bottom:20px;">
            <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                {$t['deposit_address']}
            </div>
            <div id="payzcore-address-box" style="
                background: #000000;
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                padding: 12px 14px;
                font-family: 'SF Mono', 'Fira Code', monospace;
                font-size: 13px;
                word-break: break-all;
                color: #ffffff;
                cursor: pointer;
                position: relative;
                transition: border-color 0.15s;
            " onclick="payzCoreCopyAddress()" title="Click to copy">
                {$address}
                <span id="payzcore-copy-icon" style="
                    position: absolute;
                    right: 12px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #71717a;
                    font-size: 14px;
                ">&#x2398;</span>
            </div>
            <div id="payzcore-copy-feedback" style="
                font-size:11px;
                color:#06b6d4;
                margin-top:4px;
                text-align:center;
                opacity:0;
                transition: opacity 0.3s;
            ">
                {$t['copied']}
            </div>
        </div>

        <!-- Status -->
        <div id="payzcore-status-area" style="
            background: #000000;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 20px;
        ">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <span style="font-size:12px;color:#71717a;">Status</span>
                    <div id="payzcore-status-label" style="font-size:14px;font-weight:600;color:#f59e0b;margin-top:2px;">
                        {$t['status_waiting']}
                    </div>
                </div>
                <div id="payzcore-spinner" style="
                    width: 24px;
                    height: 24px;
                    border: 2px solid rgba(6,182,212,0.2);
                    border-top-color: #06b6d4;
                    border-radius: 50%;
                    animation: payzcore-spin 0.8s linear infinite;
                "></div>
            </div>
        </div>

        <!-- Countdown -->
        <div style="text-align:center;margin-bottom:12px;">
            <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">
                {$t['time_remaining']}
            </div>
            <div id="payzcore-countdown" style="
                font-size: 22px;
                font-weight: 700;
                color: #06b6d4;
                font-variant-numeric: tabular-nums;
            ">
                --:--
            </div>
        </div>

HTML;

    // TX hash submission form (for static wallet / requires_txid mode)
    if ($requiresTxid) {
        $html .= <<<TXHTML
        <!-- TX Hash Submission -->
        <div id="payzcore-txid-section" style="
            background: #000000;
            border: 1px solid rgba(6,182,212,0.2);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 20px;
        ">
            <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">
                Confirm Payment
            </div>
            <div style="font-size:12px;color:#a1a1aa;margin-bottom:10px;">
                After sending the payment, paste your transaction hash below to speed up confirmation.
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="payzcore-txid-input" placeholder="Transaction hash (tx_hash)"
                    style="
                        flex:1;
                        background:#09090b;
                        border:1px solid rgba(255,255,255,0.1);
                        border-radius:6px;
                        padding:8px 12px;
                        font-family:'SF Mono','Fira Code',monospace;
                        font-size:12px;
                        color:#ffffff;
                        outline:none;
                    " />
                <button type="button" onclick="payzCoreSubmitTxid()"
                    style="
                        background:#06b6d4;
                        color:#000;
                        border:none;
                        border-radius:6px;
                        padding:8px 16px;
                        font-size:12px;
                        font-weight:600;
                        cursor:pointer;
                        white-space:nowrap;
                    ">Submit</button>
            </div>
            <div id="payzcore-txid-feedback" style="font-size:11px;margin-top:6px;text-align:center;opacity:0;transition:opacity 0.3s;">
            </div>
        </div>
TXHTML;
    }

    $html .= <<<HTML
        <!-- Footer note -->
        <div style="
            text-align: center;
            font-size: 11px;
            color: #52525b;
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 16px;
            margin-top: 8px;
        ">
            {$t['warning']}
        </div>
    </div>
</div>

<style>
@keyframes payzcore-spin {
    to { transform: rotate(360deg); }
}
@media (max-width: 480px) {
    #payzcore-payment-container > div { padding: 16px !important; }
    #payzcore-amount-display { font-size: 22px !important; }
    #payzcore-payment-container img[alt="Payment QR Code"] { max-width: 160px !important; height: auto !important; }
    #payzcore-address-box { font-size: 11px !important; }
}
</style>

<script>
(function() {
    var paymentId  = {$jsPaymentId};
    var expiresAt  = new Date({$jsExpiresAt}).getTime();
    var pollUrl    = {$jsPollUrl};
    var address    = {$jsAddress};
    var invoiceId  = {$jsInvoiceId};
    var tokenName  = {$jsTokenName};
    var pollTimer      = null;
    var countTimer     = null;
    var confirmUrl     = {$jsConfirmUrl};
    var pollFailCount  = 0;

    // Customizable status text (admin-editable)
    var textWaiting    = {$jsEncoded['status_waiting']};
    var textConfirming = {$jsEncoded['status_confirming']};
    var textConfirmed  = {$jsEncoded['status_confirmed']};
    var textExpired    = {$jsEncoded['status_expired']};
    var textPartial    = {$jsEncoded['status_partial']};

    // --- Copy to clipboard ---
    window.payzCoreCopyAddress = function() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(address);
        } else {
            var ta = document.createElement('textarea');
            ta.value = address;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        var fb = document.getElementById('payzcore-copy-feedback');
        if (fb) {
            fb.style.opacity = '1';
            setTimeout(function() { fb.style.opacity = '0'; }, 3000);
        }
    };

    // --- Copy amount to clipboard ---
    window.payzCoreCopyAmount = function() {
        var amountText = {$jsAmount};
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(amountText);
        } else {
            var ta = document.createElement('textarea');
            ta.value = amountText;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        var fb = document.getElementById('payzcore-amount-feedback');
        if (fb) {
            fb.style.opacity = '1';
            setTimeout(function() { fb.style.opacity = '0'; }, 3000);
        }
    };

    // --- Submit TX hash (static wallet mode) ---
    window.payzCoreSubmitTxid = function() {
        if (!confirmUrl) return;

        var input = document.getElementById('payzcore-txid-input');
        var feedback = document.getElementById('payzcore-txid-feedback');
        if (!input || !feedback) return;

        var txHash = input.value.trim();
        if (!txHash) {
            feedback.textContent = 'Please enter a transaction hash.';
            feedback.style.color = '#ef4444';
            feedback.style.opacity = '1';
            setTimeout(function() { feedback.style.opacity = '0'; }, 3000);
            return;
        }

        // Validate hex format
        var cleanHash = txHash.replace(/^0x/, '');
        if (!/^[a-fA-F0-9]{10,128}$/.test(cleanHash)) {
            feedback.textContent = 'Invalid hash format. Enter a valid transaction hash.';
            feedback.style.color = '#ef4444';
            feedback.style.opacity = '1';
            return;
        }

        // Disable button during submission
        var btn = input.parentNode.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', confirmUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = 15000;

        xhr.onload = function() {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success || xhr.status === 200) {
                    feedback.textContent = 'Transaction hash submitted successfully.';
                    feedback.style.color = '#06b6d4';
                    input.disabled = true;
                    if (btn) btn.style.display = 'none';
                } else {
                    feedback.textContent = data.error || 'Submission failed. Please try again.';
                    feedback.style.color = '#ef4444';
                }
            } catch (e) {
                feedback.textContent = 'Submission failed. Please try again.';
                feedback.style.color = '#ef4444';
            }
            feedback.style.opacity = '1';
        };

        xhr.onerror = function() {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
            feedback.textContent = 'Network error. Please try again.';
            feedback.style.color = '#ef4444';
            feedback.style.opacity = '1';
        };

        xhr.ontimeout = function() {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
            feedback.textContent = 'Request timed out. Please try again.';
            feedback.style.color = '#ef4444';
            feedback.style.opacity = '1';
        };

        xhr.send(JSON.stringify({ tx_hash: txHash }));
    };

    // --- Countdown timer ---
    function updateCountdown() {
        var now  = Date.now();
        var diff = Math.max(0, Math.floor((expiresAt - now) / 1000));
        var el   = document.getElementById('payzcore-countdown');
        if (!el) return;

        if (diff <= 0) {
            el.textContent = 'EXPIRED';
            el.style.color = '#ef4444';
            clearInterval(countTimer);
            updateStatus('expired', textExpired);
            clearInterval(pollTimer);
            var txInput = document.getElementById('payzcore-txid-input');
            var txBtn = txInput ? txInput.parentNode.querySelector('button') : null;
            if (txInput) txInput.disabled = true;
            if (txBtn) txBtn.disabled = true;
            return;
        }

        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;

        if (h > 0) {
            el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
        } else {
            el.textContent = pad(m) + ':' + pad(s);
        }

        // Turn red when less than 5 minutes
        if (diff < 300) {
            el.style.color = '#ef4444';
        }
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    // --- Status updates ---
    function updateStatus(status, label) {
        var el = document.getElementById('payzcore-status-label');
        var sp = document.getElementById('payzcore-spinner');
        if (!el) return;

        var colors = {
            'pending':    '#f59e0b',
            'confirming': '#3b82f6',
            'partial':    '#f59e0b',
            'paid':       '#06b6d4',
            'overpaid':   '#06b6d4',
            'expired':    '#ef4444',
            'cancelled':  '#ef4444'
        };

        el.textContent = label || status;
        el.style.color = colors[status] || '#71717a';

        if (sp) {
            if (status === 'paid' || status === 'overpaid' || status === 'expired' || status === 'cancelled') {
                sp.style.display = 'none';
            }
        }
    }

    // --- Poll for status ---
    function pollStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', pollUrl, true);
        xhr.timeout = 15000;

        xhr.onload = function() {
            if (xhr.status !== 200) return;
            pollFailCount = 0;

            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.success || !data.payment) return;

                var p = data.payment;

                switch (p.status) {
                    case 'pending':
                        updateStatus('pending', textWaiting);
                        break;
                    case 'confirming':
                        updateStatus('confirming', textConfirming);
                        break;
                    case 'partial':
                        updateStatus('partial', textPartial + ' (' + p.paid_amount + ' ' + tokenName + ') \u2014 Send the remaining amount to the same address');
                        break;
                    case 'paid':
                        updateStatus('paid', textConfirmed);
                        clearInterval(pollTimer);
                        clearInterval(countTimer);
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                        break;
                    case 'overpaid':
                        updateStatus('overpaid', textConfirmed + ' (overpaid)');
                        clearInterval(pollTimer);
                        clearInterval(countTimer);
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                        break;
                    case 'expired':
                        updateStatus('expired', textExpired);
                        clearInterval(pollTimer);
                        break;
                    case 'cancelled':
                        updateStatus('cancelled', 'Cancelled');
                        clearInterval(pollTimer);
                        break;
                }
            } catch (e) {
                // Silently ignore parse errors
            }
        };

        xhr.onerror = function() {
            pollFailCount++;
            if (pollFailCount >= 3) {
                var sl = document.getElementById('payzcore-status-label');
                if (sl) { sl.textContent = 'Connection issue \u2014 checking will resume automatically'; sl.style.color = '#f59e0b'; }
            }
        };
        xhr.ontimeout = function() {
            pollFailCount++;
            if (pollFailCount >= 3) {
                var sl = document.getElementById('payzcore-status-label');
                if (sl) { sl.textContent = 'Connection issue \u2014 checking will resume automatically'; sl.style.color = '#f59e0b'; }
            }
        };
        xhr.send();
    }

    // --- Initialize ---
    updateCountdown();
    countTimer = setInterval(updateCountdown, 1000);
    pollTimer  = setInterval(pollStatus, 15000);

    // First poll after 5 seconds
    setTimeout(pollStatus, 5000);
})();
</script>
HTML;

    return $html;
}

/**
 * Render success message for already-completed payments.
 *
 * @param array $payment
 * @param array $texts  Admin-customizable text strings
 * @return string
 */
function _payzcore_renderSuccess($payment, $texts = [])
{
    $amount    = htmlspecialchars($payment['paid_amount'] ?? $payment['expected_amount'] ?? '', ENT_QUOTES, 'UTF-8');
    $status    = htmlspecialchars($payment['status'], ENT_QUOTES, 'UTF-8');
    $txHash    = htmlspecialchars($payment['tx_hash'] ?? '', ENT_QUOTES, 'UTF-8');
    $network   = htmlspecialchars($payment['network'] ?? '', ENT_QUOTES, 'UTF-8');
    $tokenName = htmlspecialchars($payment['token'] ?? 'USDT', ENT_QUOTES, 'UTF-8');

    $confirmedText = htmlspecialchars(
        isset($texts['status_confirmed']) ? $texts['status_confirmed'] : 'Transfer confirmed!',
        ENT_QUOTES,
        'UTF-8'
    );

    $explorerUrl = _payzcore_getExplorerUrl($network, $txHash);

    $txLink = '';
    if ($explorerUrl) {
        $txLink = '<a href="' . htmlspecialchars($explorerUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
                . 'style="color:#06b6d4;text-decoration:underline;font-size:12px;word-break:break-all;">'
                . $txHash . '</a>';
    }

    return <<<HTML
<div style="
    max-width: 440px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
">
    <div style="
        background: #09090b;
        border: 1px solid rgba(6,182,212,0.3);
        border-radius: 12px;
        padding: 28px;
        text-align: center;
    ">
        <div style="font-size:48px;margin-bottom:12px;">&#10003;</div>
        <div style="font-size:20px;font-weight:700;color:#06b6d4;margin-bottom:4px;">
            {$confirmedText}
        </div>
        <div style="font-size:14px;color:#a1a1aa;margin-bottom:16px;">
            {$amount} {$tokenName} received
        </div>
        {$txLink}
    </div>
</div>
HTML;
}

/**
 * Get blockchain explorer URL for a transaction hash.
 *
 * @param string $network Network identifier (TRC20, BEP20, ERC20, POLYGON, ARBITRUM)
 * @param string $txHash  Transaction hash
 * @return string Explorer URL or empty string
 */
function _payzcore_getExplorerUrl($network, $txHash)
{
    if (empty($txHash)) {
        return '';
    }

    $explorers = [
        'TRC20'    => 'https://tronscan.org/#/transaction/',
        'BEP20'    => 'https://bscscan.com/tx/',
        'ERC20'    => 'https://etherscan.io/tx/',
        'POLYGON'  => 'https://polygonscan.com/tx/',
        'ARBITRUM' => 'https://arbiscan.io/tx/',
    ];

    $baseUrl = isset($explorers[$network]) ? $explorers[$network] : '';

    return $baseUrl ? $baseUrl . $txHash : '';
}
