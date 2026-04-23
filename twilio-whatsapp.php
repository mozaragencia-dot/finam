<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $httpCode, bool $ok, string $status, string $errorMessage = '', string $twilioSid = ''): void
{
    http_response_code($httpCode);
    echo json_encode([
        'ok' => $ok,
        'status' => $status,
        'twilio_sid' => $twilioSid,
        'error_message' => $errorMessage,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function maskPhone(string $phone): string
{
    $normalized = preg_replace('/\s+/', '', $phone) ?? '';
    if ($normalized === '') {
        return '';
    }

    if (strlen($normalized) <= 6) {
        return $normalized;
    }

    return substr($normalized, 0, 5) . str_repeat('*', max(0, strlen($normalized) - 7)) . substr($normalized, -2);
}

function logTwilioDebug(string $stage, array $context): void
{
    error_log('[twilio-whatsapp] ' . $stage . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'method_not_allowed', 'Method not allowed');
}

$internalToken = trim((string)(getenv('APP_INTERNAL_TOKEN') ?: 'S1arkh8m'));
$accountSid = trim((string)(getenv('TWILIO_ACCOUNT_SID') ?: 'ACc2b7e43d9a7bbde49f774104528bcfca'));
$authToken = trim((string)(getenv('TWILIO_AUTH_TOKEN') ?: 'f961a3e056227a5bcf5a436072d2001c'));
$fromWhatsApp = trim((string)(getenv('TWILIO_WHATSAPP_FROM') ?: 'whatsapp:+56987591312'));

$missingEnv = [];
if ($internalToken === '') {
    $missingEnv[] = 'APP_INTERNAL_TOKEN';
}
if ($accountSid === '') {
    $missingEnv[] = 'TWILIO_ACCOUNT_SID';
}
if ($authToken === '') {
    $missingEnv[] = 'TWILIO_AUTH_TOKEN';
}
if ($fromWhatsApp === '') {
    $missingEnv[] = 'TWILIO_WHATSAPP_FROM';
}

if ($missingEnv !== []) {
    respond(500, false, 'config_error', 'Missing required environment variables: ' . implode(', ', $missingEnv));
}

$providedToken = trim((string)($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? ''));
if ($providedToken === '' || !hash_equals($internalToken, $providedToken)) {
    respond(401, false, 'unauthorized', 'Invalid internal token');
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    respond(400, false, 'invalid_json', 'Invalid JSON body');
}

$toPhone = trim((string)($payload['toPhone'] ?? ''));
$message = trim((string)($payload['message'] ?? ''));

if ($toPhone === '' || $message === '') {
    respond(422, false, 'validation_error', 'toPhone and message are required');
}

$normalizedTo = preg_replace('/\s+/', '', $toPhone) ?? '';
if (!preg_match('/^\+\d{8,15}$/', $normalizedTo)) {
    respond(422, false, 'validation_error', 'Invalid destination phone format');
}

$normalizedFrom = preg_replace('/\s+/', '', strtolower($fromWhatsApp)) ?? '';
if (!preg_match('/^whatsapp:\+\d{8,15}$/', $normalizedFrom)) {
    respond(500, false, 'config_error', 'Invalid TWILIO_WHATSAPP_FROM format. Expected whatsapp:+########');
}

if ($normalizedFrom === 'whatsapp:+14155238886') {
    respond(500, false, 'config_error', 'Twilio WhatsApp sender is still sandbox. Configure a production sender in TWILIO_WHATSAPP_FROM.');
}

$twilioUrl = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($accountSid));
$formData = http_build_query([
    'To' => 'whatsapp:' . $normalizedTo,
    'From' => $normalizedFrom,
    'Body' => $message,
], '', '&', PHP_QUERY_RFC3986);

$ch = curl_init($twilioUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $accountSid . ':' . $authToken,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_POSTFIELDS => $formData,
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false) {
    logTwilioDebug('curl_error', [
        'http_status' => $statusCode,
        'from' => maskPhone($normalizedFrom),
        'to' => maskPhone('whatsapp:' . $normalizedTo),
        'error' => $curlError,
    ]);
    respond(502, false, 'twilio_unreachable', $curlError !== '' ? $curlError : 'Twilio request failed');
}

$twilioBody = json_decode((string)$response, true);
$twilioSid = is_array($twilioBody) ? (string)($twilioBody['sid'] ?? '') : '';
$twilioErrorMessage = is_array($twilioBody) ? (string)($twilioBody['message'] ?? '') : '';
$twilioErrorCode = is_array($twilioBody) ? (string)($twilioBody['code'] ?? '') : '';

logTwilioDebug('response', [
    'http_status' => $statusCode,
    'from' => maskPhone($normalizedFrom),
    'to' => maskPhone('whatsapp:' . $normalizedTo),
    'response_body' => $twilioBody ?? $response,
]);

if ($statusCode < 200 || $statusCode >= 300) {
    $details = $twilioErrorMessage !== '' ? $twilioErrorMessage : 'Twilio API returned an error';
    if ($twilioErrorCode !== '') {
        $details .= ' (code ' . $twilioErrorCode . ')';
    }
    respond(502, false, 'twilio_error', $details);
}

respond(200, true, 'sent', '', $twilioSid);
