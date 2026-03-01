<?php
/**
 * PayzCore Payment Status Polling Proxy
 *
 * Server-side proxy for payment status polling. The browser JavaScript
 * calls this local endpoint instead of the PayzCore API directly,
 * keeping the API key on the server side only.
 *
 * Security: validates that the requested payment_id belongs to an invoice
 * owned by the currently logged-in client via mod_payzcore_mappings.
 *
 * URL: https://yourdomain.com/modules/gateways/callback/payzcore_poll.php?payment_id=XXX
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

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
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

// ---------------------------------------------------------------------------
// Invoice ownership validation
// ---------------------------------------------------------------------------
// Verify the payment_id belongs to an invoice owned by the current session
// client. This prevents enumeration of arbitrary payment statuses.

$invoiceId = 0;

try {
    $mapping = Capsule::table('mod_payzcore_mappings')
        ->where('payment_id', $paymentId)
        ->first();

    if ($mapping) {
        $invoiceId = intval($mapping->invoice_id);
    }
} catch (\Exception $e) {
    // Table may not exist yet (pre-activation). Fall through to the
    // "not found" response below -- no data is leaked.
}

if ($invoiceId <= 0) {
    http_response_code(404);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Payment not found']));
}

// Check that the invoice belongs to the currently logged-in client.
// $_SESSION['uid'] is set by WHMCS when a client is authenticated.
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

// Check the module is active
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
// Forward request to PayzCore API (server-side, API key stays on server)
// ---------------------------------------------------------------------------

$api = new PayzCoreApi($apiUrl, $apiKey);

try {
    $payment = $api->getPayment($paymentId);

    // Return only the fields the browser needs (no sensitive data leakage)
    $safePayment = [
        'status'      => $payment['status'] ?? 'pending',
        'paid_amount' => $payment['paid_amount'] ?? '0',
    ];

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'payment' => $safePayment]);
} catch (PayzCoreApiException $e) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Upstream error']);
}
