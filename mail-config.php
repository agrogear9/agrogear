<?php
$senderEmail = getenv('AGROGEAR_EMAIL_SENDER') ?: getenv('AGROGEAR_GMAIL_ADDRESS');
$brevoApiKey = getenv('AGROGEAR_BREVO_API_KEY');

if (!$senderEmail || !$brevoApiKey) {
    throw new RuntimeException(
        'Email delivery is not configured. Set AGROGEAR_EMAIL_SENDER and AGROGEAR_BREVO_API_KEY in the server environment.'
    );
}

$mailConfig = [
    'api_key' => $brevoApiKey,
    'sender_email' => $senderEmail,
    'order_email' => getenv('AGROGEAR_ORDER_EMAIL') ?: $senderEmail,
    'from_name' => 'Agrogear Orders',
];

function sendAgrogearEmail(
    array $config,
    string $recipient,
    string $subject,
    string $body,
    string $pdfAttachment
): void {
    $payload = json_encode([
        'sender' => [
            'name' => $config['from_name'],
            'email' => $config['sender_email'],
        ],
        'to' => [
            ['email' => $recipient],
        ],
        'subject' => $subject,
        'textContent' => $body,
        'attachment' => [
            [
                'name' => 'Agrogear-Order.pdf',
                'content' => base64_encode($pdfAttachment),
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Content-Type: application/json',
                'api-key: ' . $config['api_key'],
            ]),
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    if ($response === false) {
        throw new RuntimeException('Brevo email API request failed because the service could not be reached.');
    }

    $statusCode = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
            $statusCode = (int) $matches[1];
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $errorMessage = 'Brevo email API returned HTTP ' . $statusCode . '.';
        $errorResponse = json_decode($response, true);
        if (is_array($errorResponse) && is_string($errorResponse['message'] ?? null)) {
            $errorMessage .= ' ' . $errorResponse['message'];
        }
        throw new RuntimeException($errorMessage);
    }
}

return $mailConfig;
