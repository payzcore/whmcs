{*
 * PayzCore Payment Template (Smarty)
 *
 * Alternative template for WHMCS themes that support custom gateway templates.
 * This provides the same UI as the inline HTML in payzcore_link() but in
 * Smarty template format for easier theme customization.
 *
 * Variables available:
 *   $payment_id   - PayzCore payment UUID
 *   $address      - Blockchain deposit address
 *   $amount       - Amount in stablecoin (with unique cents)
 *   $network      - TRC20, BEP20, ERC20, POLYGON, or ARBITRUM
 *   $network_label - Human-readable network name (e.g., "Tron (TRC20)")
 *   $token_name   - Token symbol (e.g., "USDT" or "USDC")
 *   $qr_code      - Base64 QR code data URI
 *   $expires_at   - ISO 8601 expiry timestamp
 *   $status       - Current payment status
 *   $api_url      - PayzCore API URL (unused, kept for backward compat)
 *   $invoice_id   - WHMCS invoice ID
 *   $test_mode    - Boolean test mode flag
 *}

<div id="payzcore-payment-container" style="
    max-width: 440px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #e4e4e7;
">

    {if $test_mode}
    <div style="background:#92400e;color:#fbbf24;padding:8px 16px;border-radius:6px;font-size:12px;margin-bottom:16px;text-align:center;">
        TEST MODE - Monitoring is active but logging is verbose
    </div>
    {/if}

    <div style="
        background: #09090b;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 28px;
    ">
        {* Header *}
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
                {$network_label|escape:'html'}
            </div>
            <div style="font-size:28px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">
                {$amount|escape:'html'} <span style="color:#06b6d4;">{$token_name|escape:'html'|default:'USDT'}</span>
            </div>
            <div style="font-size:13px;color:#71717a;margin-top:4px;">
                Send exactly this amount to the address below
            </div>
        </div>

        {* QR Code *}
        {if $qr_code}
        <div style="text-align:center;margin-bottom:20px;">
            <img src="{$qr_code}" alt="Payment QR Code"
                 style="width:200px;height:200px;border-radius:8px;background:#000;" />
        </div>
        {/if}

        {* Address *}
        <div style="margin-bottom:20px;">
            <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                Deposit Address
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
            " onclick="payzCoreCopyAddress()" title="Click to copy">
                {$address|escape:'html'}
            </div>
            <div id="payzcore-copy-feedback" style="font-size:11px;color:#06b6d4;margin-top:4px;text-align:center;opacity:0;transition:opacity 0.3s;">
                Address copied!
            </div>
        </div>

        {* Status *}
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
                        Waiting for transfer...
                    </div>
                </div>
                <div id="payzcore-spinner" style="
                    width:24px;height:24px;
                    border:2px solid rgba(6,182,212,0.2);
                    border-top-color:#06b6d4;
                    border-radius:50%;
                    animation:payzcore-spin 0.8s linear infinite;
                "></div>
            </div>
        </div>

        {* Countdown *}
        <div style="text-align:center;margin-bottom:12px;">
            <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">
                Time Remaining
            </div>
            <div id="payzcore-countdown" style="font-size:22px;font-weight:700;color:#06b6d4;font-variant-numeric:tabular-nums;">
                --:--
            </div>
        </div>

        {* Footer *}
        <div style="text-align:center;font-size:11px;color:#52525b;border-top:1px solid rgba(255,255,255,0.05);padding-top:16px;margin-top:8px;">
            Send only <strong>{$token_name|escape:'html'|default:'USDT'}</strong> on <strong>{$network_label|escape:'html'}</strong> to this address.<br>
            Sending other tokens or using the wrong network may result in loss.
        </div>
    </div>
</div>

<style>
@keyframes payzcore-spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
(function() {
    var paymentId  = '{$payment_id|escape:'javascript'}';
    var expiresAt  = new Date('{$expires_at|escape:'javascript'}').getTime();
    var pollUrl    = '../modules/gateways/callback/payzcore_poll.php?payment_id=' + encodeURIComponent(paymentId);
    var address    = '{$address|escape:'javascript'}';
    var tokenName  = '{$token_name|escape:'javascript'|default:'USDT'}';

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
        if (fb) { fb.style.opacity = '1'; setTimeout(function() { fb.style.opacity = '0'; }, 2000); }
    };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function updateCountdown() {
        var diff = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
        var el = document.getElementById('payzcore-countdown');
        if (!el) return;
        if (diff <= 0) {
            el.textContent = 'EXPIRED';
            el.style.color = '#ef4444';
            clearInterval(countTimer);
            clearInterval(pollTimer);
            return;
        }
        var h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
        el.textContent = h > 0 ? pad(h) + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
        if (diff < 300) el.style.color = '#ef4444';
    }

    function updateStatus(status, label) {
        var el = document.getElementById('payzcore-status-label');
        var sp = document.getElementById('payzcore-spinner');
        if (!el) return;
        var colors = { pending:'#f59e0b', confirming:'#3b82f6', partial:'#f59e0b', paid:'#06b6d4', overpaid:'#06b6d4', expired:'#ef4444', cancelled:'#ef4444' };
        el.textContent = label || status;
        el.style.color = colors[status] || '#71717a';
        if (sp && (status === 'paid' || status === 'overpaid' || status === 'expired' || status === 'cancelled')) sp.style.display = 'none';
    }

    function pollStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', pollUrl, true);
        xhr.timeout = 15000;
        xhr.onload = function() {
            if (xhr.status !== 200) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.success || !data.payment) return;
                var p = data.payment;
                if (p.status === 'paid' || p.status === 'overpaid') {
                    updateStatus(p.status, 'Transfer confirmed!');
                    clearInterval(pollTimer); clearInterval(countTimer);
                    setTimeout(function() { window.location.reload(); }, 3000);
                } else if (p.status === 'confirming') {
                    updateStatus('confirming', 'Transfer detected, confirming...');
                } else if (p.status === 'partial') {
                    updateStatus('partial', 'Partial transfer (' + p.paid_amount + ' ' + tokenName + ')');
                } else if (p.status === 'expired') {
                    updateStatus('expired', 'Monitoring window expired');
                    clearInterval(pollTimer);
                }
            } catch(e) {}
        };
        xhr.send();
    }

    updateCountdown();
    var countTimer = setInterval(updateCountdown, 1000);
    var pollTimer  = setInterval(pollStatus, 15000);
    setTimeout(pollStatus, 5000);
})();
</script>
