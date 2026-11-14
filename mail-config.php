<?php
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';

$gmailAddress = getenv('AGROGEAR_GMAIL_ADDRESS');
$gmailAppPassword = getenv('AGROGEAR_GMAIL_APP_PASSWORD');

if (!$gmailAddress || !$gmailAppPassword) {
    throw new RuntimeException(
        'Gmail delivery is not configured. Set AGROGEAR_GMAIL_ADDRESS and AGROGEAR_GMAIL_APP_PASSWORD in the server environment.'
    );
}

$mailConfig = [
    'host' => getenv('AGROGEAR_SMTP_HOST') ?: 'smtp.gmail.com',
    'port' => (int) (getenv('AGROGEAR_SMTP_PORT') ?: 587),
    'username' => $gmailAddress,
    'password' => $gmailAppPassword,
    'order_email' => getenv('AGROGEAR_ORDER_EMAIL') ?: $gmailAddress,
    'from_name' => 'Agrogear Orders',
];

function sendAgrogearEmail(
    array $config,
    string $recipient,
    string $subject,
    string $body,
    string $pdfAttachment
): void {
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $config['host'];
    $mailer->SMTPAuth = true;
    $mailer->Username = $config['username'];
    $mailer->Password = $config['password'];
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = $config['port'];
    $mailer->Timeout = 15;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->setFrom($config['username'], $config['from_name']);
    $mailer->addAddress($recipient);
    $mailer->Subject = $subject;
    $mailer->Body = $body;
    $mailer->addStringAttachment($pdfAttachment, 'Agrogear-Order.pdf', 'base64', 'application/pdf');
    $mailer->send();
}

return $mailConfig;
