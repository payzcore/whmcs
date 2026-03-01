<?php
/**
 * PayzCore Webhook Callback Handler
 *
 * Receives webhook notifications from the PayzCore blockchain monitoring API
 * and applies payment credits to the corresponding WHMCS invoices.
 * Supports multi-network (TRC20, BEP20, ERC20, Polygon, Arbitrum) and
 * multi-token (USDT, USDC) webhook payloads.
 *
 * Webhook URL: https://yourdomain.com/modules/gateways/callback/payzcore.php
 *
 * @package    PayzCore
 * @author     PayzCore <support@payzcore.com>
 * @copyright  2026 PayzCore
 * @license    MIT
 * @link       https://payzcore.com
 */

// Require WHMCS init
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../payzcore/PayzCoreApi.php';

use WHMCS\Database\Capsule;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Method not allowed']));
}

// Read raw body BEFORE any other processing
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Empty request body']));
}

// Get gateway configuration
$gatewayModuleName = 'payzcore';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// Check the module is active
if (!$gatewayParams['type']) {
    logTransaction($gatewayModuleName, $rawBody, 'Module Not Active');
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Gateway module not active']));
}

$webhookSecret = $gatewayParams['webhookSecret'];

// ---------------------------------------------------------------------------
// 1. Verify HMAC-SHA256 Signature
// ---------------------------------------------------------------------------

$signature = isset($_SERVER['HTTP_X_PAYZCORE_SIGNATURE'])
    ? $_SERVER['HTTP_X_PAYZCORE_SIGNATURE']
    : '';
$timestamp = isset($_SERVER['HTTP_X_PAYZCORE_TIMESTAMP'])
    ? $_SERVER['HTTP_X_PAYZCORE_TIMESTAMP']
    : '';

if (empty($signature)) {
    logTransaction($gatewayModuleName, $rawBody, 'Missing Signature');
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing signature']));
}

if (empty($timestamp)) {
    logTransaction($gatewayModuleName, $rawBody, 'Missing Timestamp');
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing timestamp']));
}

// Timestamp replay protection (±5 minutes)
$ts = strtotime($timestamp);
if ($ts === false || abs(time() - $ts) > 300) {
    logTransaction($gatewayModuleName, $rawBody, 'Timestamp Expired');
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Timestamp validation failed']));
}

// Signature covers timestamp + body
if (!PayzCoreApi::verifyWebhookSignature($rawBody, $signature, $webhookSecret, $timestamp)) {
    logTransaction($gatewayModuleName, $rawBody, 'Invalid Signature');
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid signature']));
}

// ---------------------------------------------------------------------------
// 2. Parse Webhook Payload
// ---------------------------------------------------------------------------

$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    logTransaction($gatewayModuleName, $rawBody, 'Invalid JSON');
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid JSON payload']));
}

$event          = isset($payload['event']) ? $payload['event'] : '';
$paymentId      = isset($payload['payment_id']) ? $payload['payment_id'] : '';
$externalRef    = isset($payload['external_ref']) ? $payload['external_ref'] : '';
$externalOrder  = isset($payload['external_order_id']) ? $payload['external_order_id'] : '';
$network        = isset($payload['network']) ? $payload['network'] : '';
$token          = isset($payload['token']) ? $payload['token'] : 'USDT';
$address        = isset($payload['address']) ? $payload['address'] : '';
$expectedAmount = isset($payload['expected_amount']) ? $payload['expected_amount'] : '0';
$paidAmount     = isset($payload['paid_amount']) ? $payload['paid_amount'] : '0';
$txHash         = isset($payload['tx_hash']) ? $payload['tx_hash'] : '';
$status         = isset($payload['status']) ? $payload['status'] : '';
$timestamp      = isset($payload['timestamp']) ? $payload['timestamp'] : '';

// ---------------------------------------------------------------------------
// 3. Extract WHMCS Invoice ID from external_order_id
// ---------------------------------------------------------------------------

// We set external_order_id as "WHMCS-{invoiceId}" when creating the monitoring request
$invoiceId = 0;

if (preg_match('/^WHMCS-(\d+)$/', $externalOrder, $matches)) {
    $invoiceId = intval($matches[1]);
}

if ($invoiceId <= 0) {
    // Try metadata fallback
    if (isset($payload['metadata']['invoiceId'])) {
        $invoiceId = intval($payload['metadata']['invoiceId']);
    }
}

if ($invoiceId <= 0) {
    logTransaction($gatewayModuleName, $rawBody, 'Cannot determine invoice ID');
    // Return 200 to prevent retries for unrelated webhooks
    http_response_code(200);
    header('Content-Type: application/json');
    die(json_encode(['ok' => true, 'note' => 'Invoice ID not found, event logged']));
}

// ---------------------------------------------------------------------------
// 4. Validate Invoice Exists
// ---------------------------------------------------------------------------

try {
    checkCbInvoiceID($invoiceId, $gatewayParams['name']);
} catch (\Exception $e) {
    logTransaction($gatewayModuleName, $rawBody, 'Invoice Not Found: ' . $invoiceId);
    http_response_code(200);
    header('Content-Type: application/json');
    die(json_encode(['ok' => true, 'note' => 'Invoice not found']));
}

// ---------------------------------------------------------------------------
// 5. Handle Events
// ---------------------------------------------------------------------------

$transactionId = $txHash ?: ('payzcore-' . $paymentId);

switch ($event) {
    case 'payment.completed':
    case 'payment.overpaid':
        // Check transaction not already recorded (idempotency)
        try {
            checkCbTransID($transactionId);
        } catch (\Exception $e) {
            // Transaction already recorded
            logTransaction($gatewayModuleName, $rawBody, 'Duplicate Transaction: ' . $transactionId);
            http_response_code(200);
            header('Content-Type: application/json');
            die(json_encode(['ok' => true, 'note' => 'Already processed']));
        }

        // Apply payment to invoice
        // Parameters: invoiceId, transactionId, amount, fee, gatewayModule
        // Fee is 0 - PayzCore charges a flat subscription, not per-transaction
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            floatval($paidAmount),
            0,
            $gatewayModuleName
        );

        // Update mapping status
        try {
            Capsule::table('mod_payzcore_mappings')
                ->where('invoice_id', $invoiceId)
                ->update(['status' => $status ?: 'paid']);
        } catch (\Exception $e) {
            // Non-critical: mapping table may not exist
        }

        $logMessage = ($event === 'payment.overpaid')
            ? 'Success (Overpaid: ' . $paidAmount . ' ' . $token . ' / expected ' . $expectedAmount . ' ' . $token . ')'
            : 'Success (' . $paidAmount . ' ' . $token . ' on ' . $network . ')';

        logTransaction($gatewayModuleName, $rawBody, $logMessage);

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['ok' => true, 'event' => $event, 'invoice_id' => $invoiceId]));
        break;

    case 'payment.partial':
        // Log partial payment without crediting the invoice
        logTransaction($gatewayModuleName, $rawBody, 'Partial Payment: ' . $paidAmount . ' ' . $token . ' / ' . $expectedAmount . ' ' . $token);

        // Update mapping status
        try {
            Capsule::table('mod_payzcore_mappings')
                ->where('invoice_id', $invoiceId)
                ->update(['status' => 'partial']);
        } catch (\Exception $e) {
            // Non-critical
        }

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['ok' => true, 'event' => $event, 'note' => 'Partial payment logged']));
        break;

    case 'payment.expired':
        // Log expiry - no action needed on the invoice
        logTransaction($gatewayModuleName, $rawBody, 'Expired: Invoice #' . $invoiceId);

        // Update mapping status
        try {
            Capsule::table('mod_payzcore_mappings')
                ->where('invoice_id', $invoiceId)
                ->update(['status' => 'expired']);
        } catch (\Exception $e) {
            // Non-critical
        }

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['ok' => true, 'event' => $event, 'note' => 'Expiry logged']));
        break;

    case 'payment.cancelled':
        // Log cancellation
        logTransaction($gatewayModuleName, $rawBody, 'Cancelled: Invoice #' . $invoiceId);

        // Update mapping status
        try {
            Capsule::table('mod_payzcore_mappings')
                ->where('invoice_id', $invoiceId)
                ->update(['status' => 'cancelled']);
        } catch (\Exception $e) {
            // Non-critical
        }

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['ok' => true, 'event' => $event, 'note' => 'Cancellation logged']));
        break;

    default:
        // Unknown event type - log and acknowledge
        logTransaction($gatewayModuleName, $rawBody, 'Unknown Event: ' . $event);

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['ok' => true, 'note' => 'Unknown event type logged']));
        break;
}
