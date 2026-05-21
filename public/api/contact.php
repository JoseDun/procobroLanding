<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_string($value, int $maxLength): string
{
    $value = is_string($value) ? trim($value) : '';
    $value = preg_replace('/[^\P{C}\n\t]+/u', '', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function clean_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function require_config(): array
{
    $configPath = __DIR__ . '/contact.config.php';

    if (!is_file($configPath)) {
        json_response(['error' => 'El formulario no está configurado.'], 500);
    }

    $config = require $configPath;

    if (!is_array($config)) {
        json_response(['error' => 'La configuración del formulario no es válida.'], 500);
    }

    foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'to_email'] as $key) {
        if (empty($config[$key])) {
            json_response(['error' => 'La configuración SMTP está incompleta.'], 500);
        }
    }

    return $config;
}

function phpmailer_is_available(): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
    } else {
        $manualBase = __DIR__ . '/PHPMailer/src';
        $manualFiles = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];
        $hasManualInstall = true;

        foreach ($manualFiles as $file) {
            if (!is_file($manualBase . '/' . $file)) {
                $hasManualInstall = false;
                break;
            }
        }

        if ($hasManualInstall) {
            foreach ($manualFiles as $file) {
                require_once $manualBase . '/' . $file;
            }
        }
    }

    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function national_chilean_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';

    if (substr($digits, 0, 2) === '56' && strlen($digits) === 11) {
        return substr($digits, 2);
    }

    if (substr($digits, 0, 1) === '0' && strlen($digits) === 10) {
        return substr($digits, 1);
    }

    return strlen($digits) === 9 ? $digits : '';
}

function is_valid_chilean_phone(string $value): bool
{
    $national = national_chilean_phone($value);

    if ($national === '') {
        return false;
    }

    $regionalCodes = '32|33|34|35|41|42|43|44|45|51|52|53|55|57|58|61|63|64|65|67|71|72|73|75';

    return (bool) preg_match('/^9[1-9]\d{7}$/', $national)
        || (bool) preg_match('/^2\d{8}$/', $national)
        || (bool) preg_match('/^(' . $regionalCodes . ')\d{7}$/', $national);
}

function html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(clean_header_value($value)) . '?=';
}

function format_mailbox(string $email, string $name = ''): string
{
    $email = clean_header_value($email);
    $name = clean_header_value($name);

    if ($name === '') {
        return '<' . $email . '>';
    }

    return mime_header($name) . ' <' . $email . '>';
}

function normalize_mail_body(string $body): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
}

function dot_stuff_message(string $message): string
{
    $message = normalize_mail_body($message);
    return preg_replace('/^\./m', '..', $message) ?? $message;
}

function build_mime_message(array $mailData): string
{
    $boundary = 'procobro_' . bin2hex(random_bytes(12));

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . format_mailbox($mailData['from_email'], $mailData['from_name']),
        'To: ' . format_mailbox($mailData['to_email'], $mailData['to_name']),
        'Reply-To: ' . format_mailbox($mailData['reply_to_email'], $mailData['reply_to_name']),
        'Subject: ' . mime_header($mailData['subject']),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: Procobro Contact Form',
    ];

    return implode("\r\n", $headers)
        . "\r\n\r\n--" . $boundary
        . "\r\nContent-Type: text/plain; charset=UTF-8"
        . "\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(normalize_mail_body($mailData['text_body']))
        . "\r\n\r\n--" . $boundary
        . "\r\nContent-Type: text/html; charset=UTF-8"
        . "\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(normalize_mail_body($mailData['html_body']))
        . "\r\n\r\n--" . $boundary . "--\r\n";
}

function smtp_write($socket, string $data): void
{
    $length = strlen($data);
    $written = 0;

    while ($written < $length) {
        $result = fwrite($socket, substr($data, $written));

        if ($result === false || $result === 0) {
            throw new RuntimeException('No se pudo escribir en la conexión SMTP.');
        }

        $written += $result;
    }
}

function smtp_read_response($socket): array
{
    $response = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^(\d{3})([\s-])/', $line, $matches)) {
            $code = (int) $matches[1];

            if ($matches[2] === ' ') {
                return [$code, trim($response)];
            }
        }
    }

    $meta = stream_get_meta_data($socket);
    if (!empty($meta['timed_out'])) {
        throw new RuntimeException('Tiempo de espera agotado con el servidor SMTP.');
    }

    throw new RuntimeException('Respuesta SMTP incompleta.');
}

function smtp_command($socket, string $command, array $expectedCodes, string $context): string
{
    smtp_write($socket, $command . "\r\n");
    return smtp_expect_response($socket, $expectedCodes, $context);
}

function smtp_expect_response($socket, array $expectedCodes, string $context): string
{
    [$code, $response] = smtp_read_response($socket);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException($context . ' falló: ' . $response);
    }

    return $response;
}

function send_with_native_smtp(array $config, array $mailData): void
{
    $host = (string) $config['smtp_host'];
    $port = (int) $config['smtp_port'];
    $secure = strtolower((string) ($config['smtp_secure'] ?? ''));

    if ($secure === '' && $port === 465) {
        $secure = 'ssl';
    } elseif ($secure === '' && $port === 587) {
        $secure = 'tls';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr);
    }

    try {
        stream_set_timeout($socket, 20);
        smtp_expect_response($socket, [220], 'Conexión SMTP');

        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $serverName = preg_replace('/[^A-Za-z0-9.-]/', '', $serverName) ?: 'localhost';

        try {
            smtp_command($socket, 'EHLO ' . $serverName, [250], 'EHLO');
        } catch (RuntimeException $error) {
            smtp_command($socket, 'HELO ' . $serverName, [250], 'HELO');
        }

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', [220], 'STARTTLS');

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar TLS con el servidor SMTP.');
            }

            smtp_command($socket, 'EHLO ' . $serverName, [250], 'EHLO después de STARTTLS');
        }

        smtp_command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        smtp_command($socket, base64_encode((string) $config['smtp_user']), [334], 'Usuario SMTP');
        smtp_command($socket, base64_encode((string) $config['smtp_pass']), [235], 'Clave SMTP');
        smtp_command($socket, 'MAIL FROM:<' . $mailData['from_email'] . '>', [250], 'MAIL FROM');
        smtp_command($socket, 'RCPT TO:<' . $mailData['to_email'] . '>', [250, 251], 'RCPT TO');
        smtp_command($socket, 'DATA', [354], 'DATA');

        smtp_write($socket, dot_stuff_message(build_mime_message($mailData)) . "\r\n.\r\n");
        smtp_expect_response($socket, [250], 'Entrega SMTP');

        try {
            smtp_command($socket, 'QUIT', [221], 'QUIT');
        } catch (RuntimeException $error) {
            // La entrega ya fue aceptada; no fallamos por un cierre ruidoso.
        }
    } finally {
        fclose($socket);
    }
}

function send_with_phpmailer(array $config, array $mailData): void
{
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['smtp_user'];
    $mail->Password = (string) $config['smtp_pass'];
    $mail->Port = (int) $config['smtp_port'];

    $smtpSecure = strtolower((string) ($config['smtp_secure'] ?? ''));
    if ($smtpSecure === 'ssl' || ($smtpSecure === '' && $mail->Port === 465)) {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($smtpSecure === 'tls' || ($smtpSecure === '' && $mail->Port === 587)) {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($mailData['from_email'], $mailData['from_name']);
    $mail->addAddress($mailData['to_email'], $mailData['to_name']);
    $mail->addReplyTo($mailData['reply_to_email'], $mailData['reply_to_name']);
    $mail->Subject = $mailData['subject'];
    $mail->isHTML(true);
    $mail->Body = $mailData['html_body'];
    $mail->AltBody = $mailData['text_body'];
    $mail->send();
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($requestMethod !== 'POST') {
    json_response(['error' => 'Método no permitido.'], 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 20000) {
    json_response(['error' => 'La solicitud es demasiado grande.'], 413);
}

$rawInput = file_get_contents('php://input') ?: '';
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    json_response(['error' => 'La solicitud no tiene un formato válido.'], 400);
}

if (!empty($data['website'])) {
    json_response(['message' => 'Solicitud recibida.']);
}

$fields = [
    'name' => clean_string($data['name'] ?? '', 100),
    'company' => clean_string($data['company'] ?? '', 120),
    'email' => clean_string($data['email'] ?? '', 180),
    'phone' => clean_string($data['phone'] ?? '', 40),
    'service' => clean_string($data['service'] ?? '', 40),
    'message' => clean_string($data['message'] ?? '', 3000),
];

foreach ($fields as $value) {
    if ($value === '') {
        json_response(['error' => 'Todos los campos son obligatorios.'], 400);
    }
}

if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Ingresa un email válido.'], 400);
}

if (!is_valid_chilean_phone($fields['phone'])) {
    json_response(['error' => 'Ingresa un teléfono chileno válido.'], 400);
}

$validServices = ['cobranza', 'prejudicial', 'judicial', 'danos', 'defensas', 'siniestros', 'telemarketing', 'atencion', 'otro'];
if (!in_array($fields['service'], $validServices, true)) {
    json_response(['error' => 'Selecciona un servicio válido.'], 400);
}

$config = require_config();

$serviceLabels = [
    'cobranza' => 'Cobranza',
    'prejudicial' => 'Prejudicial',
    'judicial' => 'Judicial',
    'danos' => 'Daños',
    'defensas' => 'Defensas',
    'siniestros' => 'Recuperación de Siniestros',
    'telemarketing' => 'Telemarketing',
    'atencion' => 'Atención al Cliente',
    'otro' => 'Otro',
];

$fromEmail = clean_header_value((string) ($config['from_email'] ?? $config['smtp_user']));
$toEmail = clean_header_value((string) $config['to_email']);

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'La configuración de correos no es válida.'], 500);
}

$subjectPrefix = clean_string($config['subject_prefix'] ?? 'Nuevo contacto desde Procobro', 120);
$subject = $subjectPrefix . ': ' . $fields['name'];

$textBody = implode("\n", [
    'Nuevo mensaje recibido desde el formulario de Procobro.',
    '',
    'Nombre: ' . $fields['name'],
    'Empresa: ' . $fields['company'],
    'Email: ' . $fields['email'],
    'Teléfono: ' . $fields['phone'],
    'Servicio: ' . ($serviceLabels[$fields['service']] ?? $fields['service']),
    '',
    'Mensaje:',
    $fields['message'],
]);

$htmlBody = '<h2>Nuevo mensaje desde Procobro</h2>'
    . '<p><strong>Nombre:</strong> ' . html_escape($fields['name']) . '</p>'
    . '<p><strong>Empresa:</strong> ' . html_escape($fields['company']) . '</p>'
    . '<p><strong>Email:</strong> ' . html_escape($fields['email']) . '</p>'
    . '<p><strong>Teléfono:</strong> ' . html_escape($fields['phone']) . '</p>'
    . '<p><strong>Servicio:</strong> ' . html_escape($serviceLabels[$fields['service']] ?? $fields['service']) . '</p>'
    . '<p><strong>Mensaje:</strong><br>' . nl2br(html_escape($fields['message'])) . '</p>';

$mailData = [
    'from_email' => $fromEmail,
    'from_name' => clean_header_value((string) ($config['from_name'] ?? 'Procobro')),
    'to_email' => $toEmail,
    'to_name' => clean_header_value((string) ($config['to_name'] ?? 'Procobro')),
    'reply_to_email' => $fields['email'],
    'reply_to_name' => $fields['name'],
    'subject' => $subject,
    'text_body' => $textBody,
    'html_body' => $htmlBody,
];

try {
    if (phpmailer_is_available()) {
        send_with_phpmailer($config, $mailData);
    } else {
        send_with_native_smtp($config, $mailData);
    }

    json_response(['message' => 'Gracias por contactarnos. Nos comunicaremos pronto.']);
} catch (Throwable $error) {
    error_log('Error enviando formulario Procobro: ' . $error->getMessage());
    json_response(['error' => 'No pudimos enviar el formulario.'], 500);
}
