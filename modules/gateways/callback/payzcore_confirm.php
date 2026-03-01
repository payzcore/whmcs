<?php
/**
 * PayzCore TX Hash Confirmation Proxy
 *
 * Server-side proxy for submitting transaction hashes to the PayzCore API.
 * Used in static wallet mode when the API response includes requires_txid=true.
 * The browser JavaScript calls this local endpoint instead of the PayzCore API
 * directly, keeping the API key on the server side only.
 *
 * Security: validates that the requested payment_id belongs to an invoice
 * owned by the currently logged-in client via mod_payzcore_mappings.
 *
 * URL: POST https://yourdomain.com/modules/gateways/callback/payzcore_confirm.php?payment_id=XXX
 * Body: {"tx_hash": "abc123..."}
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
require_once __DIR__ . '/../payzcore/PayzCoreApi.php';

use WHMCS\Database\Capsule;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Method not allowed']));
}

// Validate payment_id parameter
$paymentId = isset($_GET['payment_id']) ? trim($_GET['payment_id']) : '';

if (empty($paymentId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing payment_id']));
}

// Sanitize payment_id (UUID format)
$paymentId = preg_replace('/[^a-zA-Z0-9\-]/', '', $paymentId);

if (empty($paymentId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid payment_id']));
}

// Parse request body
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid JSON body']));
}

$txHash = isset($body['tx_hash']) ? trim($body['tx_hash']) : '';

if (empty($txHash)) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing tx_hash']));
}

// Sanitize tx_hash (hex string, possibly with 0x prefix)
$txHash = preg_replace('/[^a-fA-F0-9x]/', '', $txHash);

if (empty($txHash) || strlen($txHash) < 10) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid tx_hash format']));
}

// ---------------------------------------------------------------------------
// Invoice ownership validation
// ---------------------------------------------------------------------------

$invoiceId       = 0;
$confirmEndpoint = '';

try {
    $mapping = Capsule::table('mod_payzcore_mappings')
        ->where('payment_id', $paymentId)
        ->first();

    if ($mapping) {
        $invoiceId       = intval($mapping->invoice_id);
        $confirmEndpoint = $mapping->confirm_endpoint ?? '';
    }
} catch (\Exception $e) {
    // Table may not exist
}

if ($invoiceId <= 0) {
    http_response_code(404);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Payment not found']));
}

// Check that the invoice belongs to the currently logged-in client
$currentClientId = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;

if ($currentClientId <= 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Authentication required']));
}

try {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first();

    if (!$invoice || intval($invoice->userid) !== $currentClientId) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Access denied']));
    }
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Validation error']));
}

// ---------------------------------------------------------------------------
// Gateway configuration
// ---------------------------------------------------------------------------

$gatewayModuleName = 'payzcore';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Gateway module not active']));
}

$apiUrl = rtrim($gatewayParams['apiUrl'], '/');
$apiKey = $gatewayParams['apiKey'];

if (empty($apiUrl) || empty($apiKey)) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Gateway not configured']));
}

// ---------------------------------------------------------------------------
// Forward tx_hash to PayzCore API confirm endpoint
// ---------------------------------------------------------------------------

// Use stored confirm_endpoint, or fall back to default path
$confirmPath = !empty($confirmEndpoint)
    ? $confirmEndpoint
    : '/v1/payments/' . $paymentId . '/confirm';

$api = new PayzCoreApi($apiUrl, $apiKey);

try {
    $response = $api->confirmPayment($paymentId, $txHash, $confirmPath);

    logTransaction($gatewayModuleName, [
        'action'     => 'confirm_txid',
        'payment_id' => $paymentId,
        'tx_hash'    => $txHash,
        'invoice_id' => $invoiceId,
    ], 'TX hash submitted');

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Transaction hash submitted']);
} catch (PayzCoreApiException $e) {
    logTransaction($gatewayModuleName, [
        'action'     => 'confirm_txid_error',
        'payment_id' => $paymentId,
        'tx_hash'    => $txHash,
        'error'      => $e->getMessage(),
    ], 'TX hash submission failed');

    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to submit transaction hash']);
}
