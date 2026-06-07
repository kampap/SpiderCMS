<?php
require_once __DIR__ . '/config.php';

$messages_file = __DIR__ . '/.contact_messages.json';
$redirect_ok = $_POST['redirect_ok'] ?? ($_SERVER['HTTP_REFERER'] ?? '/');
$redirect_error = $_POST['redirect_error'] ?? ($_SERVER['HTTP_REFERER'] ?? '/');

function cms_contact_clean($value, $max = 2000) {
    $value = trim((string)$value);
    $value = strip_tags($value);
    $value = preg_replace('/[\r\n]+/', "\n", $value);
    return mb_substr($value, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

if (!empty($_POST['website'])) {
    header('Location: ' . $redirect_ok);
    exit;
}

$name = cms_contact_clean($_POST['name'] ?? '', 120);
$email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone = cms_contact_clean($_POST['phone'] ?? '', 60);
$subject = cms_contact_clean($_POST['subject'] ?? 'Wiadomość ze strony', 160);
$message = cms_contact_clean($_POST['message'] ?? '', 4000);
$consent = !empty($_POST['consent']);

if ($name === '' || !$email || $message === '' || !$consent) {
    header('Location: ' . $redirect_error . (strpos($redirect_error, '?') === false ? '?contact=error' : '&contact=error'));
    exit;
}

$messages = file_exists($messages_file) ? json_decode(file_get_contents($messages_file), true) : [];
if (!is_array($messages)) $messages = [];

$messages[] = [
    'id' => bin2hex(random_bytes(8)),
    'created_at' => date('Y-m-d H:i:s'),
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'subject' => $subject,
    'message' => $message,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'status' => 'new'
];

file_put_contents($messages_file, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@chmod($messages_file, 0600);

header('Location: ' . $redirect_ok . (strpos($redirect_ok, '?') === false ? '?contact=ok' : '&contact=ok'));
exit;