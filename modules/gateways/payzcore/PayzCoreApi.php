<?php
/**
 * PayzCore API Client
 *
 * Communicates with the PayzCore blockchain monitoring API.
 * Uses cURL for HTTP requests with proper timeout and error handling.
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

/**
 * Custom exception for PayzCore API errors.
 */
class PayzCoreApiException extends \Exception
{
}

/**
 * PayzCore API client.
 *
 * Provides methods to create and retrieve payment monitoring requests
 * from the PayzCore blockchain monitoring API.
 */
class PayzCoreApi
{
    /** @var string API base URL (no trailing slash) */
    private $baseUrl;

    /** @var string Project API key */
    private $apiKey;

    /** @var int Request timeout in seconds */
    private $timeout;

    /**
     * @param string $baseUrl API base URL (e.g., https://api.payzcore.com)
     * @param string $apiKey  Project API key (pk_live_xxx)
     * @param int    $timeout Request timeout in seconds (default: 30)
     */
    public function __construct($baseUrl, $apiKey, $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Create a payment monitoring request.
     *
     * @param array $params {
     *     @type float  $amount            Payment amount in stablecoin
     *     @type string $network           Blockchain network (TRC20, BEP20, ERC20, POLYGON, ARBITRUM)
     *     @type string $token             Token to monitor (USDT or USDC, default: USDT)
     *     @type string $external_ref      Client reference identifier
     *     @type string $external_order_id Order reference (e.g., WHMCS-123)
     *     @type int    $expires_in        Expiry time in seconds (300-86400)
     *     @type array  $metadata          Optional metadata key-value pairs
     * }
     *
     * @return array Decoded API response containing payment details
     *
     * @throws PayzCoreApiException On API or network errors
     */
    public function createPayment(array $params)
    {
        $response = $this->request('POST', '/v1/payments', $params);

        if (!isset($response['success']) || $response['success'] !== true) {
            $error = $response['error'] ?? 'Unknown API error';
            throw new PayzCoreApiException('Failed to create monitoring request: ' . $error);
        }

        return $response;
    }

    /**
     * Get payment monitoring status.
     *
     * Performs a real-time blockchain check for pending payments.
     *
     * @param string $paymentId Payment UUID
     *
     * @return array Payment details including current status and transactions
     *
     * @throws PayzCoreApiException On API or network errors
     */
    public function getPayment($paymentId)
    {
        $paymentId = preg_replace('/[^a-zA-Z0-9\-]/', '', $paymentId);
        $response  = $this->request('GET', '/v1/payments/' . $paymentId);

        if (!isset($response['success']) || $response['success'] !== true) {
            $error = $response['error'] ?? 'Unknown API error';
            throw new PayzCoreApiException('Failed to get payment status: ' . $error);
        }

        return $response['payment'];
    }

    /**
     * Submit a transaction hash to confirm a payment (static wallet mode).
     *
     * @param string $paymentId   Payment UUID
     * @param string $txHash      Blockchain transaction hash
     * @param string $confirmPath API endpoint path for confirmation
     *
     * @return array Decoded API response
     *
     * @throws PayzCoreApiException On API or network errors
     */
    public function confirmPayment($paymentId, $txHash, $confirmPath = '')
    {
        if (empty($confirmPath)) {
            $paymentId   = preg_replace('/[^a-zA-Z0-9\-]/', '', $paymentId);
            $confirmPath = '/v1/payments/' . $paymentId . '/confirm';
        }

        $response = $this->request('POST', $confirmPath, [
            'tx_hash' => $txHash,
        ]);

        return $response;
    }

    /**
     * Verify a webhook signature using HMAC-SHA256.
     *
     * The signature covers `timestamp + "." + body` to bind the timestamp
     * to the payload and prevent replay attacks with modified timestamps.
     *
     * @param string $rawBody       Raw request body
     * @param string $signature     Value from X-PayzCore-Signature header
     * @param string $webhookSecret Webhook signing secret
     * @param string $timestamp     Value from X-PayzCore-Timestamp header
     *
     * @return bool True if signature is valid
     */
    public static function verifyWebhookSignature($rawBody, $signature, $webhookSecret, $timestamp = '')
    {
        if (empty($rawBody) || empty($signature) || empty($webhookSecret) || empty($timestamp)) {
            return false;
        }

        // Signature covers timestamp + body
        $message = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $message, $webhookSecret);

        // Timing-safe comparison to prevent timing attacks
        return hash_equals($expected, $signature);
    }

    /**
     * Send an HTTP request to the PayzCore API.
     *
     * @param string     $method HTTP method (GET, POST)
     * @param string     $path   API endpoint path
     * @param array|null $body   Request body for POST requests
     *
     * @return array Decoded JSON response
     *
     * @throws PayzCoreApiException On network or API errors
     */
    private function request($method, $path, array $body = null)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init();

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PayzCore-WHMCS/1.0.0',
        ]);

        if ($method === 'POST') {
            $jsonBody = json_encode($body);

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);

            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonBody);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrno    = curl_errno($ch);

        curl_close($ch);

        // Network-level error
        if ($curlErrno !== 0) {
            throw new PayzCoreApiException(
                'Network error communicating with PayzCore API: ' . $curlError,
                $curlErrno
            );
        }

        // Decode response
        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PayzCoreApiException(
                'Invalid JSON response from PayzCore API (HTTP ' . $httpCode . ')',
                $httpCode
            );
        }

        // HTTP error
        if ($httpCode >= 400) {
            $errorMsg = $decoded['error'] ?? ('HTTP ' . $httpCode);
            throw new PayzCoreApiException(
                'PayzCore API error: ' . $errorMsg,
                $httpCode
            );
        }

        return $decoded;
    }
}
