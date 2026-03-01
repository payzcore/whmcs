<?php
/**
 * PayzCore WHMCS Hooks
 *
 * Optional hooks for enhanced integration:
 * - Displays blockchain transaction details on admin invoice view
 *   (supports TRC20, BEP20, ERC20, Polygon, Arbitrum explorers)
 * - Cleans up stored payment mappings when invoices are deleted
 *
 * @package    PayzCore
 * @author     PayzCore <support@payzcore.com>
 * @copyright  2026 PayzCore
 * @license    MIT
 * @link       https://payzcore.com
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

/**
 * Display PayzCore transaction details on the admin invoice page.
 *
 * Shows blockchain explorer links alongside standard WHMCS transaction info.
 * Uses mod_payzcore_mappings for reliable network/token lookup instead of
 * parsing gateway log entries.
 */
add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];

    // Check if this invoice was paid via PayzCore
    try {
        $transaction = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('gateway', 'payzcore')
            ->first();
    } catch (\Exception $e) {
        return '';
    }

    if (!$transaction || empty($transaction->transid)) {
        return '';
    }

    $txHash = htmlspecialchars($transaction->transid);

    // Skip placeholder transaction IDs (non-blockchain)
    if (strpos($txHash, 'payzcore-') === 0) {
        return '';
    }

    // Determine network from mod_payzcore_mappings (reliable, dedicated table)
    $network = 'TRC20'; // default fallback
    $token = 'USDT';
    try {
        $mapping = Capsule::table('mod_payzcore_mappings')
            ->where('invoice_id', $invoiceId)
            ->first();

        if ($mapping) {
            $network = $mapping->network ?: 'TRC20';
            $token = $mapping->token ?: 'USDT';
        }
    } catch (\Exception $e) {
        // Table may not exist (pre-activation); use defaults
    }

    // Build explorer URL
    $explorers = [
        'TRC20'    => ['https://tronscan.org/#/transaction/', 'Tronscan'],
        'BEP20'    => ['https://bscscan.com/tx/', 'BscScan'],
        'ERC20'    => ['https://etherscan.io/tx/', 'Etherscan'],
        'POLYGON'  => ['https://polygonscan.com/tx/', 'PolygonScan'],
        'ARBITRUM' => ['https://arbiscan.io/tx/', 'Arbiscan'],
    ];

    $explorerInfo = isset($explorers[$network]) ? $explorers[$network] : $explorers['TRC20'];
    $explorerUrl  = $explorerInfo[0] . $txHash;
    $explorerName = $explorerInfo[1];

    return '<div class="alert alert-info" style="margin-top:10px;">'
         . '<strong>PayzCore Transaction</strong><br>'
         . 'Network: ' . htmlspecialchars($network) . ' &middot; '
         . 'Token: ' . htmlspecialchars($token) . ' &middot; '
         . 'TX: <a href="' . htmlspecialchars($explorerUrl) . '" target="_blank" rel="noopener">'
         . $txHash . '</a> '
         . '(<a href="' . htmlspecialchars($explorerUrl) . '" target="_blank" rel="noopener">'
         . 'View on ' . $explorerName . '</a>)'
         . '</div>';
});

/**
 * Clean up PayzCore payment mappings when an invoice is deleted.
 *
 * Deletes the corresponding row from mod_payzcore_mappings directly
 * instead of scanning the gateway log table.
 */
add_hook('InvoiceDeleted', 1, function ($vars) {
    $invoiceId = intval($vars['invoiceid']);

    if ($invoiceId <= 0) {
        return;
    }

    try {
        Capsule::table('mod_payzcore_mappings')
            ->where('invoice_id', $invoiceId)
            ->delete();
    } catch (\Exception $e) {
        // Table may not exist (pre-activation) or other error; silently ignore
    }
});
