<?php
// ======================================================================
// public/admin.php
// SpiderCMS – Panel administracyjny
// Dostosowano styl wizualny do mrocznego, neonowego logo systemu
// Dodano wyświetlanie logo przy napisach SpiderCMS (Sidebar oraz Ekran Logowania)
// Naprawiono strukturę formularzy (zapis ustawień oraz eksport ZIP działają niezależnie)
// NAPRAWIONO: Dodano pełną obsługę, formularz oraz dynamiczny szablon generowania stopki z poprawną ścieżką
// DYNAMICZNA STOPKA: Zapis stopki aktualizuje teraz globalny plik footer.php oraz automatycznie naprawia istniejące podstrony!
// Dodano: Zmianę hasła administratora z poziomu zakładki Ustawienia
// Dodano: Czat użytkownika strony z administratorem, bez bazy danych, zapis w plikach JSON
// Wersja: czerwiec 2026
// ======================================================================

session_start();


// ----------------------------------------------------------------------
// PODSTAWOWE ZABEZPIECZENIA PRODUKCYJNE
// ----------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_or_die() {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Błąd bezpieczeństwa: nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spidercms_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function spidercms_write_htaccess($dir, $content) {
    if (!is_dir($dir)) return;
    $file = rtrim($dir, '/\\') . '/.htaccess';
    if (!file_exists($file)) {
        @file_put_contents($file, $content);
    }
}


// ----------------------------------------------------------------------
// SYSTEM LOGÓW – rejestrowanie akcji administratora
// ----------------------------------------------------------------------
define('SPIDERCMS_LOG_DIR', __DIR__ . '/.logs');
define('SPIDERCMS_ACTION_LOG_FILE', SPIDERCMS_LOG_DIR . '/admin-actions.jsonl');

function spidercms_logs_bootstrap() {
    if (!is_dir(SPIDERCMS_LOG_DIR)) {
        @mkdir(SPIDERCMS_LOG_DIR, 0755, true);
    }
    spidercms_write_htaccess(SPIDERCMS_LOG_DIR, "Require all denied\nDeny from all\nOptions -Indexes\n");
}

function spidercms_log_sanitize_context(array $context) {
    $blocked = ['password','new_password','repeat_password','smtp_password','csrf_token','content','page_content','message','body'];
    $safe = [];
    foreach ($context as $key => $value) {
        $key_l = strtolower((string)$key);
        if (in_array($key_l, $blocked, true) || str_contains($key_l, 'password') || str_contains($key_l, 'token')) continue;
        if (is_array($value)) {
            $safe[$key] = '[array:' . count($value) . ']';
        } else {
            $value = trim((string)$value);
            $safe[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 180, 'UTF-8') : substr($value, 0, 180);
        }
    }
    return $safe;
}

function spidercms_log_label($action) {
    $labels = [
        'login_success'=>'Logowanie poprawne','login_failed'=>'Nieudane logowanie','logout'=>'Wylogowanie',
        'save_stats_settings'=>'Zapis ustawień statystyk','reset_stats'=>'Wyczyszczenie statystyk',
        'save_slider'=>'Zapis slidera','delete_slider'=>'Usunięcie slidera','create'=>'Utworzenie strony',
        'edit'=>'Edycja strony','delete'=>'Usunięcie strony','duplicate'=>'Duplikowanie strony',
        'set_homepage'=>'Zmiana strony głównej','save_menu'=>'Zapis menu','save_footer'=>'Zapis stopki',
        'apply_site_preset'=>'Zastosowanie presetu wyglądu','save_settings'=>'Zapis ustawień witryny',
        'change_password'=>'Zmiana hasła administratora','save_social_settings'=>'Zapis social media',
        'export_all'=>'Eksport ZIP','upload_media'=>'Upload pliku','delete_media'=>'Usunięcie pliku',
        'save_chat_settings'=>'Zapis ustawień czatu','test_chat_email'=>'Test e-mail / SMTP',
        'chat_reply'=>'Odpowiedź na czacie','chat_mark_read'=>'Oznaczenie rozmowy jako przeczytanej',
        'chat_archive'=>'Archiwizacja rozmowy','chat_delete'=>'Usunięcie rozmowy','clear_action_logs'=>'Czyszczenie logów',
        'export_action_logs'=>'Eksport logów akcji','add_admin_user'=>'Dodanie użytkownika','update_admin_user'=>'Edycja użytkownika','delete_admin_user'=>'Usunięcie użytkownika','permission_denied_settings_tab'=>'Odmowa dostępu do ustawień','permission_denied_settings_action'=>'Odmowa zmiany ustawień','password_reset_request'=>'Prośba o reset hasła','password_reset_success'=>'Reset hasła'
    ];
    return $labels[$action] ?? $action;
}

function spidercms_log_action($action, $status = 'info', array $context = []) {
    spidercms_logs_bootstrap();
    $entry = [
        'time'=>date('Y-m-d H:i:s'), 'timestamp'=>time(), 'action'=>(string)$action,
        'label'=>spidercms_log_label((string)$action), 'status'=>(string)$status,
        'ip'=>spidercms_client_ip(), 'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? '',
        'url'=>$_SERVER['REQUEST_URI'] ?? '', 'method'=>$_SERVER['REQUEST_METHOD'] ?? '',
        'context'=>spidercms_log_sanitize_context($context)
    ];
    @file_put_contents(SPIDERCMS_ACTION_LOG_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function spidercms_read_action_logs($limit = 300, $filter_action = '', $filter_status = '') {
    spidercms_logs_bootstrap();
    if (!file_exists(SPIDERCMS_ACTION_LOG_FILE)) return [];
    $lines = @file(SPIDERCMS_ACTION_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $lines = array_reverse($lines);
    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        if ($filter_action !== '' && ($row['action'] ?? '') !== $filter_action) continue;
        if ($filter_status !== '' && ($row['status'] ?? '') !== $filter_status) continue;
        $out[] = $row;
        if (count($out) >= $limit) break;
    }
    return $out;
}


function spidercms_read_action_logs_for_export($filter_action = '', $filter_status = '', $date_from = '', $date_to = '') {
    spidercms_logs_bootstrap();
    if (!file_exists(SPIDERCMS_ACTION_LOG_FILE)) return [];
    $lines = @file(SPIDERCMS_ACTION_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $out = [];
    $from_ts = $date_from !== '' ? strtotime($date_from . ' 00:00:00') : 0;
    $to_ts = $date_to !== '' ? strtotime($date_to . ' 23:59:59') : 0;
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        $ts = (int)($row['timestamp'] ?? strtotime($row['time'] ?? 'now'));
        if ($filter_action !== '' && ($row['action'] ?? '') !== $filter_action) continue;
        if ($filter_status !== '' && ($row['status'] ?? '') !== $filter_status) continue;
        if ($from_ts > 0 && $ts < $from_ts) continue;
        if ($to_ts > 0 && $ts > $to_ts) continue;
        $out[] = $row;
    }
    return array_reverse($out);
}

function spidercms_log_export_filename($ext) {
    return 'spidercms-logi-' . date('Y-m-d-His') . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
}

function spidercms_send_download_headers($filename, $content_type) {
    if (ob_get_level()) {
        while (ob_get_level()) { @ob_end_clean(); }
    }
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function spidercms_export_logs($format, array $logs) {
    $format = strtolower((string)$format);
    if (!in_array($format, ['csv','json','txt','zip'], true)) $format = 'csv';

    if ($format === 'json') {
        $filename = spidercms_log_export_filename('json');
        spidercms_send_download_headers($filename, 'application/json; charset=utf-8');
        echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($format === 'txt') {
        $filename = spidercms_log_export_filename('txt');
        spidercms_send_download_headers($filename, 'text/plain; charset=utf-8');
        echo "SpiderCMS - eksport logów\n";
        echo "Data eksportu: " . date('Y-m-d H:i:s') . "\n";
        echo "Liczba rekordów: " . count($logs) . "\n";
        echo str_repeat('=', 80) . "\n\n";
        foreach ($logs as $log) {
            echo "Czas: " . ($log['time'] ?? '') . "\n";
            echo "Status: " . ($log['status'] ?? '') . "\n";
            echo "Akcja: " . ($log['label'] ?? spidercms_log_label($log['action'] ?? '')) . " [" . ($log['action'] ?? '') . "]\n";
            echo "IP: " . ($log['ip'] ?? '') . "\n";
            echo "URL: " . ($log['url'] ?? '') . "\n";
            echo "Metoda: " . ($log['method'] ?? '') . "\n";
            echo "Szczegóły: " . json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            echo str_repeat('-', 80) . "\n";
        }
        exit;
    }

    if ($format === 'zip') {
        if (!class_exists('ZipArchive')) {
            // Awaryjnie, jeśli hosting nie ma rozszerzenia zip.
            $filename = spidercms_log_export_filename('json');
            spidercms_send_download_headers($filename, 'application/json; charset=utf-8');
            echo json_encode(['warning'=>'Brak rozszerzenia ZipArchive na serwerze. Zwrócono JSON zamiast ZIP.','logs'=>$logs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'spidercms_logs_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('admin-actions.json', json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $csv = fopen('php://temp', 'r+');
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, ['czas','status','akcja','etykieta','ip','url','metoda','user_agent','szczegoly'], ';');
            foreach ($logs as $log) {
                fputcsv($csv, [
                    $log['time'] ?? '', $log['status'] ?? '', $log['action'] ?? '', $log['label'] ?? '',
                    $log['ip'] ?? '', $log['url'] ?? '', $log['method'] ?? '', $log['user_agent'] ?? '',
                    json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ], ';');
            }
            rewind($csv);
            $zip->addFromString('admin-actions.csv', stream_get_contents($csv));
            fclose($csv);
            $zip->close();
            $filename = spidercms_log_export_filename('zip');
            spidercms_send_download_headers($filename, 'application/zip');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
    }

    // CSV domyślnie.
    $filename = spidercms_log_export_filename('csv');
    spidercms_send_download_headers($filename, 'text/csv; charset=utf-8');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['czas','status','akcja','etykieta','ip','url','metoda','user_agent','szczegoly'], ';');
    foreach ($logs as $log) {
        fputcsv($out, [
            $log['time'] ?? '', $log['status'] ?? '', $log['action'] ?? '', $log['label'] ?? '',
            $log['ip'] ?? '', $log['url'] ?? '', $log['method'] ?? '', $log['user_agent'] ?? '',
            json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ], ';');
    }
    fclose($out);
    exit;
}

function spidercms_log_status_badge($status) {
    $status = (string)$status;
    if ($status === 'success') return '<span class="log-badge success">OK</span>';
    if ($status === 'error') return '<span class="log-badge error">Błąd</span>';
    if ($status === 'warning') return '<span class="log-badge warning">Uwaga</span>';
    return '<span class="log-badge info">Info</span>';
}

spidercms_logs_bootstrap();


// ----------------------------------------------------------------------
// SPIDERCMS ADMIN USERS - konta użytkowników panelu
// ----------------------------------------------------------------------
if (!defined('SPIDERCMS_ADMIN_USERS_DIR')) {
    define('SPIDERCMS_ADMIN_USERS_DIR', __DIR__ . '/.users');
}
if (!defined('SPIDERCMS_ADMIN_USERS_FILE')) {
    define('SPIDERCMS_ADMIN_USERS_FILE', SPIDERCMS_ADMIN_USERS_DIR . '/admin_users.json');
}

function spidercms_admin_users_bootstrap() {
    if (!is_dir(SPIDERCMS_ADMIN_USERS_DIR)) {
        @mkdir(SPIDERCMS_ADMIN_USERS_DIR, 0750, true);
    }
    spidercms_write_htaccess(SPIDERCMS_ADMIN_USERS_DIR, "Options -Indexes\nRequire all denied\nDeny from all\n");

    if (!file_exists(SPIDERCMS_ADMIN_USERS_FILE)) {
        @file_put_contents(SPIDERCMS_ADMIN_USERS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod(SPIDERCMS_ADMIN_USERS_FILE, 0640);
    }
}

function spidercms_admin_users_load() {
    spidercms_admin_users_bootstrap();
    $data = json_decode((string)@file_get_contents(SPIDERCMS_ADMIN_USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function spidercms_admin_users_save(array $users) {
    spidercms_admin_users_bootstrap();
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $ok = @file_put_contents(SPIDERCMS_ADMIN_USERS_FILE, $json, LOCK_EX) !== false;
    @chmod(SPIDERCMS_ADMIN_USERS_FILE, 0640);
    return $ok;
}

function spidercms_admin_user_clean_username($username) {
    $username = strtolower(trim((string)$username));
    $username = preg_replace('/[^a-z0-9_\-.@]/', '', $username);
    return substr($username, 0, 80);
}

function spidercms_admin_user_clean_role($role) {
    $role = strtolower(trim((string)$role));
    return in_array($role, ['admin','editor','moderator','viewer'], true) ? $role : 'editor';
}

function spidercms_admin_user_role_label($role) {
    $labels = [
        'admin' => 'Administrator',
        'editor' => 'Edytor',
        'moderator' => 'Moderator',
        'viewer' => 'Podgląd',
    ];
    return $labels[$role] ?? $role;
}

function spidercms_admin_current_username() {
    return $_SESSION['admin_username'] ?? 'admin';
}

function spidercms_admin_current_role() {
    return $_SESSION['admin_user_role'] ?? 'admin';
}

function spidercms_admin_has_role($roles) {
    $role = spidercms_admin_current_role();
    if ($role === 'admin') return true;
    return in_array($role, (array)$roles, true);
}

function spidercms_admin_require_role($roles) {
    if (!spidercms_admin_has_role($roles)) {
        http_response_code(403);
        exit('Brak uprawnień do wykonania tej akcji.');
    }
}


function spidercms_admin_is_admin() {
    return spidercms_admin_current_role() === 'admin';
}

function spidercms_admin_can_access_settings() {
    return spidercms_admin_is_admin();
}

function spidercms_admin_settings_actions() {
    return [
        'save_settings',
        'change_password',
        'apply_site_preset',
        'save_social_settings',
    ];
}


function spidercms_admin_users_ensure_default($admin_hash = '') {
    $users = spidercms_admin_users_load();
    if (!empty($users)) return;

    $hash = (is_string($admin_hash) && $admin_hash !== '') ? $admin_hash : password_hash('admin', PASSWORD_DEFAULT);

    $users[] = [
        'id' => 'adm_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
        'username' => 'admin',
        'display_name' => 'Administrator',
        'email' => '',
        'role' => 'admin',
        'password_hash' => $hash,
        'active' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login_at' => '',
        'last_login_ip_hash' => '',
    ];
    spidercms_admin_users_save($users);
}

function spidercms_admin_authenticate_user($username, $password, $legacy_admin_hash = '') {
    $username = spidercms_admin_user_clean_username($username);
    if ($username === '') $username = 'admin';

    spidercms_admin_users_ensure_default($legacy_admin_hash);
    $users = spidercms_admin_users_load();

    foreach ($users as $idx => $user) {
        if (($user['username'] ?? '') !== $username) continue;
        if (empty($user['active'])) return false;

        if (password_verify((string)$password, (string)($user['password_hash'] ?? ''))) {
            $users[$idx]['last_login_at'] = date('Y-m-d H:i:s');
            $users[$idx]['last_login_ip_hash'] = hash('sha256', spidercms_client_ip());
            spidercms_admin_users_save($users);
            return $users[$idx];
        }
        return false;
    }

    // Kompatybilność z poprzednim systemem: login admin + stare hasło z config.php.
    if ($username === 'admin' && is_string($legacy_admin_hash) && $legacy_admin_hash !== '' && password_verify((string)$password, $legacy_admin_hash)) {
        spidercms_admin_users_ensure_default($legacy_admin_hash);
        $users = spidercms_admin_users_load();
        foreach ($users as $user) {
            if (($user['username'] ?? '') === 'admin') return $user;
        }
    }

    return false;
}


// ----------------------------------------------------------------------
// SPIDERCMS PASSWORD RESET
// ----------------------------------------------------------------------
if (!defined('SPIDERCMS_PASSWORD_RESETS_FILE')) {
    define('SPIDERCMS_PASSWORD_RESETS_FILE', SPIDERCMS_ADMIN_USERS_DIR . '/password_resets.json');
}

function spidercms_password_resets_load() {
    spidercms_admin_users_bootstrap();
    $data = json_decode((string)@file_get_contents(SPIDERCMS_PASSWORD_RESETS_FILE), true);
    return is_array($data) ? $data : [];
}

function spidercms_password_resets_save(array $tokens) {
    spidercms_admin_users_bootstrap();
    $json = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $ok = @file_put_contents(SPIDERCMS_PASSWORD_RESETS_FILE, $json, LOCK_EX) !== false;
    @chmod(SPIDERCMS_PASSWORD_RESETS_FILE, 0640);
    return $ok;
}

function spidercms_password_reset_cleanup() {
    $tokens = spidercms_password_resets_load();
    $now = time();
    $changed = false;
    foreach ($tokens as $token => $row) {
        if (!is_array($row) || (int)($row['expires_at'] ?? 0) < $now || !empty($row['used'])) {
            unset($tokens[$token]);
            $changed = true;
        }
    }
    if ($changed) spidercms_password_resets_save($tokens);
}

function spidercms_password_reset_base_admin_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/admin.php';
    if ($host === '') return 'admin.php';
    return $scheme . '://' . $host . $script;
}

function spidercms_password_reset_find_user($username_or_email) {
    $needle = strtolower(trim((string)$username_or_email));
    if ($needle === '') return null;
    foreach (spidercms_admin_users_load() as $idx => $user) {
        $u = strtolower((string)($user['username'] ?? ''));
        $e = strtolower((string)($user['email'] ?? ''));
        if ($needle === $u || ($e !== '' && $needle === $e)) {
            $user['_index'] = $idx;
            return $user;
        }
    }
    return null;
}

function spidercms_password_reset_create_token($username_or_email) {
    spidercms_password_reset_cleanup();
    $user = spidercms_password_reset_find_user($username_or_email);
    if (!$user || empty($user['active'])) return false;

    $token = bin2hex(random_bytes(32));
    $tokens = spidercms_password_resets_load();
    $tokens[$token] = [
        'username' => (string)($user['username'] ?? ''),
        'created_at' => time(),
        'expires_at' => time() + 3600,
        'ip_hash' => hash('sha256', spidercms_client_ip()),
        'used' => false,
    ];
    spidercms_password_resets_save($tokens);

    return [
        'token' => $token,
        'user' => $user,
        'url' => spidercms_password_reset_base_admin_url() . '?reset_token=' . rawurlencode($token),
    ];
}

function spidercms_password_reset_send_email($email, $url, $username = '') {
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $subject = 'Reset hasła SpiderCMS';
    $body = "Witaj" . ($username !== '' ? " " . $username : "") . ",\n\n";
    $body .= "Otrzymaliśmy prośbę o reset hasła do panelu SpiderCMS.\n\n";
    $body .= "Kliknij link poniżej, aby ustawić nowe hasło. Link jest ważny przez 1 godzinę:\n";
    $body .= $url . "\n\n";
    $body .= "Jeżeli to nie Ty wysłałeś tę prośbę, zignoruj tę wiadomość.\n";

    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($email, $subject, $body, $headers);
}

function spidercms_password_reset_get_valid($token) {
    spidercms_password_reset_cleanup();
    $token = trim((string)$token);
    if ($token === '') return false;
    $tokens = spidercms_password_resets_load();
    if (empty($tokens[$token]) || !is_array($tokens[$token])) return false;
    $row = $tokens[$token];
    if (!empty($row['used'])) return false;
    if ((int)($row['expires_at'] ?? 0) < time()) return false;
    return $row;
}

function spidercms_password_reset_apply($token, $new_password) {
    $row = spidercms_password_reset_get_valid($token);
    if (!$row) return false;

    $new_password = (string)$new_password;
    if (strlen($new_password) < 6) return false;

    $users = spidercms_admin_users_load();
    $changed = false;
    foreach ($users as &$user) {
        if (($user['username'] ?? '') === ($row['username'] ?? '')) {
            $user['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            $user['active'] = true;
            $changed = true;
            break;
        }
    }
    unset($user);

    if (!$changed) return false;
    spidercms_admin_users_save($users);

    $tokens = spidercms_password_resets_load();
    if (isset($tokens[$token])) {
        $tokens[$token]['used'] = true;
        $tokens[$token]['used_at'] = time();
    }
    spidercms_password_resets_save($tokens);

    spidercms_log_action('password_reset_success', 'success', ['username' => $row['username'] ?? '']);
    return true;
}

function spidercms_admin_users_tab_html() {
    $users = spidercms_admin_users_load();
    $current = spidercms_admin_current_username();

    ob_start();
    ?>
    <div class="card">
        <h2 style="margin-bottom:1rem;"><i class="fa-solid fa-users-gear"></i> Użytkownicy panelu</h2>
        <p style="color:#94a3b8;margin-bottom:1.5rem;">
            Twórz konta użytkowników panelu SpiderCMS, przypisuj role, resetuj hasła oraz blokuj dostęp. Jeśli użytkownik ma wpisany e-mail, może użyć opcji „Nie pamiętasz hasła?”.
            Dane są zapisywane bez bazy danych w pliku <code>.users/admin_users.json</code>.
        </p>

        <div class="spider-users-layout">
            <div class="card spider-user-card">
                <h3><i class="fa-solid fa-user-plus"></i> Dodaj użytkownika</h3>
                <form method="post" class="spider-user-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_admin_user">

                    <label>Login</label>
                    <input type="text" name="username" required placeholder="editor" autocomplete="off">

                    <label>Nazwa wyświetlana</label>
                    <input type="text" name="display_name" placeholder="Jan Kowalski">

                    <label>E-mail</label>
                    <input type="email" name="email" placeholder="name@example.com">

                    <label>Hasło</label>
                    <input type="password" name="password" required minlength="6" autocomplete="new-password">

                    <label>Rola</label>
                    <select name="role">
                        <option value="admin">Administrator - pełny dostęp</option>
                        <option value="editor">Edytor - strony i media</option>
                        <option value="moderator">Moderator - chat, rezerwacje i logi</option>
                        <option value="viewer">Podgląd - tylko odczyt</option>
                    </select>

                    <button type="submit" class="btn btn-edit"><i class="fa-solid fa-plus"></i> Utwórz użytkownika</button>
                </form>
            </div>

            <div class="card spider-user-card spider-users-table-wrap">
                <h3><i class="fa-solid fa-users"></i> Lista użytkowników</h3>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Login</th>
                            <th>Nazwa</th>
                            <th>Rola</th>
                            <th>Status</th>
                            <th>Ostatnie logowanie</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $username = (string)($user['username'] ?? '');
                        $is_self = $username === $current;
                        ?>
                        <tr>
                            <td><strong><?= e($username) ?></strong><br><small><?= e($user['email'] ?? '') ?></small></td>
                            <td><?= e($user['display_name'] ?? '') ?></td>
                            <td><?= e(spidercms_admin_user_role_label($user['role'] ?? 'editor')) ?></td>
                            <td><?= !empty($user['active']) ? '<span class="log-badge success">Aktywne</span>' : '<span class="log-badge error">Zablokowane</span>' ?></td>
                            <td><?= e($user['last_login_at'] ?? '-') ?></td>
                            <td style="min-width:260px;">
                                <details>
                                    <summary class="btn btn-view spider-summary-btn">Edytuj</summary>
                                    <form method="post" class="spider-user-form small">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_admin_user">
                                        <input type="hidden" name="user_id" value="<?= e($user['id'] ?? '') ?>">

                                        <input type="text" name="display_name" value="<?= e($user['display_name'] ?? '') ?>" placeholder="Nazwa wyświetlana">
                                        <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" placeholder="E-mail">

                                        <select name="role" <?= $is_self ? 'disabled' : '' ?>>
                                            <?php foreach (['admin'=>'Administrator','editor'=>'Edytor','moderator'=>'Moderator','viewer'=>'Podgląd'] as $role => $label): ?>
                                                <option value="<?= e($role) ?>" <?= (($user['role'] ?? '') === $role) ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($is_self): ?>
                                            <input type="hidden" name="role" value="<?= e($user['role'] ?? 'admin') ?>">
                                        <?php endif; ?>

                                        <input type="password" name="new_password" placeholder="Nowe hasło, zostaw puste bez zmian">

                                        <label class="inline-check">
                                            <input type="checkbox" name="active" value="1" <?= !empty($user['active']) ? 'checked' : '' ?> <?= $is_self ? 'disabled' : '' ?>>
                                            Konto aktywne
                                        </label>
                                        <?php if ($is_self): ?>
                                            <input type="hidden" name="active" value="1">
                                        <?php endif; ?>

                                        <button type="submit" class="btn btn-edit">Zapisz użytkownika</button>
                                    </form>
                                </details>

                                <?php if (!$is_self): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć tego użytkownika?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_admin_user">
                                        <input type="hidden" name="user_id" value="<?= e($user['id'] ?? '') ?>">
                                        <button type="submit" class="btn btn-delete">Usuń</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6">Brak użytkowników.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
    .spider-users-layout{display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:1.5rem;align-items:start}
    .spider-user-card{margin:0;background:rgba(15,23,42,.45)}
    .spider-users-table-wrap{overflow:auto}
    .spider-user-form{display:grid;gap:.75rem;margin-top:1rem}
    .spider-user-form.small{margin-top:.75rem;gap:.5rem}
    .spider-user-form input,.spider-user-form select{
        width:100%;padding:.8rem .9rem;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#f8fafc;box-sizing:border-box
    }
    .spider-user-form label{color:#cbd5e1;font-weight:700}
    .inline-check{display:flex!important;gap:.5rem;align-items:center}
    .inline-check input{width:auto!important}
    .spider-summary-btn{display:inline-flex!important;cursor:pointer;margin-bottom:.4rem}
    @media(max-width:980px){.spider-users-layout{grid-template-columns:1fr}}
    </style>
    <?php
    return ob_get_clean();
}

// ----------------------------------------------------------------------
// SPIDERCMS SECURITY HARDENING START
// Dodatkowe zabezpieczenia serwerowe dla hostingu produkcyjnego.
// ----------------------------------------------------------------------

// Bezpieczniejsze ciasteczko sesji — działa najlepiej przed session_start,
// ale poniższe ustawienia też wzmacniają obecną sesję, jeśli serwer na to pozwala.
if (PHP_SESSION_ACTIVE === session_status()) {
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        @ini_set('session.cookie_secure', '1');
    }
}

// Dodatkowe nagłówki bezpieczeństwa.
// Uwaga: nie dodaję bardzo agresywnego CSP, żeby nie zepsuć TinyMCE/CDN/fontawesome.
if (!headers_sent()) {
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Download-Options: noopen');
}

// Bezpieczny zapis JSON.
function spidercms_safe_write_json($file, array $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return false;
    }

    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) {
        return false;
    }

    @chmod($tmp, 0640);
    return @rename($tmp, $file);
}

// Minimalny log bezpieczeństwa.
function spidercms_security_log($event, array $context = []) {
    $dir = __DIR__ . '/.logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $file = $dir . '/security.jsonl';

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'event' => (string)$event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        'context' => $context,
    ];

    @file_put_contents(
        $file,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
    @chmod($file, 0640);
}

// Sprawdzenie rozszerzeń i nazw uploadów.
function spidercms_is_safe_upload_name($name) {
    $name = basename((string)$name);
    if ($name === '' || preg_match('/[\/\\\\]/', $name)) {
        return false;
    }

    // Blokada podwójnych rozszerzeń typu obraz.php.jpg oraz plików wykonywalnych.
    if (preg_match('/\.(php|php[0-9]?|phtml|phar|cgi|pl|py|sh|bash|exe|dll|bat|cmd|js|html?|shtml|svgz)(\.|$)/i', $name)) {
        return false;
    }

    return true;
}

function spidercms_safe_uploaded_image_ext($filename) {
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true);
}

function spidercms_secure_uploads_htaccess() {
    $uploads = __DIR__ . '/uploads';
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0755, true);
    }

    $htaccess = <<<'HTACCESS'
Options -Indexes
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .sh
RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .sh
<FilesMatch "\.(php|php[0-9]?|phtml|phar|cgi|pl|py|sh|bash|exe|dll|bat|cmd)$">
    Require all denied
    Deny from all
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
HTACCESS;

    @file_put_contents($uploads . '/.htaccess', $htaccess, LOCK_EX);
    @chmod($uploads . '/.htaccess', 0644);
}

// Blokada bezpośredniego dostępu do prywatnych katalogów flat-file.
function spidercms_secure_private_dirs() {
    $private_dirs = [
        __DIR__ . '/.chat',
        __DIR__ . '/.logs',
        __DIR__ . '/.stats',
        __DIR__ . '/.backups',
    ];

    $private_htaccess = <<<'HTACCESS'
Options -Indexes
Require all denied
Deny from all
HTACCESS;

    foreach ($private_dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/.htaccess', $private_htaccess, LOCK_EX);
        @chmod($dir . '/.htaccess', 0644);
    }
}

// Ochrona plików ukrytych i konfiguracyjnych w katalogu CMS.
// Działa na Apache/home.pl, nie powinno psuć publicznych stron.
function spidercms_write_root_security_htaccess() {
    $file = __DIR__ . '/.htaccess';

    $block = <<<'HTACCESS'

# BEGIN SpiderCMS Security
Options -Indexes

<FilesMatch "^\.">
    Require all denied
    Deny from all
</FilesMatch>

<FilesMatch "\.(json|jsonl|log|bak|tmp|sql|sqlite|db|env|ini|lock)$">
    Require all denied
    Deny from all
</FilesMatch>

<FilesMatch "^(config\.php|composer\.json|composer\.lock)$">
    Require all denied
    Deny from all
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
# END SpiderCMS Security

HTACCESS;

    $current = is_file($file) ? (string)@file_get_contents($file) : '';
    if (strpos($current, '# BEGIN SpiderCMS Security') === false) {
        @file_put_contents($file, rtrim($current) . "\n" . $block, LOCK_EX);
        @chmod($file, 0644);
    }
}

// Walidacja adresu powrotu / URL wewnętrznego.
function spidercms_safe_internal_redirect($url, $fallback = 'admin.php') {
    $url = trim((string)$url);
    if ($url === '' || preg_match('~^(https?:)?//~i', $url) || str_contains($url, "\n") || str_contains($url, "\r")) {
        return $fallback;
    }
    return $url;
}

// Jednorazowe zabezpieczenia katalogów.
spidercms_secure_uploads_htaccess();
spidercms_secure_private_dirs();
spidercms_write_root_security_htaccess();

// Logowanie podejrzanych metod HTTP.
$allowed_methods = ['GET', 'POST', 'HEAD'];
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed_methods, true)) {
    spidercms_security_log('blocked_http_method', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    exit('Method not allowed');
}
// SPIDERCMS SECURITY HARDENING END


// ----------------------------------------------------------------------
// Obsługa wylogowania
// ----------------------------------------------------------------------
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    spidercms_log_action('logout', 'success');
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

require_once __DIR__ . '/config.php';
spidercms_admin_users_ensure_default($ADMIN_HASH ?? '');

// ----------------------------------------------------------------------
// BAZOWA ŚCIEŻKA
// ----------------------------------------------------------------------
define('BASE_URL', ''); // ← zmień jeśli folder nazywa się inaczej


// ----------------------------------------------------------------------
// USTAWIENIE FOLDERU DLA NOWO TWORZONYCH STRON
// ----------------------------------------------------------------------
$page_folder_file = __DIR__ . '/.page_folder.json';
$page_folder_settings = file_exists($page_folder_file) ? json_decode(file_get_contents($page_folder_file), true) : [];
if (!is_array($page_folder_settings)) $page_folder_settings = [];

function spidercms_sanitize_page_folder($folder) {
    $folder = str_replace('\\', '/', trim((string)$folder));
    $folder = trim($folder, '/');
    $folder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder);
    $parts = [];
    foreach (explode('/', $folder) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') continue;
        $parts[] = $part;
    }
    $folder = implode('/', $parts);
    return $folder !== '' ? $folder : 'pages';
}

function spidercms_page_folder_dir($folder) {
    return __DIR__ . '/' . spidercms_sanitize_page_folder($folder);
}

function spidercms_page_folder_url($folder) {
    return rtrim(BASE_URL, '/') . '/' . spidercms_sanitize_page_folder($folder) . '/';
}

function spidercms_page_folder_depth($folder) {
    $folder = spidercms_sanitize_page_folder($folder);
    return substr_count($folder, '/') + 1;
}

function spidercms_available_page_folders() {
    $folders = ['pages'];
    foreach (glob(__DIR__ . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (in_array($name, ['uploads','assets','vendor','node_modules','.chat','.backups'], true)) continue;
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $name)) $folders[] = $name;
    }
    $folders[] = spidercms_sanitize_page_folder($GLOBALS['page_folder_settings']['folder'] ?? 'pages');
    $folders = array_values(array_unique(array_filter($folders)));
    sort($folders);
    return $folders;
}

$active_page_folder = spidercms_sanitize_page_folder($page_folder_settings['folder'] ?? 'pages');
$active_pages_dir = spidercms_page_folder_dir($active_page_folder);
$active_pages_url = spidercms_page_folder_url($active_page_folder);
$active_pages_depth = spidercms_page_folder_depth($active_page_folder);
if (!is_dir($active_pages_dir)) {
    @mkdir($active_pages_dir, 0755, true);
}
if (!defined('ACTIVE_PAGES_DIR')) define('ACTIVE_PAGES_DIR', $active_pages_dir);
if (!defined('ACTIVE_PAGES_URL')) define('ACTIVE_PAGES_URL', $active_pages_url);
if (!defined('ACTIVE_PAGES_DEPTH')) define('ACTIVE_PAGES_DEPTH', $active_pages_depth);

// ----------------------------------------------------------------------
// Inicjalizacja zmiennych
// ----------------------------------------------------------------------
$toast = ['type' => '', 'msg' => ''];
$login_error = '';

// ----------------------------------------------------------------------
// Wczytanie ustawień i logo
// ----------------------------------------------------------------------
$settings_file = __DIR__ . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
if (!is_array($settings)) {
    $settings = [];
}
if (!array_key_exists('header_enabled', $settings)) {
    $settings['header_enabled'] = '1';
}
if (!array_key_exists('show_site_name_in_header', $settings)) {
    $settings['show_site_name_in_header'] = '0';
}
if (!array_key_exists('header_title_font_size', $settings)) $settings['header_title_font_size'] = '22';
if (!array_key_exists('header_title_font_weight', $settings)) $settings['header_title_font_weight'] = '800';
if (!array_key_exists('header_title_color', $settings)) $settings['header_title_color'] = '';
if (!array_key_exists('header_title_gap', $settings)) $settings['header_title_gap'] = '10';
if (!array_key_exists('header_title_uppercase', $settings)) $settings['header_title_uppercase'] = '0';
if (!array_key_exists('header_title_italic', $settings)) $settings['header_title_italic'] = '0';
if (!array_key_exists('header_title_shadow', $settings)) $settings['header_title_shadow'] = '0';

if (!array_key_exists('site_menu_behavior', $settings)) $settings['site_menu_behavior'] = 'standard';
if (!array_key_exists('site_menu_transparent_top', $settings)) $settings['site_menu_transparent_top'] = '0';
if (!array_key_exists('site_menu_blur', $settings)) $settings['site_menu_blur'] = '0';
if (!array_key_exists('site_menu_shadow_scroll', $settings)) $settings['site_menu_shadow_scroll'] = '1';
if (!array_key_exists('site_menu_auto_hide', $settings)) $settings['site_menu_auto_hide'] = '0';
if (!array_key_exists('site_menu_animate', $settings)) $settings['site_menu_animate'] = '1';
if (!array_key_exists('site_menu_mobile_style', $settings)) $settings['site_menu_mobile_style'] = 'dropdown';
if (!array_key_exists('site_menu_mobile_side', $settings)) $settings['site_menu_mobile_side'] = 'right';
if (!array_key_exists('site_menu_mobile_autoclose', $settings)) $settings['site_menu_mobile_autoclose'] = '1';
if (!array_key_exists('site_menu_height', $settings)) $settings['site_menu_height'] = '74';
if (!array_key_exists('site_menu_radius', $settings)) $settings['site_menu_radius'] = '0';
if (!array_key_exists('site_menu_active_style', $settings)) $settings['site_menu_active_style'] = 'underline';
if (!array_key_exists('site_menu_hover_style', $settings)) $settings['site_menu_hover_style'] = 'lift';
if (!array_key_exists('site_menu_logo_shrink', $settings)) $settings['site_menu_logo_shrink'] = '1';
if (!array_key_exists('site_menu_progress', $settings)) $settings['site_menu_progress'] = '0';
if (!array_key_exists('site_menu_cta_enabled', $settings)) $settings['site_menu_cta_enabled'] = '0';
if (!array_key_exists('site_menu_cta_text', $settings)) $settings['site_menu_cta_text'] = 'Umów wizytę';
if (!array_key_exists('site_menu_cta_url', $settings)) $settings['site_menu_cta_url'] = '#kontakt';

if (!array_key_exists('header_title_bg', $settings)) $settings['header_title_bg'] = '';
if (!array_key_exists('header_title_radius', $settings)) $settings['header_title_radius'] = '0';

// Jako domyślne logo ustawiamy nową grafikę z pająkiem
$logo_url = $settings['logo'] ?? (BASE_URL . 'assets/images/spidercms-icon.png');

// ----------------------------------------------------------------------
// Wczytanie kolorów i rozszerzonych ustawień stylu
// ----------------------------------------------------------------------
$theme_file = __DIR__ . '/.theme.json';
$theme_defaults = [
    'primary' => '#a855f7',
    'primary-dark' => '#7e22ce',
    'accent' => '#2563eb',
    'page-bg' => '#f9fafb',
    'page-text' => '#111827',
    'header-bg' => '#ffffff',
    'header-text' => '#374151',
    'footer-bg' => '#1f2937',
    'footer-text' => '#f3f4f6',
    'footer-muted' => '#9ca3af',
    'link-color' => '#a855f7',
    'button-bg' => '#a855f7',
    'button-text' => '#ffffff',
    'font-family' => 'system-ui, sans-serif',
    'header-height' => '74',
    'logo-height' => '100',
    'content-width' => '1240',
    'border-radius' => '10',
    'shadow-enabled' => '1',
    'menu-position' => 'right',
];
$theme_loaded = file_exists($theme_file) ? json_decode(file_get_contents($theme_file), true) : [];
if (!is_array($theme_loaded)) $theme_loaded = [];
$theme = array_merge($theme_defaults, $theme_loaded);

function theme_value($key, $default = '') {
    global $theme, $theme_defaults;
    return $theme[$key] ?? $theme_defaults[$key] ?? $default;
}

function css_px($value, $default) {
    $value = preg_replace('/[^0-9.]/', '', (string)$value);
    return $value !== '' ? $value . 'px' : $default . 'px';
}


function spidercms_clean_slug($slug) {
    $slug = trim((string)$slug);
    $slug = strtolower($slug);
    $map = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z'
    ];
    $slug = strtr($slug, $map);
    $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', $slug);
    $slug = trim($slug, '-_');
    return $slug;
}

function spidercms_page_get_title_from_source($source, $fallback_slug = '') {
    if (preg_match('/\$title\s*=\s*([\'\"])(.*?)\1\s*;/s', (string)$source, $m)) {
        return stripcslashes($m[2]);
    }
    if (preg_match('/<title>(.*?)<\/title>/is', (string)$source, $m)) {
        return trim(strip_tags($m[1]));
    }
    return $fallback_slug !== '' ? ucwords(str_replace(['-', '_'], ' ', $fallback_slug)) : '';
}

function spidercms_page_set_title_in_source($source, $title) {
    $title_php = addcslashes((string)$title, "\\'");
    $source = (string)$source;
    $count = 0;
    $source = preg_replace('/\$title\s*=\s*([\'\"])(.*?)\1\s*;/s', "\$title = '" . $title_php . "';", $source, 1, $count);
    if ($count === 0) {
        $source = "<?php\n\$title = '" . $title_php . "';\n?>\n" . $source;
    }
    return $source;
}


// ----------------------------------------------------------------------
// ZACHOWANIE MENU STRONY WWW - FRONTEND
// ----------------------------------------------------------------------
function spidercms_write_site_menu_behavior_file() {
    global $settings;

    $cfg = [
        'behavior' => $settings['site_menu_behavior'] ?? 'standard',
        'transparentTop' => ($settings['site_menu_transparent_top'] ?? '0') === '1',
        'blur' => ($settings['site_menu_blur'] ?? '0') === '1',
        'shadowScroll' => ($settings['site_menu_shadow_scroll'] ?? '1') === '1',
        'autoHide' => (($settings['site_menu_auto_hide'] ?? '0') === '1') || (($settings['site_menu_behavior'] ?? 'standard') === 'autohide'),
        'animate' => ($settings['site_menu_animate'] ?? '1') === '1',
        'mobileStyle' => $settings['site_menu_mobile_style'] ?? 'dropdown',
        'mobileSide' => $settings['site_menu_mobile_side'] ?? 'right',
        'mobileAutoclose' => ($settings['site_menu_mobile_autoclose'] ?? '1') === '1',
        'height' => max(52, min(120, (int)($settings['site_menu_height'] ?? 74))),
        'radius' => max(0, min(40, (int)($settings['site_menu_radius'] ?? 0))),
        'activeStyle' => $settings['site_menu_active_style'] ?? 'underline',
        'hoverStyle' => $settings['site_menu_hover_style'] ?? 'lift',
        'logoShrink' => ($settings['site_menu_logo_shrink'] ?? '1') === '1',
        'progress' => ($settings['site_menu_progress'] ?? '0') === '1',
        'ctaEnabled' => ($settings['site_menu_cta_enabled'] ?? '0') === '1',
        'ctaText' => $settings['site_menu_cta_text'] ?? 'Umów wizytę',
        'ctaUrl' => $settings['site_menu_cta_url'] ?? '#kontakt',
    ];

    $payload = var_export($cfg, true);

    $file = <<<'PHP'
<?php
$spider_menu_cfg = __PAYLOAD__;
?>
<style>
:root{
    --spider-site-menu-height: <?php echo (int)$spider_menu_cfg['height']; ?>px;
    --spider-site-menu-radius: <?php echo (int)$spider_menu_cfg['radius']; ?>px;
}
.site-header{
    min-height:var(--spider-site-menu-height)!important;
    border-radius:0 0 var(--spider-site-menu-radius) var(--spider-site-menu-radius)!important;
    transition:transform .25s ease, background .25s ease, box-shadow .25s ease, backdrop-filter .25s ease!important;
}
<?php if (in_array($spider_menu_cfg['behavior'], ['sticky','autohide'], true)): ?>
.site-header{position:sticky!important;top:0!important;z-index:9990!important}
<?php elseif ($spider_menu_cfg['behavior'] === 'fixed'): ?>
.site-header{position:fixed!important;top:0!important;left:0!important;right:0!important;z-index:9990!important}
body{padding-top:var(--spider-site-menu-height)}
<?php endif; ?>
<?php if ($spider_menu_cfg['transparentTop']): ?>
.site-header:not(.spider-menu-scrolled){background:transparent!important;box-shadow:none!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['blur']): ?>
.site-header{backdrop-filter:blur(14px)!important;-webkit-backdrop-filter:blur(14px)!important;background:rgba(255,255,255,.78)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['shadowScroll']): ?>
.site-header.spider-menu-scrolled{box-shadow:0 12px 34px rgba(15,23,42,.14)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['autoHide']): ?>
.site-header.spider-menu-hidden{transform:translateY(-110%)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['animate']): ?>
.site-header{animation:spiderMenuIn .38s ease both}
@keyframes spiderMenuIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
<?php endif; ?>
<?php if ($spider_menu_cfg['logoShrink']): ?>
.site-header.spider-menu-scrolled .site-logo,
.site-header.spider-menu-scrolled .logo img,
.site-header.spider-menu-scrolled img.logo{max-height:42px!important;transition:max-height .25s ease}
<?php endif; ?>
.nav-menu a,.site-header nav a{transition:transform .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease}
<?php if ($spider_menu_cfg['hoverStyle'] === 'lift'): ?>
.nav-menu a:hover,.site-header nav a:hover{transform:translateY(-2px)}
<?php elseif ($spider_menu_cfg['hoverStyle'] === 'underline'): ?>
.nav-menu a:hover,.site-header nav a:hover{text-decoration:underline;text-underline-offset:6px}
<?php elseif ($spider_menu_cfg['hoverStyle'] === 'background'): ?>
.nav-menu a:hover,.site-header nav a:hover{background:rgba(168,85,247,.12);border-radius:999px}
<?php endif; ?>
<?php if ($spider_menu_cfg['activeStyle'] === 'underline'): ?>
.nav-menu a.active,.site-header nav a.active{border-bottom:2px solid var(--primary,#a855f7)}
<?php elseif ($spider_menu_cfg['activeStyle'] === 'pill'): ?>
.nav-menu a.active,.site-header nav a.active{background:var(--primary,#a855f7);color:#fff!important;border-radius:999px}
<?php elseif ($spider_menu_cfg['activeStyle'] === 'box'): ?>
.nav-menu a.active,.site-header nav a.active{background:rgba(168,85,247,.12);box-shadow:inset 0 0 0 1px rgba(168,85,247,.28);border-radius:12px}
<?php endif; ?>
#spider-scroll-progress{position:fixed;top:0;left:0;height:3px;background:var(--primary,#a855f7);width:0;z-index:10000;display:<?php echo $spider_menu_cfg['progress'] ? 'block' : 'none'; ?>}
.spider-menu-cta{display:inline-flex;align-items:center;justify-content:center;padding:.65rem 1rem;border-radius:999px;background:var(--primary,#a855f7);color:#fff!important;text-decoration:none!important;font-weight:800;margin-left:.6rem}
@media(max-width:760px){
    <?php if ($spider_menu_cfg['mobileStyle'] === 'fullscreen'): ?>
    .nav-menu,.site-header nav{position:fixed!important;inset:0!important;z-index:9998!important;background:#fff!important;display:none!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:1rem!important;padding:2rem!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{display:flex!important}
    <?php elseif ($spider_menu_cfg['mobileStyle'] === 'side'): ?>
    .nav-menu,.site-header nav{position:fixed!important;top:0!important;bottom:0!important;<?php echo ($spider_menu_cfg['mobileSide'] === 'left') ? 'left:0;transform:translateX(-105%)' : 'right:0;transform:translateX(105%)'; ?>!important;width:min(340px,86vw)!important;z-index:9998!important;background:#fff!important;display:flex!important;flex-direction:column!important;gap:.5rem!important;padding:5rem 1rem 1rem!important;transition:transform .25s ease!important;box-shadow:0 20px 60px rgba(0,0,0,.25)!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{transform:translateX(0)!important}
    <?php else: ?>
    .nav-menu,.site-header nav{display:none!important;position:absolute!important;top:100%!important;left:0!important;right:0!important;background:#fff!important;flex-direction:column!important;padding:1rem!important;box-shadow:0 16px 40px rgba(0,0,0,.14)!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{display:flex!important}
    <?php endif; ?>
}
</style>
<div id="spider-scroll-progress"></div>
<script>
(function(){
    const cfg = <?php echo json_encode($spider_menu_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function(){
        const header = document.querySelector('.site-header');
        if(!header) return;

        let lastY = window.scrollY || 0;
        const progress = document.getElementById('spider-scroll-progress');

        function onScroll(){
            const y = window.scrollY || 0;
            header.classList.toggle('spider-menu-scrolled', y > 10);

            if(cfg.autoHide){
                header.classList.toggle('spider-menu-hidden', y > lastY && y > 90);
            }

            if(progress && cfg.progress){
                const h = document.documentElement;
                const max = Math.max(1, h.scrollHeight - h.clientHeight);
                progress.style.width = Math.min(100, Math.max(0, (y / max) * 100)) + '%';
            }

            lastY = y;
        }

        window.addEventListener('scroll', onScroll, {passive:true});
        onScroll();

        if(cfg.ctaEnabled){
            const nav = header.querySelector('.nav-menu') || header.querySelector('nav');
            if(nav && !nav.querySelector('.spider-menu-cta')){
                const a = document.createElement('a');
                a.className = 'spider-menu-cta';
                a.href = cfg.ctaUrl || '#';
                a.textContent = cfg.ctaText || 'Umów wizytę';
                nav.appendChild(a);
            }
        }

        document.querySelectorAll('.menu-toggle,.hamburger,.nav-toggle,[data-menu-toggle]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                document.body.classList.toggle('spider-site-menu-open');
            });
        });

        if(cfg.mobileAutoclose){
            document.querySelectorAll('.nav-menu a,.site-header nav a').forEach(function(a){
                a.addEventListener('click', function(){
                    document.body.classList.remove('spider-site-menu-open');
                });
            });
        }
    });
})();
</script>
PHP;

    $file = str_replace('__PAYLOAD__', $payload, $file);
    file_put_contents(__DIR__ . '/site-menu-behavior.php', $file, LOCK_EX);
}

function spidercms_ensure_site_menu_behavior_in_header() {
    $header = __DIR__ . '/header.php';
    if (!is_file($header)) return false;

    $content = (string)file_get_contents($header);
    if (strpos($content, 'site-menu-behavior.php') !== false) return true;

    $include = "<?php if (file_exists(__DIR__ . '/site-menu-behavior.php')) require_once __DIR__ . '/site-menu-behavior.php'; ?>\n";

    if (stripos($content, '</head>') !== false) {
        $content = str_ireplace('</head>', $include . '</head>', $content);
    } else {
        $content .= "\n" . $include;
    }

    return file_put_contents($header, $content, LOCK_EX) !== false;
}

// ----------------------------------------------------------------------
// Wczytanie ustawień stopki
// ----------------------------------------------------------------------
$footer_file = __DIR__ . '/.footer.json';
$footer_data = file_exists($footer_file) ? json_decode(file_get_contents($footer_file), true) : [
    'copyright' => '© ' . date('Y') . ' SpiderCMS – wszystkie prawa zastrzeżone.',
    'about_text' => 'Ultra-lekki system zarządzania treścią Flat-File.',
    'columns' => [
        ['title' => 'Kontakt', 'content' => 'Email: kontakt@example.com'],
        ['title' => 'Linki', 'content' => '<a href="/polityka-privacy">Polityka prywatności</a>'],
    ],
    // Kompatybilność ze starszą wersją pliku .footer.json
    'col1_title' => 'Kontakt',
    'col1_content' => 'Email: kontakt@example.com',
    'col2_title' => 'Linki',
    'col2_content' => '<a href="/polityka-privacy">Polityka prywatności</a>'
];
if (!is_array($footer_data)) {
    $footer_data = [];
}
if (empty($footer_data['columns']) || !is_array($footer_data['columns'])) {
    $footer_data['columns'] = [];
    if (!empty($footer_data['col1_title']) || !empty($footer_data['col1_content'])) {
        $footer_data['columns'][] = [
            'title' => $footer_data['col1_title'] ?? '',
            'content' => $footer_data['col1_content'] ?? '',
        ];
    }
    if (!empty($footer_data['col2_title']) || !empty($footer_data['col2_content'])) {
        $footer_data['columns'][] = [
            'title' => $footer_data['col2_title'] ?? '',
            'content' => $footer_data['col2_content'] ?? '',
        ];
    }
}
$footer_enabled = file_exists(__DIR__ . '/.footer_enabled');

// ----------------------------------------------------------------------
// Wczytanie ustawienia strony głównej
// ----------------------------------------------------------------------
$homepage_file = __DIR__ . '/.homepage';
$homepage_slug = file_exists($homepage_file) ? trim((string)file_get_contents($homepage_file)) : 'index';
if ($homepage_slug === '') {
    $homepage_slug = 'index';
}
// ----------------------------------------------------------------------
// MEDIA LIBRARY - dodaj tutaj
// ----------------------------------------------------------------------
spidercms_write_site_menu_behavior_file();
spidercms_ensure_site_menu_behavior_in_header();

$uploads_dir = __DIR__ . '/uploads/';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}
spidercms_write_htaccess($uploads_dir, "Options -Indexes\n<FilesMatch \"\\.(php|php[0-9]?|phtml|phar|cgi|pl|py|sh|htaccess)\$\">\nRequire all denied\nDeny from all\n</FilesMatch>\nphp_flag engine off\n");

function get_media_files() {
    global $uploads_dir;
    $files = [];
    foreach (glob($uploads_dir . '*') as $file) {
        if (is_file($file)) {
            $files[] = [
                'name' => basename($file),
                'url'  => BASE_URL . 'uploads/' . basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i', filemtime($file)),
                'ext'  => strtolower(pathinfo($file, PATHINFO_EXTENSION))
            ];
        }
    }
    usort($files, fn($a, $b) => filemtime($uploads_dir.$b['name']) - filemtime($uploads_dir.$a['name']));
    return $files;
}


// ----------------------------------------------------------------------
// CHAT STRONY – komunikacja odwiedzającego z administratorem
// ----------------------------------------------------------------------
$chat_dir = __DIR__ . '/.chat';
if (!is_dir($chat_dir)) {
    mkdir($chat_dir, 0755, true);
}
spidercms_write_htaccess($chat_dir, "Require all denied\nDeny from all\nOptions -Indexes\n");
$chat_file = $chat_dir . '/conversations.json';
$chat_archive_file = $chat_dir . '/archive.jsonl';
$chat_archive_index_file = $chat_dir . '/archive_index.json';
$chat_settings_file = $chat_dir . '/settings.json';
$chat_settings_defaults = [
    'enabled' => '1',
    'title' => 'Masz pytanie?',
    'subtitle' => 'Napisz do nas. Odpowiemy możliwie szybko.',
    'welcome' => 'Cześć! W czym możemy pomóc?',
    'button_text' => 'Chat',
    'admin_name' => 'Administrator',
    'email_notifications' => '0',
    'admin_email' => '',
    'from_email' => '',
    'mail_method' => 'smtp',
    'smtp_host' => '',
    'smtp_port' => '465',
    'smtp_secure' => 'ssl',
    'smtp_username' => '',
    'smtp_password' => '',
    'email_last_status' => '',
    'email_last_time' => '',
];
$chat_settings_loaded = file_exists($chat_settings_file) ? json_decode(file_get_contents($chat_settings_file), true) : [];
if (!is_array($chat_settings_loaded)) $chat_settings_loaded = [];
$chat_settings = array_merge($chat_settings_defaults, $chat_settings_loaded);



// ----------------------------------------------------------------------
// SOCIAL MEDIA HUB – ikony w nagłówku/stopce, pływające przyciski i widget kontaktowy
// ----------------------------------------------------------------------
$social_file = __DIR__ . '/.social.json';
$social_defaults = [
    'enabled' => '1',
    'show_header' => '0',
    'show_footer' => '1',
    'show_floating' => '1',
    'show_contact_widget' => '0',
    'floating_side' => 'right',
    'og_enabled' => '1',
    'og_title' => '',
    'og_description' => '',
    'og_image' => '',
    'email' => '',
    'phone' => '',
    'facebook' => '',
    'instagram' => '',
    'youtube' => '',
    'tiktok' => '',
    'linkedin' => '',
    'x' => '',
    'github' => '',
    'discord' => '',
    'whatsapp' => '',
    'messenger' => '',
];
$social_loaded = file_exists($social_file) ? json_decode(file_get_contents($social_file), true) : [];
if (!is_array($social_loaded)) $social_loaded = [];
$social_settings = array_merge($social_defaults, $social_loaded);

function social_platforms() {
    return [
        'facebook' => ['label' => 'Facebook', 'icon' => 'fa-brands fa-facebook-f'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'fa-brands fa-youtube'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'fa-brands fa-tiktok'],
        'linkedin' => ['label' => 'LinkedIn', 'icon' => 'fa-brands fa-linkedin-in'],
        'x' => ['label' => 'X', 'icon' => 'fa-brands fa-x-twitter'],
        'github' => ['label' => 'GitHub', 'icon' => 'fa-brands fa-github'],
        'discord' => ['label' => 'Discord', 'icon' => 'fa-brands fa-discord'],
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'fa-brands fa-whatsapp'],
        'messenger' => ['label' => 'Messenger', 'icon' => 'fa-brands fa-facebook-messenger'],
        'email' => ['label' => 'Email', 'icon' => 'fa-solid fa-envelope'],
        'phone' => ['label' => 'Telefon', 'icon' => 'fa-solid fa-phone'],
    ];
}

function social_clean_value($value, $max = 400) {
    $value = trim((string)$value);
    $value = strip_tags($value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
    return substr($value, 0, $max);
}

function social_public_url($key, $value) {
    $value = social_clean_value($value, 500);
    if ($value === '') return '';
    if ($key === 'email') return 'mailto:' . $value;
    if ($key === 'phone') return 'tel:' . preg_replace('/[^0-9+]/', '', $value);
    if ($key === 'whatsapp' && preg_match('/^\+?[0-9\s\-]{6,}$/', $value)) {
        return 'https://wa.me/' . preg_replace('/[^0-9]/', '', $value);
    }
    if ($key === 'messenger' && !preg_match('~^https?://~i', $value)) {
        return 'https://m.me/' . ltrim($value, '@');
    }
    if (!preg_match('~^(https?://|mailto:|tel:)~i', $value)) {
        return 'https://' . ltrim($value, '/');
    }
    return $value;
}

function social_links_from_settings($settings = null) {
    global $social_settings;
    $settings = is_array($settings) ? $settings : $social_settings;
    $links = [];
    foreach (social_platforms() as $key => $meta) {
        $raw = $settings[$key] ?? '';
        $url = social_public_url($key, $raw);
        if ($url !== '') {
            $links[] = ['key' => $key, 'label' => $meta['label'], 'icon' => $meta['icon'], 'url' => $url];
        }
    }
    return $links;
}

function social_write_widget_file() {
    global $social_settings;
    $payload = [
        'settings' => $social_settings,
        'links' => social_links_from_settings($social_settings),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $widget = <<<'PHP'
<?php
$social_payload = __PAYLOAD__;
$social_settings = $social_payload['settings'] ?? [];
$social_links = $social_payload['links'] ?? [];
if (($social_settings['enabled'] ?? '1') !== '1' || empty($social_links)) return;
function spidercms_social_safe_url($url) {
    $url = trim((string)$url);
    return preg_match('~^(https?://|mailto:|tel:)~i', $url) ? $url : '#';
}
function spidercms_social_render_link($item, $class = 'spidercms-social-link') {
    $url = spidercms_social_safe_url($item['url'] ?? '#');
    $label = htmlspecialchars($item['label'] ?? 'Social', ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($item['icon'] ?? 'fa-solid fa-link', ENT_QUOTES, 'UTF-8');
    $target = preg_match('~^https?://~i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
    return '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" aria-label="' . $label . '" title="' . $label . '"' . $target . '><i class="' . $icon . '"></i><span>' . $label . '</span></a>';
}
?>
<style>
.spidercms-social-link{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;text-decoration:none;transition:.18s}.spidercms-social-link i{font-size:1.05em}.spidercms-social-footer{max-width:var(--content-width,1240px);margin:1.5rem auto 0;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.14);display:flex;flex-wrap:wrap;gap:.7rem;align-items:center}.spidercms-social-footer-title{font-weight:800;color:var(--footer-text,#fff);margin-right:.35rem}.spidercms-social-footer .spidercms-social-link{width:38px;height:38px;border-radius:999px;background:rgba(255,255,255,.09);color:var(--footer-text,#fff)}.spidercms-social-footer .spidercms-social-link span,.spidercms-social-header .spidercms-social-link span,.spidercms-social-float .spidercms-social-link span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.spidercms-social-header{display:flex;gap:.45rem;align-items:center;margin-left:1rem}.spidercms-social-header .spidercms-social-link{width:32px;height:32px;border-radius:999px;background:rgba(168,85,247,.10);color:var(--header-text,#374151)}.spidercms-social-header .spidercms-social-link:hover,.spidercms-social-footer .spidercms-social-link:hover{transform:translateY(-2px);color:var(--primary,#a855f7)}.spidercms-social-float{position:fixed;top:50%;transform:translateY(-50%);z-index:99990;display:flex;flex-direction:column;gap:.55rem}.spidercms-social-float.right{right:18px}.spidercms-social-float.left{left:18px}.spidercms-social-float .spidercms-social-link{width:44px;height:44px;border-radius:999px;background:#111827;color:#fff;box-shadow:0 12px 28px rgba(0,0,0,.25)}.spidercms-social-float .spidercms-social-link:hover{transform:scale(1.08);background:var(--primary,#a855f7)}.spidercms-social-contact{position:fixed;left:22px;bottom:22px;z-index:99988;background:#fff;color:#111827;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 20px 50px rgba(0,0,0,.22);width:min(330px,calc(100vw - 32px));overflow:hidden}.spidercms-social-contact-head{padding:14px 16px;background:linear-gradient(135deg,#111827,#7e22ce);color:#fff;font-weight:900}.spidercms-social-contact-body{padding:12px;display:grid;gap:.55rem}.spidercms-social-contact .spidercms-social-link{justify-content:flex-start;padding:.75rem .85rem;border-radius:12px;background:#f8fafc;color:#111827;font-weight:700}.spidercms-social-contact .spidercms-social-link:hover{background:#f3e8ff;color:#7e22ce}@media(max-width:760px){.spidercms-social-float{top:auto;bottom:88px;transform:none}.spidercms-social-float.right{right:12px}.spidercms-social-float.left{left:12px}.spidercms-social-contact{left:12px;bottom:12px}.spidercms-social-header{display:none}}
</style>
<?php if (($social_settings['show_floating'] ?? '0') === '1'): ?>
<div class="spidercms-social-float <?php echo htmlspecialchars(($social_settings['floating_side'] ?? 'right') === 'left' ? 'left' : 'right'); ?>">
  <?php foreach ($social_links as $item) echo spidercms_social_render_link($item); ?>
</div>
<?php endif; ?>
<?php if (($social_settings['show_contact_widget'] ?? '0') === '1'): ?>
<div class="spidercms-social-contact">
  <div class="spidercms-social-contact-head">Szybki kontakt</div>
  <div class="spidercms-social-contact-body">
    <?php foreach ($social_links as $item) echo spidercms_social_render_link($item); ?>
  </div>
</div>
<?php endif; ?>
<script>
(function(){
  const linksHtml = <?php echo json_encode(array_map(fn($item) => spidercms_social_render_link($item), $social_links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>.join('');
  const showHeader = <?php echo json_encode(($social_settings['show_header'] ?? '0') === '1'); ?>;
  const showFooter = <?php echo json_encode(($social_settings['show_footer'] ?? '0') === '1'); ?>;
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    if (showHeader) {
      const nav = document.querySelector('.nav-menu') || document.querySelector('.header-container');
      if (nav && !document.querySelector('.spidercms-social-header')) {
        const box = document.createElement('div'); box.className = 'spidercms-social-header'; box.innerHTML = linksHtml; nav.appendChild(box);
      }
    }
    if (showFooter) {
      const footer = document.querySelector('.site-footer');
      if (footer && !document.querySelector('.spidercms-social-footer')) {
        const box = document.createElement('div'); box.className = 'spidercms-social-footer'; box.innerHTML = '<span class="spidercms-social-footer-title">Social media:</span>' + linksHtml; footer.appendChild(box);
      }
    }
  });
})();
</script>
PHP;
    $widget = str_replace('__PAYLOAD__', var_export(json_decode($json, true), true), $widget);
    file_put_contents(__DIR__ . '/social-widget.php', $widget);
}

function social_write_meta_file() {
    global $social_settings;
    $meta = [
        'enabled' => $social_settings['og_enabled'] ?? '1',
        'title' => $social_settings['og_title'] ?? '',
        'description' => $social_settings['og_description'] ?? '',
        'image' => $social_settings['og_image'] ?? '',
    ];
    $payload = var_export($meta, true);
    $file = <<<'PHP'
<?php
$social_meta = __PAYLOAD__;
if (($social_meta['enabled'] ?? '1') !== '1') return;
$og_title = trim((string)($social_meta['title'] ?? ''));
$og_desc = trim((string)($social_meta['description'] ?? ''));
$og_image = trim((string)($social_meta['image'] ?? ''));
if ($og_title === '' && defined('SITE_NAME')) $og_title = SITE_NAME;
if ($og_title !== '') echo '<meta property="og:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if ($og_desc !== '') echo '<meta property="og:description" content="' . htmlspecialchars($og_desc, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if ($og_image !== '') echo '<meta property="og:image" content="' . htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if (defined('SITE_NAME')) echo '<meta property="og:site_name" content="' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<meta property="og:type" content="website">' . "\n";
?>
PHP;
    $file = str_replace('__PAYLOAD__', $payload, $file);
    file_put_contents(__DIR__ . '/social-meta.php', $file);
}

function social_sync_in_pages() {
    if (!defined('ACTIVE_PAGES_DIR')) return 0;
    $updated = 0;
    $body_include = "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/social-widget.php'; ?>";
    $head_include = "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/social-meta.php'; ?>";
    foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        $changed = false;
        if (strpos($content, 'social-widget.php') === false && stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $body_include . "\n</body>", $content);
            $changed = true;
        }
        if (strpos($content, 'social-meta.php') === false && stripos($content, '</head>') !== false) {
            $content = str_ireplace('</head>', $head_include . "\n</head>", $content);
            $changed = true;
        }
        if ($changed) { file_put_contents($file, $content); $updated++; }
    }
    return $updated;
}

social_write_widget_file();
social_write_meta_file();

function chat_clean_text($value, $max = 2000) {
    $value = trim((string)$value);
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function chat_public_rate_limit($limit = 6, $window = 60) {
    global $chat_dir;
    $ip_hash = hash('sha256', spidercms_client_ip());
    $file = $chat_dir . '/rate_' . $ip_hash . '.json';
    $now = time();
    $items = file_exists($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($items)) $items = [];
    $items = array_values(array_filter($items, fn($t) => is_int($t) && $t > ($now - $window)));
    if (count($items) >= $limit) {
        return false;
    }
    $items[] = $now;
    file_put_contents($file, json_encode($items), LOCK_EX);
    return true;
}

function chat_message_looks_like_spam($message) {
    if (preg_match_all('~https?://|www\.~i', (string)$message, $m) > 2) return true;
    if (preg_match('/<\s*(script|iframe|object|embed|form|img)/i', (string)$message)) return true;
    return false;
}

function chat_valid_conversation_id($id) {
    return is_string($id) && preg_match('/^chat_[0-9]{14}_[a-f0-9]{10}$/', $id);
}

function chat_load_conversations() {
    global $chat_file;
    if (!file_exists($chat_file)) return [];
    $data = json_decode(file_get_contents($chat_file), true);
    return is_array($data) ? $data : [];
}

function chat_save_conversations($data) {
    global $chat_file;
    return file_put_contents($chat_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function chat_append_archive($conversation_id, $from, $body, $meta = []) {
    global $chat_archive_file, $chat_archive_index_file;
    if (!chat_valid_conversation_id($conversation_id)) return false;
    $entry = [
        'conversation_id' => $conversation_id,
        'from' => $from === 'admin' ? 'admin' : 'user',
        'body' => chat_clean_text($body, 4000),
        'time' => date('Y-m-d H:i:s'),
        'name' => chat_clean_text($meta['name'] ?? '', 120),
        'email' => chat_clean_text($meta['email'] ?? '', 160),
        'ip_hash' => $meta['ip_hash'] ?? '',
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "
";
    $ok = file_put_contents($chat_archive_file, $line, FILE_APPEND | LOCK_EX) !== false;
    if ($ok) {
        $index = file_exists($chat_archive_index_file) ? json_decode((string)file_get_contents($chat_archive_index_file), true) : [];
        if (!is_array($index)) $index = [];
        if (!isset($index[$conversation_id])) {
            $index[$conversation_id] = [
                'conversation_id' => $conversation_id,
                'name' => $entry['name'] ?: 'Gość strony',
                'email' => $entry['email'],
                'created_at' => $entry['time'],
                'updated_at' => $entry['time'],
                'messages_count' => 0,
            ];
        }
        if ($entry['name'] !== '') $index[$conversation_id]['name'] = $entry['name'];
        if ($entry['email'] !== '') $index[$conversation_id]['email'] = $entry['email'];
        $index[$conversation_id]['updated_at'] = $entry['time'];
        $index[$conversation_id]['messages_count'] = (int)($index[$conversation_id]['messages_count'] ?? 0) + 1;
        file_put_contents($chat_archive_index_file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return $ok;
}

function chat_load_archive_index() {
    global $chat_archive_index_file;
    $data = file_exists($chat_archive_index_file) ? json_decode((string)file_get_contents($chat_archive_index_file), true) : [];
    return is_array($data) ? $data : [];
}

function chat_load_archive_messages($conversation_id = '', $limit = 500) {
    global $chat_archive_file;
    if (!file_exists($chat_archive_file)) return [];
    if ($conversation_id !== '' && !chat_valid_conversation_id($conversation_id)) return [];
    $lines = @file($chat_archive_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        if ($conversation_id !== '' && ($row['conversation_id'] ?? '') !== $conversation_id) continue;
        $out[] = $row;
        if (count($out) >= $limit) break;
    }
    return array_reverse($out);
}

function chat_backfill_archive_from_conversations() {
    global $chat_archive_file;
    if (file_exists($chat_archive_file) && filesize($chat_archive_file) > 0) return;
    foreach (chat_load_conversations() as $cid => $conversation) {
        if (!chat_valid_conversation_id($cid)) continue;
        foreach (($conversation['messages'] ?? []) as $msg) {
            $old_time = $msg['time'] ?? date('Y-m-d H:i:s');
            $meta = [
                'name' => $conversation['name'] ?? 'Gość strony',
                'email' => $conversation['email'] ?? '',
                'ip_hash' => $conversation['ip_hash'] ?? '',
            ];
            chat_append_archive($cid, $msg['from'] ?? 'user', $msg['body'] ?? '', $meta);
        }
    }
}

function chat_get_visitor_id() {
    if (empty($_SESSION['spidercms_chat_id'])) {
        $_SESSION['spidercms_chat_id'] = 'chat_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
    }
    return $_SESSION['spidercms_chat_id'];
}

function chat_send_json($payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_email_log($message) {
    global $chat_dir;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($chat_dir . '/email.log', $line, FILE_APPEND | LOCK_EX);
}

function chat_smtp_read($socket) {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function chat_smtp_expect($socket, $codes, &$last_response, $step) {
    $last_response = chat_smtp_read($socket);
    $code = (int)substr($last_response, 0, 3);
    if (!in_array($code, (array)$codes, true)) {
        chat_email_log('SMTP BŁĄD [' . $step . '] Odpowiedź: ' . trim($last_response));
        return false;
    }
    return true;
}

function chat_smtp_command($socket, $command, $codes, &$last_response, $step) {
    fwrite($socket, $command . "\r\n");
    return chat_smtp_expect($socket, $codes, $last_response, $step);
}

function chat_send_smtp_mail($to, $subject, $body, $from_email, $from_name = 'SpiderCMS', $reply_to = '') {
    global $chat_settings;

    $host = trim((string)($chat_settings['smtp_host'] ?? ''));
    $port = (int)($chat_settings['smtp_port'] ?? 465);
    $secure = strtolower(trim((string)($chat_settings['smtp_secure'] ?? 'ssl')));
    $username = trim((string)($chat_settings['smtp_username'] ?? ''));
    $password = (string)($chat_settings['smtp_password'] ?? '');

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        chat_email_log('SMTP BŁĄD: brak hosta, portu, loginu albo hasła SMTP.');
        return false;
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]
    ]);

    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        chat_email_log('SMTP BŁĄD: nie można połączyć z ' . $remote . ' | ' . $errno . ' ' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 20);
    $last = '';

    if (!chat_smtp_expect($socket, [220], $last, 'connect')) { fclose($socket); return false; }

    $server_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!chat_smtp_command($socket, 'EHLO ' . $server_name, [250], $last, 'EHLO')) { fclose($socket); return false; }

    if ($secure === 'tls') {
        if (!chat_smtp_command($socket, 'STARTTLS', [220], $last, 'STARTTLS')) { fclose($socket); return false; }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            chat_email_log('SMTP BŁĄD: nie udało się włączyć TLS.');
            fclose($socket);
            return false;
        }
        if (!chat_smtp_command($socket, 'EHLO ' . $server_name, [250], $last, 'EHLO po STARTTLS')) { fclose($socket); return false; }
    }

    if (!chat_smtp_command($socket, 'AUTH LOGIN', [334], $last, 'AUTH LOGIN')) { fclose($socket); return false; }
    if (!chat_smtp_command($socket, base64_encode($username), [334], $last, 'SMTP USER')) { fclose($socket); return false; }
    if (!chat_smtp_command($socket, base64_encode($password), [235], $last, 'SMTP PASS')) { fclose($socket); return false; }

    $safe_from = str_replace(["\r", "\n"], '', $from_email);
    $safe_to = str_replace(["\r", "\n"], '', $to);

    if (!chat_smtp_command($socket, 'MAIL FROM:<' . $safe_from . '>', [250], $last, 'MAIL FROM')) { fclose($socket); return false; }
    if (!chat_smtp_command($socket, 'RCPT TO:<' . $safe_to . '>', [250, 251], $last, 'RCPT TO')) { fclose($socket); return false; }
    if (!chat_smtp_command($socket, 'DATA', [354], $last, 'DATA')) { fclose($socket); return false; }

    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encoded_from_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $encoded_from_name . ' <' . $safe_from . '>';
    $headers[] = 'To: <' . $safe_to . '>';
    $headers[] = 'Subject: ' . $encoded_subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: SpiderCMS SMTP';

    if ($reply_to !== '' && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: <' . str_replace(["\r", "\n"], '', $reply_to) . '>';
    }

    // SMTP wymaga kropkowania linii zaczynających się od kropki.
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body);
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";

    fwrite($socket, $message . "\r\n");
    if (!chat_smtp_expect($socket, [250], $last, 'wysyłka treści')) { fclose($socket); return false; }

    chat_smtp_command($socket, 'QUIT', [221, 250], $last, 'QUIT');
    fclose($socket);

    chat_email_log('SMTP OK | do=' . $safe_to . ' | from=' . $safe_from . ' | host=' . $host . ':' . $port . ' | secure=' . $secure);
    return true;
}

function chat_notify_admin_by_email($conversation_id, $visitor_name, $visitor_email, $message, $force = false) {
    global $chat_settings;

    if (!$force && (($chat_settings['email_notifications'] ?? '0') !== '1')) {
        chat_email_log('Powiadomienie pominięte: opcja email_notifications jest wyłączona.');
        return false;
    }

    $admin_email = trim((string)($chat_settings['admin_email'] ?? ''));
    if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        chat_email_log('Powiadomienie pominięte: brak poprawnego adresu administratora.');
        return false;
    }

    $visitor_name = chat_clean_text($visitor_name ?: 'Gość strony', 120);
    $visitor_email = chat_clean_text($visitor_email, 160);
    $message = chat_clean_text($message, 2000);

    $site = defined('SITE_NAME') ? SITE_NAME : 'SpiderCMS';
    $subject = 'Nowa wiadomość na chacie - ' . $site;

    $admin_url = '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $admin_url = $scheme . '://' . $host . rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/admin.php?tab=chat&conversation=' . rawurlencode((string)$conversation_id);
    }

    $body = "Nowa wiadomość z chatu strony.\n\n";
    $body .= "Od: " . $visitor_name . "\n";
    if ($visitor_email !== '') {
        $body .= "E-mail użytkownika: " . $visitor_email . "\n";
    }
    $body .= "Data: " . date('Y-m-d H:i:s') . "\n";
    $body .= "ID rozmowy: " . $conversation_id . "\n\n";
    $body .= "Treść wiadomości:\n" . $message . "\n";
    if ($admin_url !== '') {
        $body .= "\nOtwórz rozmowę w panelu:\n" . $admin_url . "\n";
    }

    $configured_from = trim((string)($chat_settings['from_email'] ?? ''));
    $domain = preg_replace('/^www\./', '', (string)$host);
    $domain = preg_replace('/:\d+$/', '', $domain);

    if ($configured_from !== '' && filter_var($configured_from, FILTER_VALIDATE_EMAIL)) {
        $from_email = $configured_from;
    } elseif ($domain !== '' && strpos($domain, '.') !== false) {
        $from_email = 'no-reply@' . $domain;
    } else {
        $from_email = $admin_email;
    }

    $mail_method = strtolower(trim((string)($chat_settings['mail_method'] ?? 'smtp')));
    $reply_to = '';
    if ($visitor_email !== '' && filter_var($visitor_email, FILTER_VALIDATE_EMAIL)) {
        $reply_to = $visitor_email;
    }

    if ($mail_method === 'smtp') {
        return chat_send_smtp_mail($admin_email, $subject, $body, $from_email, 'SpiderCMS', $reply_to);
    }

    // Awaryjny tryb PHP mail(). Na home.pl lepiej używać SMTP.
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safe_from_header = str_replace(["\r", "\n"], '', $from_email);
    $safe_admin_email = str_replace(["\r", "\n"], '', $admin_email);

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: SpiderCMS <' . $safe_from_header . '>';
    $headers[] = 'Sender: ' . $safe_from_header;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    if ($reply_to !== '') {
        $headers[] = 'Reply-To: <' . str_replace(["\r", "\n"], '', $reply_to) . '>';
    }

    $params = '';
    if (filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        $params = '-f' . escapeshellarg($from_email);
    }

    $ok = false;
    $last_error = '';

    set_error_handler(function($severity, $msg) use (&$last_error) {
        $last_error = $msg;
        return true;
    });

    if ($params !== '') {
        $ok = mail($safe_admin_email, $encoded_subject, $body, implode("\r\n", $headers), $params);
    } else {
        $ok = mail($safe_admin_email, $encoded_subject, $body, implode("\r\n", $headers));
    }

    restore_error_handler();

    chat_email_log(($ok ? 'PHP mail() OK' : 'PHP mail() BŁĄD') . ' | do=' . $safe_admin_email . ' | from=' . $from_email . ($last_error ? ' | PHP: ' . $last_error : ''));

    return $ok;
}

function chat_public_add_message($name, $email, $message, $website = '') {
    if (trim((string)$website) !== '') {
        return ['ok' => false, 'error' => 'Wiadomość odrzucona.'];
    }
    if (!chat_public_rate_limit()) {
        return ['ok' => false, 'error' => 'Wysyłasz zbyt wiele wiadomości. Spróbuj za chwilę.'];
    }
    if (chat_message_looks_like_spam($message)) {
        return ['ok' => false, 'error' => 'Wiadomość wygląda jak spam. Usuń nadmiar linków.'];
    }
    $name = chat_clean_text($name, 80);
    $email = chat_clean_text($email, 120);
    $message = chat_clean_text($message, 2000);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Wiadomość nie może być pusta.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Podaj poprawny adres e-mail albo zostaw pole puste.'];
    }
    $visitor_id = chat_get_visitor_id();
    $data = chat_load_conversations();
    if (!isset($data[$visitor_id])) {
        $data[$visitor_id] = [
            'id' => $visitor_id,
            'name' => $name ?: 'Gość strony',
            'email' => $email,
            'status' => 'open',
            'unread_admin' => 0,
            'unread_user' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'ip_hash' => hash('sha256', spidercms_client_ip()),
            'messages' => [],
        ];
    }
    if ($name !== '') $data[$visitor_id]['name'] = $name;
    if ($email !== '') $data[$visitor_id]['email'] = $email;
    $data[$visitor_id]['status'] = 'open';
    $data[$visitor_id]['updated_at'] = date('Y-m-d H:i:s');
    $data[$visitor_id]['unread_admin'] = (int)($data[$visitor_id]['unread_admin'] ?? 0) + 1;
    $data[$visitor_id]['messages'][] = [
        'from' => 'user',
        'body' => $message,
        'time' => date('Y-m-d H:i:s'),
    ];
    chat_append_archive($visitor_id, 'user', $message, [
        'name' => $data[$visitor_id]['name'] ?? 'Gość strony',
        'email' => $data[$visitor_id]['email'] ?? '',
        'ip_hash' => $data[$visitor_id]['ip_hash'] ?? '',
    ]);

    // Powiadomienie e-mail do administratora o nowej wiadomości użytkownika.
    chat_notify_admin_by_email(
        $visitor_id,
        $data[$visitor_id]['name'] ?? 'Gość strony',
        $data[$visitor_id]['email'] ?? '',
        $message
    );

    chat_save_conversations($data);
    return ['ok' => true, 'conversation_id' => $visitor_id];
}

function chat_public_get_messages() {
    $visitor_id = $_SESSION['spidercms_chat_id'] ?? '';
    if ($visitor_id === '') return ['ok' => true, 'messages' => []];
    $data = chat_load_conversations();
    if (!isset($data[$visitor_id])) return ['ok' => true, 'messages' => []];
    $data[$visitor_id]['unread_user'] = 0;
    chat_save_conversations($data);
    return ['ok' => true, 'messages' => $data[$visitor_id]['messages'] ?? []];
}

function chat_unread_count() {
    $count = 0;
    foreach (chat_load_conversations() as $conversation) {
        if (($conversation['status'] ?? 'open') !== 'archived') {
            $count += (int)($conversation['unread_admin'] ?? 0);
        }
    }
    return $count;
}

function chat_write_widget_file() {
    global $chat_settings;
    $enabled = !empty($chat_settings['enabled']);
    $title = $chat_settings['title'] ?? 'Masz pytanie?';
    $subtitle = $chat_settings['subtitle'] ?? 'Napisz do nas.';
    $welcome = $chat_settings['welcome'] ?? 'Cześć! W czym możemy pomóc?';
    $button = $chat_settings['button_text'] ?? 'Chat';
    $widget = <<<'PHP'
<?php
$chat_settings_file = __DIR__ . '/.chat/settings.json';
$chat_settings = file_exists($chat_settings_file) ? json_decode(file_get_contents($chat_settings_file), true) : [];
if (!is_array($chat_settings)) $chat_settings = [];
$chat_enabled = ($chat_settings['enabled'] ?? '__ENABLED__') === '1';
if (!$chat_enabled) return;
$chat_title = $chat_settings['title'] ?? '__TITLE__';
$chat_subtitle = $chat_settings['subtitle'] ?? '__SUBTITLE__';
$chat_welcome = $chat_settings['welcome'] ?? '__WELCOME__';
$chat_button = $chat_settings['button_text'] ?? '__BUTTON__';
?>
<div id="spidercms-chat">
  <button type="button" id="spidercms-chat-toggle">💬 <?php echo htmlspecialchars($chat_button); ?></button>
  <div id="spidercms-chat-box" aria-live="polite">
    <div class="spidercms-chat-head">
      <div>
        <strong><?php echo htmlspecialchars($chat_title); ?></strong>
        <span><?php echo htmlspecialchars($chat_subtitle); ?></span>
      </div>
      <button type="button" id="spidercms-chat-close">×</button>
    </div>
    <div id="spidercms-chat-messages">
      <div class="spidercms-chat-msg admin"><?php echo htmlspecialchars($chat_welcome); ?></div>
    </div>
    <form id="spidercms-chat-form">
      <div class="spidercms-chat-user-fields" id="spidercms-chat-user-fields">
        <input type="text" name="name" placeholder="Imię" maxlength="80" autocomplete="name">
        <input type="email" name="email" placeholder="E-mail, opcjonalnie" maxlength="120" autocomplete="email">
      </div>
      <div id="spidercms-chat-remembered" style="display:none;">
        <span></span>
        <button type="button" id="spidercms-chat-change-user">Zmień dane</button>
      </div>
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">
      <textarea name="message" rows="3" placeholder="Napisz wiadomość..." required maxlength="2000"></textarea>
      <button type="submit">Wyślij</button>
      <small id="spidercms-chat-status"></small>
    </form>
  </div>
</div>
<style>
#spidercms-chat{position:fixed;right:22px;bottom:22px;z-index:99999;font-family:system-ui,sans-serif;color:#0f172a}#spidercms-chat-toggle{border:0;border-radius:999px;background:#a855f7;color:#fff;padding:13px 18px;font-weight:800;box-shadow:0 12px 30px rgba(0,0,0,.28);cursor:pointer}#spidercms-chat-box{display:none;width:min(360px,calc(100vw - 32px));background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden}#spidercms-chat.open #spidercms-chat-box{display:block}#spidercms-chat.open #spidercms-chat-toggle{display:none}.spidercms-chat-head{background:linear-gradient(135deg,#7e22ce,#a855f7);color:#fff;padding:15px 16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.spidercms-chat-head span{display:block;font-size:12px;opacity:.88;margin-top:3px}.spidercms-chat-head button{background:transparent;border:0;color:#fff;font-size:26px;line-height:1;cursor:pointer}#spidercms-chat-messages{height:230px;overflow:auto;background:#f8fafc;padding:14px;display:flex;flex-direction:column;gap:9px}.spidercms-chat-msg{max-width:82%;padding:9px 11px;border-radius:14px;font-size:14px;line-height:1.35;word-break:break-word}.spidercms-chat-msg.admin{background:#fff;border:1px solid #e5e7eb;align-self:flex-start}.spidercms-chat-msg.user{background:#a855f7;color:#fff;align-self:flex-end}#spidercms-chat-form{padding:12px;background:#fff;border-top:1px solid #e5e7eb}.spidercms-chat-user-fields{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.spidercms-chat-user-fields.is-hidden{display:none}#spidercms-chat-remembered{align-items:center;justify-content:space-between;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;margin-bottom:8px;color:#475569;font-size:13px}#spidercms-chat-remembered button{border:0;background:transparent;color:#7e22ce;font-weight:800;cursor:pointer;padding:0;white-space:nowrap}#spidercms-chat.has-remembered-user .spidercms-chat-user-fields{display:none!important}#spidercms-chat input,#spidercms-chat textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:10px;padding:9px;font:inherit;font-size:14px}#spidercms-chat textarea{resize:vertical;min-height:72px}#spidercms-chat-form button[type=submit]{margin-top:8px;width:100%;border:0;border-radius:10px;background:#111827;color:#fff;padding:10px 12px;font-weight:800;cursor:pointer}#spidercms-chat-status{display:block;min-height:18px;margin-top:7px;color:#64748b}@media(max-width:520px){#spidercms-chat{right:12px;bottom:12px}.spidercms-chat-user-fields{grid-template-columns:1fr}}
</style>
<script>
(function(){
  const root = document.getElementById('spidercms-chat');
  if (!root) return;

  const box = document.getElementById('spidercms-chat-messages');
  const form = document.getElementById('spidercms-chat-form');
  const status = document.getElementById('spidercms-chat-status');
  const userFields = document.getElementById('spidercms-chat-user-fields');
  const remembered = document.getElementById('spidercms-chat-remembered');
  const rememberedText = remembered ? remembered.querySelector('span') : null;
  const changeUserBtn = document.getElementById('spidercms-chat-change-user');
  const nameInput = form ? form.querySelector('input[name="name"]') : null;
  const emailInput = form ? form.querySelector('input[name="email"]') : null;
  const messageInput = form ? form.querySelector('textarea[name="message"]') : null;
  const storageKey = 'spidercms_chat_user_v2';
  const oldStorageKey = 'spidercms_chat_user_v1';

  function readCookie(name){
    const parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift() || '');
    return '';
  }
  function writeCookie(name, value, days){
    const maxAge = days * 24 * 60 * 60;
    document.cookie = name + '=' + encodeURIComponent(value || '') + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }
  function deleteCookie(name){
    document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
  }
  function getSavedUser(){
    let data = {};
    try { data = JSON.parse(localStorage.getItem(storageKey) || localStorage.getItem(oldStorageKey) || '{}') || {}; } catch(e) { data = {}; }
    if (!data.name && !data.email) {
      data.name = readCookie('spidercms_chat_name');
      data.email = readCookie('spidercms_chat_email');
    }
    return {
      name: String(data.name || '').trim(),
      email: String(data.email || '').trim()
    };
  }
  function saveUser(name, email){
    const data = {
      name: String(name || '').trim(),
      email: String(email || '').trim()
    };
    if (!data.name && !data.email) return false;
    try { localStorage.setItem(storageKey, JSON.stringify(data)); localStorage.removeItem(oldStorageKey); } catch(e) {}
    writeCookie('spidercms_chat_name', data.name, 365);
    writeCookie('spidercms_chat_email', data.email, 365);
    return true;
  }
  function clearSavedUser(){
    try { localStorage.removeItem(storageKey); localStorage.removeItem(oldStorageKey); } catch(e) {}
    deleteCookie('spidercms_chat_name');
    deleteCookie('spidercms_chat_email');
  }
  function setUserFieldsVisible(visible){
    if (userFields) {
      userFields.style.display = visible ? '' : 'none';
      userFields.classList.toggle('is-hidden', !visible);
    }
    if (remembered) remembered.style.display = visible ? 'none' : 'flex';
    root.classList.toggle('has-remembered-user', !visible);
  }
  function applySavedUser(){
    const u = getSavedUser();
    const hasUser = !!(u.name || u.email);
    if (nameInput) nameInput.value = u.name;
    if (emailInput) emailInput.value = u.email;
    setUserFieldsVisible(!hasUser);
    if (rememberedText && hasUser) rememberedText.textContent = 'Piszesz jako ' + (u.name || u.email);
  }

  if (changeUserBtn) {
    changeUserBtn.addEventListener('click', function(){
      clearSavedUser();
      if (nameInput) nameInput.value = '';
      if (emailInput) emailInput.value = '';
      setUserFieldsVisible(true);
      if (nameInput) nameInput.focus();
    });
  }

  applySavedUser();

  function esc(s){
    return String(s || '').replace(/[&<>'"]/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
    });
  }
  function addMsg(from, body){
    const d = document.createElement('div');
    d.className = 'spidercms-chat-msg ' + (from === 'admin' ? 'admin' : 'user');
    d.innerHTML = esc(body);
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
  }
  function render(messages){
    box.innerHTML = '<div class="spidercms-chat-msg admin">' + esc(<?php echo json_encode($chat_welcome, JSON_UNESCAPED_UNICODE); ?>) + '</div>';
    (messages || []).forEach(m => addMsg(m.from, m.body));
  }

  const endpoint = <?php echo json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/') . '/admin.php'); ?>;

  function load(){
    fetch(endpoint + '?action=chat_public_get', {credentials:'same-origin'})
      .then(r => r.json())
      .then(d => { if (d.ok) render(d.messages); })
      .catch(() => {});
  }

  const toggle = document.getElementById('spidercms-chat-toggle');
  const close = document.getElementById('spidercms-chat-close');
  if (toggle) toggle.addEventListener('click', function(){ root.classList.add('open'); applySavedUser(); load(); });
  if (close) close.addEventListener('click', function(){ root.classList.remove('open'); });

  form.addEventListener('submit', function(e){
    e.preventDefault();

    const typedName = nameInput ? nameInput.value : '';
    const typedEmail = emailInput ? emailInput.value : '';
    const existingUser = getSavedUser();
    const finalName = String(typedName || existingUser.name || '').trim();
    const finalEmail = String(typedEmail || existingUser.email || '').trim();

    if (finalName || finalEmail) {
      saveUser(finalName, finalEmail);
      applySavedUser();
    }

    const fd = new FormData(form);
    fd.set('name', finalName);
    fd.set('email', finalEmail);
    fd.set('action', 'chat_public_send');

    status.textContent = 'Wysyłanie...';

    fetch(endpoint, {method:'POST', body:fd, credentials:'same-origin'})
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          addMsg('user', fd.get('message'));
          if (messageInput) messageInput.value = '';
          status.textContent = 'Wysłano.';
          setTimeout(load, 400);
        } else {
          status.textContent = d.error || 'Nie udało się wysłać.';
        }
      })
      .catch(() => status.textContent = 'Błąd połączenia.');
  });

  setInterval(function(){ if (root.classList.contains('open')) load(); }, 7000);
})();
</script>
PHP;
    $widget = str_replace('__ENABLED__', $enabled ? '1' : '0', $widget);
    $widget = str_replace('__TITLE__', addslashes($title), $widget);
    $widget = str_replace('__SUBTITLE__', addslashes($subtitle), $widget);
    $widget = str_replace('__WELCOME__', addslashes($welcome), $widget);
    $widget = str_replace('__BUTTON__', addslashes($button), $widget);
    file_put_contents(__DIR__ . '/chat-widget.php', $widget);
}

function chat_sync_widget_in_pages() {
    if (!defined('ACTIVE_PAGES_DIR')) return 0;
    $include = "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/chat-widget.php'; ?>";
    $updated = 0;
    foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        if (strpos($content, 'chat-widget.php') !== false) continue;
        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $include . "\n</body>", $content);
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}

chat_write_widget_file();


// ----------------------------------------------------------------------
// STATYSTYKI ODWIEDZIN – flat-file, bez bazy danych
// ----------------------------------------------------------------------
$stats_dir = __DIR__ . '/.stats';
if (!is_dir($stats_dir)) {
    @mkdir($stats_dir, 0755, true);
}
spidercms_write_htaccess($stats_dir, "Require all denied\nDeny from all\nOptions -Indexes\n");

$stats_settings_file = $stats_dir . '/settings.json';
$stats_settings_defaults = [
    'enabled' => '1',
    'throttle_minutes' => '30',
    'online_minutes' => '5',
    'ignore_admin' => '1',
    'ignore_bots' => '1',
];
$stats_settings_loaded = file_exists($stats_settings_file) ? json_decode((string)file_get_contents($stats_settings_file), true) : [];
if (!is_array($stats_settings_loaded)) $stats_settings_loaded = [];
$stats_settings = array_merge($stats_settings_defaults, $stats_settings_loaded);

function stats_json_read($name, $default = []) {
    global $stats_dir;
    $file = $stats_dir . '/' . $name;
    if (!file_exists($file)) return $default;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function stats_json_write($name, $data) {
    global $stats_dir;
    return file_put_contents($stats_dir . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function stats_clean_text($value, $max = 250) {
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
    return substr($value, 0, $max);
}

function stats_is_bot($ua) {
    return preg_match('~bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegrambot|curl|wget|python|monitor|uptime~i', (string)$ua) === 1;
}

function stats_device_type($ua) {
    $ua = strtolower((string)$ua);
    if (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) return 'Tablet';
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) return 'Telefon';
    return 'Komputer';
}

function stats_browser_name($ua) {
    $ua = (string)$ua;
    if (stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Firefox/') !== false) return 'Firefox';
    if (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome/') === false) return 'Safari';
    if (stripos($ua, 'Chrome/') !== false) return 'Chrome';
    return 'Inna';
}

function stats_referrer_domain($ref) {
    $ref = trim((string)$ref);
    if ($ref === '') return 'Wejście bezpośrednie';
    $host = parse_url($ref, PHP_URL_HOST);
    return $host ? strtolower($host) : 'Inne';
}

function stats_increment_assoc(&$array, $key, $by = 1) {
    $key = stats_clean_text($key, 180);
    if ($key === '') $key = 'Inne';
    $array[$key] = (int)($array[$key] ?? 0) + $by;
}

function stats_track_event($payload) {
    global $stats_settings;
    if (($stats_settings['enabled'] ?? '1') !== '1') return ['ok' => false, 'ignored' => 'disabled'];
    if (($stats_settings['ignore_admin'] ?? '1') === '1' && !empty($_SESSION['logged_in'])) return ['ok' => true, 'ignored' => 'admin'];

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (($stats_settings['ignore_bots'] ?? '1') === '1' && stats_is_bot($ua)) return ['ok' => true, 'ignored' => 'bot'];

    $path = stats_clean_text($payload['path'] ?? ($_SERVER['HTTP_REFERER'] ?? '/'), 350);
    $title = stats_clean_text($payload['title'] ?? '', 180);
    $ref = stats_clean_text($payload['referrer'] ?? '', 400);
    $visitor = stats_clean_text($payload['visitor'] ?? '', 120);
    if ($visitor === '') $visitor = hash('sha256', spidercms_client_ip() . '|' . $ua);

    $today = date('Y-m-d');
    $now = time();
    $visitor_hash = hash('sha256', $visitor . '|' . spidercms_client_ip());
    $throttle = max(1, (int)($stats_settings['throttle_minutes'] ?? 30)) * 60;

    $recent = stats_json_read('recent.json', []);
    foreach ($recent as $k => $t) {
        if (!is_int($t) && !ctype_digit((string)$t)) unset($recent[$k]);
        elseif ((int)$t < $now - 86400) unset($recent[$k]);
    }
    $recent_key = hash('sha256', $visitor_hash . '|' . $path);
    if (isset($recent[$recent_key]) && (int)$recent[$recent_key] > $now - $throttle) {
        stats_json_write('recent.json', $recent);
        return ['ok' => true, 'ignored' => 'throttle'];
    }
    $recent[$recent_key] = $now;
    stats_json_write('recent.json', $recent);

    $daily = stats_json_read('visits_daily.json', []);
    stats_increment_assoc($daily, $today);
    stats_json_write('visits_daily.json', $daily);

    $unique = stats_json_read('unique_daily.json', []);
    if (!isset($unique[$today]) || !is_array($unique[$today])) $unique[$today] = [];
    $unique[$today][$visitor_hash] = $now;
    if (count($unique) > 120) {
        ksort($unique);
        $unique = array_slice($unique, -120, null, true);
    }
    stats_json_write('unique_daily.json', $unique);

    $pages = stats_json_read('page_views.json', []);
    if (!isset($pages[$path])) $pages[$path] = ['title' => $title ?: $path, 'views' => 0, 'last' => ''];
    $pages[$path]['title'] = $title ?: ($pages[$path]['title'] ?? $path);
    $pages[$path]['views'] = (int)($pages[$path]['views'] ?? 0) + 1;
    $pages[$path]['last'] = date('Y-m-d H:i:s');
    stats_json_write('page_views.json', $pages);

    $devices = stats_json_read('devices.json', []);
    stats_increment_assoc($devices, stats_device_type($ua));
    stats_json_write('devices.json', $devices);

    $browsers = stats_json_read('browsers.json', []);
    stats_increment_assoc($browsers, stats_browser_name($ua));
    stats_json_write('browsers.json', $browsers);

    $refs = stats_json_read('referrers.json', []);
    $own_host = $_SERVER['HTTP_HOST'] ?? '';
    $ref_domain = stats_referrer_domain($ref);
    if ($ref_domain !== '' && $ref_domain !== strtolower($own_host)) stats_increment_assoc($refs, $ref_domain);
    stats_json_write('referrers.json', $refs);

    $online = stats_json_read('online.json', []);
    foreach ($online as $k => $row) {
        if (!is_array($row) || (int)($row['time'] ?? 0) < $now - max(60, (int)($stats_settings['online_minutes'] ?? 5) * 60)) unset($online[$k]);
    }
    $online[$visitor_hash] = ['time' => $now, 'path' => $path, 'title' => $title ?: $path];
    stats_json_write('online.json', $online);

    $events_file = $GLOBALS['stats_dir'] . '/events.jsonl';
    $event = ['time' => date('Y-m-d H:i:s'), 'path' => $path, 'title' => $title, 'device' => stats_device_type($ua), 'browser' => stats_browser_name($ua), 'referrer' => $ref_domain];
    @file_put_contents($events_file, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    return ['ok' => true];
}

function stats_get_summary() {
    global $stats_settings;
    $daily = stats_json_read('visits_daily.json', []);
    $unique = stats_json_read('unique_daily.json', []);
    $pages = stats_json_read('page_views.json', []);
    $devices = stats_json_read('devices.json', []);
    $browsers = stats_json_read('browsers.json', []);
    $refs = stats_json_read('referrers.json', []);
    $online = stats_json_read('online.json', []);
    $now = time();
    foreach ($online as $k => $row) {
        if (!is_array($row) || (int)($row['time'] ?? 0) < $now - max(60, (int)($stats_settings['online_minutes'] ?? 5) * 60)) unset($online[$k]);
    }
    stats_json_write('online.json', $online);

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $last7 = 0;
    $last30 = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime('-' . $i . ' day'));
        $v = (int)($daily[$d] ?? 0);
        $last30[$d] = $v;
        if ($i < 7) $last7 += $v;
    }
    $month_prefix = date('Y-m');
    $month = 0;
    foreach ($daily as $d => $v) if (strpos($d, $month_prefix) === 0) $month += (int)$v;
    $total = array_sum(array_map('intval', $daily));

    $unique_today = isset($unique[$today]) && is_array($unique[$today]) ? count($unique[$today]) : 0;

    uasort($pages, fn($a,$b) => (int)($b['views'] ?? 0) <=> (int)($a['views'] ?? 0));
    arsort($devices); arsort($browsers); arsort($refs);
    return compact('daily','pages','devices','browsers','refs','online','last30','today','yesterday','last7','month','total','unique_today');
}

function stats_write_widget_file() {
    $widget = <<<'PHP'
<?php
$stats_settings_file = __DIR__ . '/.stats/settings.json';
$stats_settings = file_exists($stats_settings_file) ? json_decode((string)file_get_contents($stats_settings_file), true) : [];
if (!is_array($stats_settings)) $stats_settings = [];
if (($stats_settings['enabled'] ?? '1') !== '1') return;
?>
<script>
(function(){
  try{
    var key = 'spidercms_stats_visitor_v1';
    var visitor = localStorage.getItem(key);
    if(!visitor){ visitor = 'v_' + Date.now() + '_' + Math.random().toString(16).slice(2); localStorage.setItem(key, visitor); }
    var payload = new FormData();
    payload.set('action','stats_track');
    payload.set('visitor', visitor);
    payload.set('path', location.pathname + location.search);
    payload.set('title', document.title || '');
    payload.set('referrer', document.referrer || '');
    var candidates = [];
    <?php if (defined('BASE_URL') && BASE_URL !== ''): ?>
      candidates.push(<?php echo json_encode(rtrim(BASE_URL, '/') . '/admin.php'); ?>);
    <?php endif; ?>
    candidates.push('admin.php','../admin.php','../../admin.php','../../../admin.php','/admin.php');
    var i = 0;
    function sendNext(){
      if(i >= candidates.length) return;
      var url = candidates[i++];
      fetch(url, {method:'POST', body:payload, credentials:'same-origin', cache:'no-store'}).then(function(r){
        if(!r.ok) throw new Error('bad');
        return r.json();
      }).catch(sendNext);
    }
    if('requestIdleCallback' in window) requestIdleCallback(sendNext); else setTimeout(sendNext, 800);
  }catch(e){}
})();
</script>
PHP;
    file_put_contents(__DIR__ . '/stats-widget.php', $widget);
}

function stats_sync_widget_in_pages() {
    if (!defined('ACTIVE_PAGES_DIR')) return 0;
    $include = "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/stats-widget.php'; ?>";
    $updated = 0;
    foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        if (strpos($content, 'stats-widget.php') !== false) continue;
        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $include . "\n</body>", $content);
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}

stats_write_widget_file();
// UWAGA: nie synchronizujemy stron automatycznie przy każdym odświeżeniu panelu.
// stats_sync_widget_in_pages();


// ----------------------------------------------------------------------
// SLIDERY ZDJĘĆ – generator shortcode [slider id="..."]
// ----------------------------------------------------------------------
$sliders_dir = __DIR__ . '/.sliders';
if (!is_dir($sliders_dir)) {
    @mkdir($sliders_dir, 0755, true);
}
spidercms_write_htaccess($sliders_dir, "Require all denied\nDeny from all\nOptions -Indexes\n");
$sliders_file = $sliders_dir . '/sliders.json';

function slider_slug($value) {
    $value = trim((string)$value);
    $value = mb_strtolower($value, 'UTF-8');
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z'];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'slider';
}

function slider_clean_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('~^(https?:)?//|^data:image/|^/~i', $url)) return $url;
    return ltrim($url, '/');
}

function slider_load_all() {
    global $sliders_file;
    if (!file_exists($sliders_file)) return [];
    $data = json_decode((string)file_get_contents($sliders_file), true);
    return is_array($data) ? $data : [];
}

function slider_save_all($data) {
    global $sliders_file;
    if (!is_array($data)) $data = [];
    file_put_contents($sliders_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function slider_normalize($input) {
    $id = slider_slug($input['id'] ?? ($input['name'] ?? 'slider'));
    $name = trim((string)($input['name'] ?? 'Slider'));
    if ($name === '') $name = 'Slider';
    $style = in_array(($input['style'] ?? 'modern'), ['modern','glass','minimal','dark','cards'], true) ? $input['style'] : 'modern';
    $fit_mode = in_array(($input['fit_mode'] ?? 'contain'), ['contain','cover','auto'], true) ? $input['fit_mode'] : 'contain';
    $height = max(180, min(900, (int)($input['height'] ?? 420)));
    $autoplay = !empty($input['autoplay']) ? '1' : '0';
    $interval = max(1500, min(20000, (int)($input['interval'] ?? 4500)));
    $arrows = !empty($input['arrows']) ? '1' : '0';
    $dots = !empty($input['dots']) ? '1' : '0';
    $overlay = !empty($input['overlay']) ? '1' : '0';
    $radius = max(0, min(40, (int)($input['radius'] ?? 18)));
    $images = [];
    $urls = $input['image_url'] ?? [];
    $titles = $input['image_title'] ?? [];
    $descs = $input['image_desc'] ?? [];
    if (!is_array($urls)) $urls = [$urls];
    if (!is_array($titles)) $titles = [$titles];
    if (!is_array($descs)) $descs = [$descs];
    $urls = array_values($urls);
    $titles = array_values($titles);
    $descs = array_values($descs);
    foreach ($urls as $i => $url) {
        $url = slider_clean_url($url);
        if ($url === '') continue;
        $images[] = [
            'url' => $url,
            'title' => trim((string)($titles[$i] ?? '')),
            'desc' => trim((string)($descs[$i] ?? '')),
        ];
    }
    return compact('id','name','style','fit_mode','height','autoplay','interval','arrows','dots','overlay','radius','images');
}

function slider_write_widget_file() {
    $sliders = slider_load_all();
    $payload = var_export($sliders, true);
    $widget = <<<'PHP'
<?php
$spidercms_sliders = __PAYLOAD__;
if (!is_array($spidercms_sliders) || empty($spidercms_sliders)) return;
?>
<style>
.spidercms-slider{position:relative;overflow:hidden;width:100%;margin:1.5rem auto;border-radius:var(--slider-radius,18px);background:#0f172a;box-shadow:0 20px 50px rgba(15,23,42,.18)}
.spidercms-slider-track{display:flex;height:100%;transition:transform .55s ease;will-change:transform}.spidercms-slide{position:relative;min-width:100%;height:100%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--slider-bg,#0f172a)}.spidercms-slide img{width:100%;height:100%;display:block}.spidercms-slider.fit-contain .spidercms-slide img{object-fit:contain;max-width:100%;max-height:100%;background:transparent}.spidercms-slider.fit-cover .spidercms-slide img{object-fit:cover}.spidercms-slider.fit-auto .spidercms-slide img{width:auto;height:auto;max-width:100%;max-height:100%;object-fit:contain}.spidercms-slide-caption{position:absolute;left:0;right:0;bottom:0;padding:clamp(1rem,3vw,2rem);color:#fff;background:linear-gradient(to top,rgba(0,0,0,.72),rgba(0,0,0,0));text-shadow:0 2px 10px rgba(0,0,0,.35)}.spidercms-slide-caption h3{margin:0 0 .35rem;font-size:clamp(1.35rem,3vw,2.4rem);line-height:1.1}.spidercms-slide-caption p{margin:0;max-width:720px;opacity:.92}.spidercms-slider.no-overlay .spidercms-slide-caption{background:transparent;text-shadow:0 2px 12px rgba(0,0,0,.65)}.spidercms-slider-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:44px;height:44px;border:0;border-radius:999px;background:rgba(255,255,255,.88);color:#111827;font-size:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 25px rgba(0,0,0,.18)}.spidercms-slider-arrow:hover{background:#fff}.spidercms-slider-prev{left:16px}.spidercms-slider-next{right:16px}.spidercms-slider-dots{position:absolute;left:0;right:0;bottom:14px;z-index:6;display:flex;gap:8px;justify-content:center}.spidercms-slider-dot{width:10px;height:10px;border-radius:999px;border:0;background:rgba(255,255,255,.55);cursor:pointer}.spidercms-slider-dot.active{background:#fff;transform:scale(1.25)}.spidercms-slider.style-glass{box-shadow:0 24px 70px rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.25)}.spidercms-slider.style-minimal{box-shadow:none}.spidercms-slider.style-dark{background:#020617;box-shadow:0 24px 60px rgba(0,0,0,.35)}.spidercms-slider.style-cards{padding:10px;background:#fff}.spidercms-slider.style-cards .spidercms-slide img{border-radius:calc(var(--slider-radius,18px) - 6px)}@media(max-width:700px){.spidercms-slider{height:min(var(--slider-height,420px),60vh)!important}.spidercms-slider-arrow{width:38px;height:38px}.spidercms-slide-caption{padding:1rem 1rem 2.2rem}}
</style>
<script>
(function(){
  const sliders = <?php echo json_encode($spidercms_sliders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function publicUrl(url){url=String(url||'').trim(); if(!url) return ''; if(/^(https?:)?\/\//i.test(url)||url[0]==='/'||/^data:image\//i.test(url)) return url; const depth=(location.pathname.match(/\//g)||[]).length-1; return '../'.repeat(Math.max(0,depth)) + url.replace(/^\/+/, '');}
  function renderSlider(id){
    const s = sliders[id]; if(!s || !Array.isArray(s.images) || !s.images.length) return '<div class="spidercms-slider-missing">Brak slidera: '+esc(id)+'</div>';
    const h = parseInt(s.height||420,10); const radius=parseInt(s.radius||18,10);
    const fit = ['contain','cover','auto'].includes(String(s.fit_mode||'contain')) ? String(s.fit_mode||'contain') : 'contain';
    let html = '<div class="spidercms-slider style-'+esc(s.style||'modern')+' fit-'+esc(fit)+(s.overlay==='1'?'':' no-overlay')+'" data-autoplay="'+esc(s.autoplay||'0')+'" data-interval="'+esc(s.interval||4500)+'" style="height:'+h+'px;--slider-height:'+h+'px;--slider-radius:'+radius+'px">';
    html += '<div class="spidercms-slider-track">';
    s.images.forEach(function(img){ html += '<div class="spidercms-slide"><img src="'+esc(publicUrl(img.url))+'" alt="'+esc(img.title||s.name||'Slider')+'">'; if(img.title || img.desc){ html += '<div class="spidercms-slide-caption">'+(img.title?'<h3>'+esc(img.title)+'</h3>':'')+(img.desc?'<p>'+esc(img.desc)+'</p>':'')+'</div>'; } html += '</div>'; });
    html += '</div>';
    if(s.arrows==='1' && s.images.length>1){ html += '<button class="spidercms-slider-arrow spidercms-slider-prev" type="button" aria-label="Poprzedni">‹</button><button class="spidercms-slider-arrow spidercms-slider-next" type="button" aria-label="Następny">›</button>'; }
    if(s.dots==='1' && s.images.length>1){ html += '<div class="spidercms-slider-dots">'+s.images.map(function(_,i){return '<button class="spidercms-slider-dot'+(i===0?' active':'')+'" type="button" data-i="'+i+'"></button>';}).join('')+'</div>'; }
    html += '</div>'; return html;
  }
  function replaceShortcodes(root){
    root = root || document.body;
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:function(n){return n.nodeValue.indexOf('[slider')!==-1?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;}});
    const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(n){
      const span=document.createElement('span');
      span.innerHTML = n.nodeValue.replace(/\[slider\s+id=["']?([a-zA-Z0-9_-]+)["']?\]/g,function(_,id){return renderSlider(id);});
      n.parentNode.replaceChild(span,n);
    });
  }
  function initSlider(el){
    const track=el.querySelector('.spidercms-slider-track'); const slides=el.querySelectorAll('.spidercms-slide'); if(!track||slides.length<2) return;
    let i=0; const dots=el.querySelectorAll('.spidercms-slider-dot');
    function go(n){ i=(n+slides.length)%slides.length; track.style.transform='translateX('+(-i*100)+'%)'; dots.forEach(function(d,k){d.classList.toggle('active',k===i);}); }
    const prev=el.querySelector('.spidercms-slider-prev'); const next=el.querySelector('.spidercms-slider-next'); if(prev) prev.onclick=function(){go(i-1)}; if(next) next.onclick=function(){go(i+1)}; dots.forEach(function(d){d.onclick=function(){go(parseInt(d.dataset.i||0,10));};});
    if(el.dataset.autoplay==='1'){ setInterval(function(){go(i+1)}, Math.max(1500, parseInt(el.dataset.interval||4500,10))); }
  }
  function boot(){ replaceShortcodes(document.body); document.querySelectorAll('.spidercms-slider').forEach(initSlider); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
</script>
PHP;
    $widget = str_replace('__PAYLOAD__', $payload, $widget);
    file_put_contents(__DIR__ . '/slider-widget.php', $widget);
}

function slider_sync_widget_in_pages() {
    if (!defined('ACTIVE_PAGES_DIR')) return 0;
    $include = "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/slider-widget.php'; ?>";
    $updated = 0;
    foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        if (strpos($content, 'slider-widget.php') !== false) continue;
        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $include . "\n</body>", $content);
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}

slider_write_widget_file();
// UWAGA: nie synchronizujemy stron automatycznie przy każdym odświeżeniu panelu.
// slider_sync_widget_in_pages();


// ----------------------------------------------------------------------
// Funkcja aktualizująca kolory we wszystkich stronach
// ----------------------------------------------------------------------
function update_all_pages_colors() {
    global $theme;
    $root_block = ":root {\n" .
        " --primary: " . ($theme['primary'] ?? '#a855f7') . ";\n" .
        " --primary-dark: " . ($theme['primary-dark'] ?? '#7e22ce') . ";\n" .
        " --accent: " . ($theme['accent'] ?? '#2563eb') . ";\n" .
        " --page-bg: " . ($theme['page-bg'] ?? '#f9fafb') . ";\n" .
        " --page-text: " . ($theme['page-text'] ?? '#111827') . ";\n" .
        " --header-bg: " . ($theme['header-bg'] ?? '#ffffff') . ";\n" .
        " --header-text: " . ($theme['header-text'] ?? '#374151') . ";\n" .
        " --footer-bg: " . ($theme['footer-bg'] ?? '#1f2937') . ";\n" .
        " --footer-text: " . ($theme['footer-text'] ?? '#f3f4f6') . ";\n" .
        " --footer-muted: " . ($theme['footer-muted'] ?? '#9ca3af') . ";\n" .
        " --link-color: " . ($theme['link-color'] ?? '#a855f7') . ";\n" .
        " --button-bg: " . ($theme['button-bg'] ?? '#a855f7') . ";\n" .
        " --button-text: " . ($theme['button-text'] ?? '#ffffff') . ";\n" .
        " --font-family: " . ($theme['font-family'] ?? 'system-ui, sans-serif') . ";\n" .
        " --header-height: " . css_px($theme['header-height'] ?? '74', 74) . ";\n" .
        " --logo-height: " . css_px($theme['logo-height'] ?? '100', 100) . ";\n" .
        " --content-width: " . css_px($theme['content-width'] ?? '1240', 1240) . ";\n" .
        " --radius: " . css_px($theme['border-radius'] ?? '10', 10) . ";\n" .
        " --header-shadow: " . (!empty($theme['shadow-enabled']) ? '0 2px 10px rgba(0,0,0,0.08)' : 'none') . ";\n" .
        " --menu-position: " . (($theme['menu-position'] ?? 'right')) . ";\n" .
        " --spidercms-logo-max-height: min(var(--logo-height,100px), calc(var(--header-height,74px) - 14px));\n" .
        " --gray50: #f9fafb;\n" .
        " --gray800: #1f2937;\n" .
        "}";
    $updated = 0;
    $files = glob(ACTIVE_PAGES_DIR . '/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $new_content = preg_replace('/:root\s*\{[^}]*\}/s', $root_block, $content, 1, $count);
        if ($count > 0) {
            file_put_contents($file, $new_content);
            $updated++;
        }
    }
    return $updated;
}


// ----------------------------------------------------------------------
// Wymuszenie aktualnego presetu/motywu na wszystkich stronach
// Poprzednia wersja zapisywała preset, ale nie każda starsza strona
// miała poprawnie aktualizowany blok :root. Ten fix dodaje końcowy CSS
// z aktualnymi zmiennymi motywu do <head>, więc działa też na index.php.
// ----------------------------------------------------------------------
function spidercms_apply_theme_css_to_all_pages() {
    global $theme;
    // Zapisz globalny CSS presetu, żeby działał także na starszych stronach.
    if (function_exists('spidercms_write_theme_css_file')) { spidercms_write_theme_css_file(); }
    $updated = 0;
    $files = [];

    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    foreach (spidercms_available_page_folders() as $folder) {
        $dir = spidercms_page_folder_dir($folder);
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    $root_index = __DIR__ . '/index.php';
    if (is_file($root_index)) {
        $files[] = $root_index;
    }

    $files = array_values(array_unique($files));

    $shadow = !empty($theme['shadow-enabled']) ? '0 2px 10px rgba(0,0,0,0.08)' : 'none';
    $menu_position = $theme['menu-position'] ?? 'right';
    if (!in_array($menu_position, ['left','center','right'], true)) {
        $menu_position = 'right';
    }

    $css = "<style id=\"spidercms-theme-preset-fix\">\n";
    $css .= ":root{\n";
    $css .= "--primary:" . ($theme['primary'] ?? '#a855f7') . ";\n";
    $css .= "--primary-dark:" . ($theme['primary-dark'] ?? '#7e22ce') . ";\n";
    $css .= "--accent:" . ($theme['accent'] ?? '#2563eb') . ";\n";
    $css .= "--page-bg:" . ($theme['page-bg'] ?? '#f9fafb') . ";\n";
    $css .= "--page-text:" . ($theme['page-text'] ?? '#111827') . ";\n";
    $css .= "--header-bg:" . ($theme['header-bg'] ?? '#ffffff') . ";\n";
    $css .= "--header-text:" . ($theme['header-text'] ?? '#374151') . ";\n";
    $css .= "--footer-bg:" . ($theme['footer-bg'] ?? '#1f2937') . ";\n";
    $css .= "--footer-text:" . ($theme['footer-text'] ?? '#f3f4f6') . ";\n";
    $css .= "--footer-muted:" . ($theme['footer-muted'] ?? '#9ca3af') . ";\n";
    $css .= "--link-color:" . ($theme['link-color'] ?? '#a855f7') . ";\n";
    $css .= "--button-bg:" . ($theme['button-bg'] ?? '#a855f7') . ";\n";
    $css .= "--button-text:" . ($theme['button-text'] ?? '#ffffff') . ";\n";
    $css .= "--font-family:" . ($theme['font-family'] ?? 'system-ui, sans-serif') . ";\n";
    $css .= "--header-height:" . css_px($theme['header-height'] ?? '74', 74) . ";\n";
    $css .= "--logo-height:" . css_px($theme['logo-height'] ?? '100', 100) . ";\n";
    $css .= "--content-width:" . css_px($theme['content-width'] ?? '1240', 1240) . ";\n";
    $css .= "--radius:" . css_px($theme['border-radius'] ?? '10', 10) . ";\n";
    $css .= "--header-shadow:" . $shadow . ";\n";
    $css .= "--menu-position:" . $menu_position . ";\n";
    $css .= "--spidercms-logo-max-height:min(var(--logo-height,100px), calc(var(--header-height,74px) - 14px));\n";
    $css .= "}\n";
    $css .= "body{background:var(--page-bg)!important;color:var(--page-text)!important;font-family:var(--font-family)!important;}\n";
    $css .= "a{color:var(--link-color);}\n";
    $css .= ".site-header{background:var(--header-bg)!important;color:var(--header-text)!important;box-shadow:var(--header-shadow)!important;}\n";
    $css .= ".site-header a,.nav-menu a{color:var(--header-text)!important;}\n";
    $css .= ".site-footer{background:var(--footer-bg)!important;color:var(--footer-text)!important;}\n";
    $css .= ".footer-bottom,.footer-col a{color:var(--footer-muted)!important;}\n";
    $css .= ".cms-btn,button[type=submit]{background:var(--button-bg);color:var(--button-text);}\n";
    $css .= "main{max-width:var(--content-width,1240px);margin-left:auto!important;margin-right:auto!important;}\n";
    $css .= ".logo img{max-height:var(--spidercms-logo-max-height)!important;width:auto!important;object-fit:contain!important;}\n";
    $css .= ".site-header.menu-left .header-container{justify-content:flex-start!important;gap:2rem;}\n";
    $css .= ".site-header.menu-center .header-container{justify-content:center!important;gap:2rem;}\n";
    $css .= ".site-header.menu-right .header-container{justify-content:space-between!important;}\n";
    $css .= "</style>";

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') continue;
        $content = file_get_contents($file);
        $original = $content;
        $content = preg_replace('~<style\s+id=["\']spidercms-theme-preset-fix["\'][^>]*>.*?</style>\s*~is', '', $content);
        if (stripos($content, '</head>') !== false) {
            $content = str_ireplace('</head>', $css . "\n</head>", $content);
        } elseif (stripos($content, '<body') !== false) {
            $content = preg_replace('~(<body\b[^>]*>)~i', "$1\n" . $css, $content, 1);
        }
        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }
    if (function_exists('spidercms_sync_theme_css_link_in_pages')) {
        $updated += spidercms_sync_theme_css_link_in_pages();
    }
    return $updated;
}


// ----------------------------------------------------------------------
// GLOBALNY CSS MOTYWU – mocniejsza poprawka presetów
// Presety zapisują .theme.json, ale starsze strony mają często własny CSS.
// Ten moduł generuje /assets/spidercms-theme.css i podpina go do stron
// jako końcowy arkusz stylów z !important.
// ----------------------------------------------------------------------
function spidercms_theme_public_base_url() {
    $base = defined('BASE_URL') ? trim((string)BASE_URL) : '';
    if ($base === '') return '';
    return rtrim($base, '/');
}

function spidercms_theme_css_string($theme_data = null) {
    global $theme;
    $t = is_array($theme_data) ? $theme_data : (is_array($theme) ? $theme : []);

    $primary       = $t['primary'] ?? '#a855f7';
    $primary_dark  = $t['primary-dark'] ?? '#7e22ce';
    $accent        = $t['accent'] ?? '#2563eb';
    $page_bg       = $t['page-bg'] ?? '#f9fafb';
    $page_text     = $t['page-text'] ?? '#111827';
    $header_bg     = $t['header-bg'] ?? '#ffffff';
    $header_text   = $t['header-text'] ?? '#374151';
    $footer_bg     = $t['footer-bg'] ?? '#1f2937';
    $footer_text   = $t['footer-text'] ?? '#f3f4f6';
    $footer_muted  = $t['footer-muted'] ?? '#9ca3af';
    $link_color    = $t['link-color'] ?? $primary;
    $button_bg     = $t['button-bg'] ?? $primary;
    $button_text   = $t['button-text'] ?? '#ffffff';
    $font_family   = $t['font-family'] ?? 'system-ui, sans-serif';
    $header_height = css_px($t['header-height'] ?? '74', 74);
    $logo_height   = css_px($t['logo-height'] ?? '100', 100);
    $content_width = css_px($t['content-width'] ?? '1240', 1240);
    $radius        = css_px($t['border-radius'] ?? '10', 10);
    $shadow        = !empty($t['shadow-enabled']) ? '0 2px 10px rgba(0,0,0,0.08)' : 'none';
    $menu_position = $t['menu-position'] ?? 'right';
    if (!in_array($menu_position, ['left','center','right'], true)) $menu_position = 'right';

    return <<<CSS
/* SpiderCMS global theme override - generated automatically */
:root{
  --primary: {$primary}!important;
  --primary-dark: {$primary_dark}!important;
  --accent: {$accent}!important;
  --page-bg: {$page_bg}!important;
  --page-text: {$page_text}!important;
  --header-bg: {$header_bg}!important;
  --header-text: {$header_text}!important;
  --footer-bg: {$footer_bg}!important;
  --footer-text: {$footer_text}!important;
  --footer-muted: {$footer_muted}!important;
  --link-color: {$link_color}!important;
  --button-bg: {$button_bg}!important;
  --button-text: {$button_text}!important;
  --font-family: {$font_family}!important;
  --header-height: {$header_height}!important;
  --logo-height: {$logo_height}!important;
  --content-width: {$content_width}!important;
  --radius: {$radius}!important;
  --header-shadow: {$shadow}!important;
  --menu-position: {$menu_position}!important;
  --spidercms-logo-max-height: min(var(--logo-height,100px), calc(var(--header-height,74px) - 14px))!important;
}
html,body{
  background:var(--page-bg)!important;
  color:var(--page-text)!important;
  font-family:var(--font-family)!important;
}
body *{font-family:inherit;}
a{color:var(--link-color)!important;}
main,.page-content,.content,.container-main{
  max-width:var(--content-width,1240px)!important;
  margin-left:auto!important;
  margin-right:auto!important;
}
.site-header{
  background:var(--header-bg)!important;
  background-color:var(--header-bg)!important;
  color:var(--header-text)!important;
  box-shadow:var(--header-shadow)!important;
  min-height:var(--header-height)!important;
  opacity:1!important;
  backdrop-filter:none!important;
  -webkit-backdrop-filter:none!important;
}
.site-header *,.site-header a,.nav-menu a,.submenu a{color:var(--header-text)!important;}
.site-header .header-container{min-height:var(--header-height)!important;}
.logo img,.brand-logo,.site-logo img{
  max-height:var(--spidercms-logo-max-height)!important;
  width:auto!important;
  object-fit:contain!important;
}
.site-header.menu-left .header-container{justify-content:flex-start!important;gap:2rem!important;}
.site-header.menu-center .header-container{justify-content:center!important;gap:2rem!important;}
.site-header.menu-right .header-container{justify-content:space-between!important;}
.site-footer,footer.site-footer{
  background:var(--footer-bg)!important;
  color:var(--footer-text)!important;
}
.site-footer *{color:inherit;}
.footer-bottom,.footer-col a,.site-footer a,.site-footer .muted{color:var(--footer-muted)!important;}
.cms-btn,.btn-primary,.button-primary,a.cms-btn,button[type=submit]:not(.no-theme),input[type=submit]{
  background:var(--button-bg)!important;
  background-color:var(--button-bg)!important;
  color:var(--button-text)!important;
  border-color:var(--button-bg)!important;
}
.cms-card,.card,.cms-faq details{
  border-radius:var(--radius)!important;
}
.cms-hero{
  max-width:var(--content-width,1240px)!important;
  border-radius:calc(var(--radius,10px) + 8px)!important;
}
CSS;
}

function spidercms_write_theme_css_file() {
    global $theme;
    $assets_dir = __DIR__ . '/assets';
    if (!is_dir($assets_dir)) @mkdir($assets_dir, 0755, true);
    $css = spidercms_theme_css_string($theme);
    return @file_put_contents($assets_dir . '/spidercms-theme.css', $css) !== false;
}

function spidercms_theme_link_block($depth = 1) {
    $depth = (int)$depth;
    return "<?php\n" .
        "\$spidercms_theme_root = dirname(__DIR__, {$depth});\n" .
        "\$spidercms_theme_css = \$spidercms_theme_root . '/assets/spidercms-theme.css';\n" .
        "\$spidercms_theme_v = file_exists(\$spidercms_theme_css) ? filemtime(\$spidercms_theme_css) : time();\n" .
        "\$spidercms_theme_href = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '') . '/assets/spidercms-theme.css?v=' . \$spidercms_theme_v;\n" .
        "?>\n" .
        "<link id=\"spidercms-global-theme-css\" rel=\"stylesheet\" href=\"<?= htmlspecialchars(\$spidercms_theme_href, ENT_QUOTES, 'UTF-8') ?>\">";
}

function spidercms_sync_theme_css_link_in_pages() {
    $updated = 0;
    $files = [];

    foreach (spidercms_available_page_folders() as $folder) {
        $dir = spidercms_page_folder_dir($folder);
        foreach (glob($dir . '/*.php') ?: [] as $file) $files[] = $file;
    }
    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) $files[] = $file;
    }
    $root_index = __DIR__ . '/index.php';
    if (is_file($root_index)) $files[] = $root_index;

    $files = array_values(array_unique($files));

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') continue;
        $content = file_get_contents($file);
        $original = $content;

        // Usuń stare wersje linku/stylu, żeby zawsze była jedna aktualna wersja.
        $content = preg_replace('~<link\s+id=["\']spidercms-global-theme-css["\'][^>]*>\s*~is', '', $content);
        $content = preg_replace('~<style\s+id=["\']spidercms-theme-preset-fix["\'][^>]*>.*?</style>\s*~is', '', $content);

        $rel = str_replace('\\', '/', substr(dirname($file), strlen(__DIR__)));
        $rel = trim($rel, '/');
        $depth = $rel === '' ? 0 : substr_count($rel, '/') + 1;
        if ($depth < 1) $depth = 1;
        $link = spidercms_theme_link_block($depth);

        if (stripos($content, '</head>') !== false) {
            $content = str_ireplace('</head>', $link . "\n</head>", $content);
        } elseif (stripos($content, '<body') !== false) {
            $content = preg_replace('~(<body\b[^>]*>)~i', $link . "\n$1", $content, 1);
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}

function spidercms_force_theme_refresh() {
    spidercms_write_theme_css_file();
    spidercms_sync_theme_css_link_in_pages();
    spidercms_apply_theme_css_to_all_pages();
}


// ----------------------------------------------------------------------
// Widoczność nagłówka systemowego – MINIMALNA POPRAWKA
// Nie zmienia HTML ani CSS nagłówka. Tylko decyduje, czy wczytać istniejący header.php.
// Dodatkowo przygotowuje zmienne używane przez obecny header.php: $logo_url, $menu_enabled, $menu_items.
// ----------------------------------------------------------------------
function spidercms_header_bootstrap_block($depth) {
    $depth = (int)$depth;
    return "<?php\n" .
        "require_once dirname(__DIR__, {$depth}) . '/config.php';\n" .
        "\$spidercms_root_dir = dirname(__DIR__, {$depth});\n" .
        "\$settings_file = \$spidercms_root_dir . '/.settings.json';\n" .
        "\$settings = file_exists(\$settings_file) ? json_decode((string)file_get_contents(\$settings_file), true) : [];\n" .
        "if (!is_array(\$settings)) { \$settings = []; }\n" .
        "if (!array_key_exists('header_enabled', \$settings)) { \$settings['header_enabled'] = '1'; }\n" .
        "\$logo_url = \$settings['logo'] ?? ((defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png');\n" .
        "\$menu_enabled = file_exists(\$spidercms_root_dir . '/.menu_enabled');\n" .
        "\$menu_items = json_decode(@file_get_contents(\$spidercms_root_dir . '/.menu.json') ?: '[]', true);\n" .
        "if (!is_array(\$menu_items)) { \$menu_items = []; }\n" .
        "if ((string)(\$settings['header_enabled'] ?? '1') === '1') {\n" .
        "    require_once \$spidercms_root_dir . '/header.php';\n" .
        "}\n" .
        "?>";
}

function spidercms_sync_header_bootstrap_in_pages() {
    if (!defined('ACTIVE_PAGES_DIR')) return 0;
    $depth = defined('ACTIVE_PAGES_DEPTH') ? (int)ACTIVE_PAGES_DEPTH : 1;
    $block = spidercms_header_bootstrap_block($depth);
    $updated = 0;

    foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        $original = $content;

        // Naprawa wersji standardowej: config.php + header.php na początku pliku.
        $content = preg_replace(
            "~<\\?php\\s*require_once\\s+dirname\\(__DIR__,\\s*\\d+\\)\\s*\\.\\s*'/config\\.php';\\s*require_once\\s+dirname\\(__DIR__,\\s*\\d+\\)\\s*\\.\\s*'/header\\.php';~s",
            rtrim($block, "?>") ,
            $content,
            1
        );

        // Naprawa prostszej wersji: samo require header.php.
        if (strpos($content, "'/header.php'") !== false && strpos($content, '$logo_url =') === false) {
            $content = preg_replace(
                "~<\\?php\\s*require_once\\s+dirname\\(__DIR__,\\s*\\d+\\)\\s*\\.\\s*'/header\\.php';\\s*\\?>~s",
                $block,
                $content,
                1
            );
        }

        // Jeżeli wcześniejsza poprawka dodała warunek bez zmiennych, dopiszemy zmienne przed require header.php.
        if (strpos($content, '$spidercms_header_enabled') !== false && strpos($content, '$logo_url =') === false) {
            $content = str_replace(
                "if (\$spidercms_header_enabled) {\n    require_once \$spidercms_root_dir . '/header.php';\n}",
                "if (!function_exists('spidercms_header_public_url')) { function spidercms_header_public_url(\$url) { \$url = trim((string)\$url); if (\$url === '') return ''; if (preg_match('~^(https?:)?//|^data:|^mailto:|^tel:|^/~i', \$url)) return \$url; \$base = defined('BASE_URL') ? trim((string)BASE_URL) : ''; if (\$base === '') { \$base = '/'; } return rtrim(\$base, '/') . '/' . ltrim(\$url, '/'); } }\n\$logo_url = \$spidercms_settings['logo'] ?? ((defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png');\n\$logo_url = spidercms_header_public_url(\$logo_url);\n\$menu_enabled = file_exists(\$spidercms_root_dir . '/.menu_enabled');\n\$menu_items = json_decode(@file_get_contents(\$spidercms_root_dir . '/.menu.json') ?: '[]', true);\nif (!is_array(\$menu_items)) { \$menu_items = []; }\nif (\$spidercms_header_enabled) {\n    require_once \$spidercms_root_dir . '/header.php';\n}",
                $content
            );
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}


// ----------------------------------------------------------------------
// Naprawa przezroczystego nagłówka na stronie głównej / istniejących stronach
// Nie zmienia układu nagłówka. Dodaje tylko końcową regułę CSS, która ma
// pierwszeństwo nad ewentualnym przezroczystym stylem na stronie głównej.
// ----------------------------------------------------------------------
function spidercms_fix_opaque_header_in_pages() {
    $updated = 0;
    $files = [];

    // Aktywny folder stron.
    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    // Root index.php też może być stroną główną albo własnym szablonem.
    $root_index = __DIR__ . '/index.php';
    if (is_file($root_index)) {
        $files[] = $root_index;
    }

    // Dodatkowo poprawiamy typowe foldery z podstronami, bo użytkownik mógł zmienić folder stron.
    foreach (spidercms_available_page_folders() as $folder) {
        $dir = spidercms_page_folder_dir($folder);
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    $files = array_values(array_unique($files));

    $fix_css = <<<'CSS'
<style id="spidercms-header-opaque-fix">
/*
   SpiderCMS: ujednolicenie wyglądu nagłówka na stronie głównej.
   Ta poprawka NIE przenosi header.php i NIE zmienia struktury strony.
   Nadpisuje tylko style, które na stronie głównej mogły robić nagłówek
   półprzezroczysty albo inny niż na pozostałych podstronach.
*/
:root{
    --spidercms-header-solid-bg: var(--header-bg,#ffffff);
    --spidercms-header-solid-text: var(--header-text,#374151);
}
body .site-header{
    position:fixed!important;
    top:0!important;
    left:0!important;
    right:0!important;
    z-index:1000!important;
    background:var(--spidercms-header-solid-bg)!important;
    background-color:var(--spidercms-header-solid-bg)!important;
    opacity:1!important;
    filter:none!important;
    backdrop-filter:none!important;
    -webkit-backdrop-filter:none!important;
    box-shadow:var(--header-shadow,0 2px 10px rgba(0,0,0,.08))!important;
    text-align:left!important;
}
body .site-header::before,
body .site-header::after{
    display:none!important;
    content:none!important;
    opacity:1!important;
    filter:none!important;
    backdrop-filter:none!important;
    -webkit-backdrop-filter:none!important;
}
body .site-header .header-container{
    max-width:var(--content-width,1240px)!important;
    margin:0 auto!important;
    padding:0 1.5rem!important;
    display:flex!important;
    justify-content:space-between!important;
    align-items:center!important;
    height:var(--header-height,74px)!important;
    min-height:var(--header-height,74px)!important;
    background:transparent!important;
    text-align:left!important;
}
body .site-header.menu-left .header-container,
body .site-header.menu-center .header-container,
body .site-header.menu-right .header-container{
    justify-content:flex-start!important;
}
body .site-header .logo{
    font-weight:700!important;
    font-size:1.4rem!important;
    color:var(--primary,#a855f7)!important;
    text-decoration:none!important;
    display:flex!important;
    align-items:center!important;
    justify-content:flex-start!important;
    text-align:left!important;
    margin-right:auto!important;
    height:100%!important;
}
body .site-header.menu-left .logo,
body .site-header.menu-center .logo{
    margin-right:1.5rem!important;
}
body .site-header.menu-right .logo{
    margin-right:auto!important;
}
body .site-header .logo img{
    max-height:min(var(--logo-height,100px), calc(var(--header-height,74px) - 14px))!important;
    height:auto!important;
    width:auto!important;
    max-width:min(260px, 40vw)!important;
    object-fit:contain!important;
    display:block!important;
    margin:0!important;
    opacity:1!important;
    filter:none!important;
}
body .site-header .nav-menu{
    display:flex!important;
    gap:2rem!important;
    align-items:center!important;
    background:transparent!important;
}
body .site-header.menu-left .nav-menu{
    margin-left:0!important;
    margin-right:auto!important;
}
body .site-header.menu-center .nav-menu{
    margin-left:auto!important;
    margin-right:auto!important;
}
body .site-header.menu-right .nav-menu{
    margin-left:auto!important;
    margin-right:0!important;
}
body .site-header .nav-menu a{
    color:var(--spidercms-header-solid-text)!important;
    text-decoration:none!important;
    font-weight:500!important;
    padding:.5rem 1rem!important;
    display:flex!important;
    align-items:center!important;
    gap:.5rem!important;
    background:transparent!important;
}
body .site-header .nav-menu a:hover{
    color:var(--primary,#a855f7)!important;
}
body .site-header .menu-toggle{
    color:var(--spidercms-header-solid-text)!important;
}
@media (max-width:768px){
    body .site-header .nav-menu{
        display:none!important;
        position:absolute!important;
        top:var(--header-height,74px)!important;
        left:0!important;
        right:0!important;
        flex-direction:column!important;
        padding:1.5rem!important;
        background:var(--spidercms-header-solid-bg)!important;
        box-shadow:0 6px 16px rgba(0,0,0,.1)!important;
    }
    body .site-header .nav-menu.active{
        display:flex!important;
    }
    body .site-header .menu-toggle{
        display:block!important;
    }
}
</style>
CSS;

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') {
            continue;
        }

        $content = file_get_contents($file);
        $original = $content;

        // Usuń starszą wersję poprawki, żeby nie dublować kodu.
        $content = preg_replace('~<style\s+id=["\']spidercms-header-opaque-fix["\'][^>]*>.*?</style>\s*~is', '', $content);

        // Popraw znane warianty definicji .site-header.
        $content = str_replace(
            '.site-header{position:fixed;top:0;left:0;right:0;background:var(--header-bg);box-shadow:var(--header-shadow);z-index:1000;text-align:left;}',
            '.site-header{position:fixed;top:0;left:0;right:0;background:var(--header-bg,#ffffff)!important;background-color:var(--header-bg,#ffffff)!important;opacity:1!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;box-shadow:var(--header-shadow);z-index:1000;text-align:left;}',
            $content
        );

        // Najważniejsze: dodaj regułę na końcu sekcji HEAD, aby wygrała z wcześniejszym CSS strony głównej.
        if (stripos($content, '</head>') !== false) {
            $content = str_ireplace('</head>', $fix_css . "\n</head>", $content);
        } elseif (stripos($content, '<body') !== false) {
            $content = preg_replace('~(<body\b[^>]*>)~i', $fix_css . "\n$1", $content, 1);
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }

    return $updated;
}

// Automatyczna, lekka naprawa przy wejściu do panelu – działa tylko na plikach stron.
// Wyłączone: ta funkcja modyfikowała publiczne strony przy każdym odświeżeniu panelu.
// spidercms_fix_opaque_header_in_pages();


// ----------------------------------------------------------------------
// AWARYJNA NAPRAWA PO POPRZEDNIEJ WERSJI
// Cofnięcie przeniesienia nagłówka do <body>, które mogło powodować puste strony.
// Przywraca układ generowany wcześniej przez SpiderCMS: bootstrap nagłówka na początku pliku,
// a w <body> zostaje tylko komentarz zastępczy.
// ----------------------------------------------------------------------
function spidercms_repair_pages_after_header_body_move() {
    if (!function_exists('spidercms_header_bootstrap_block')) return 0;

    $files = [];
    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) $files[] = $file;
    }
    foreach (spidercms_available_page_folders() as $folder) {
        $dir = spidercms_page_folder_dir($folder);
        foreach (glob($dir . '/*.php') ?: [] as $file) $files[] = $file;
    }
    $files = array_values(array_unique($files));
    $updated = 0;

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') continue;
        $content = file_get_contents($file);
        $original = $content;
        $depth = substr_count(str_replace('\\', '/', dirname($file)), '/') - substr_count(str_replace('\\', '/', __DIR__), '/');
        if ($depth < 1) $depth = defined('ACTIVE_PAGES_DEPTH') ? (int)ACTIVE_PAGES_DEPTH : 1;

        // Usuń blok nagłówka, który poprzednia wersja mogła wstawić bezpośrednio po <body>.
        $content = preg_replace(
            '~<body>\s*<\?php\s*require_once\s+dirname\(__DIR__,\s*\d+\)\s*\.\s*[\'\"]/config\.php[\'\"];.*?require_once\s+\$spidercms_root_dir\s*\.\s*[\'\"]/header\.php[\'\"];\s*\}\s*\?>~is',
            "<body>\n<?php // Nagłówek wczytany z header.php ?>",
            $content,
            1
        );

        // Jeśli przed DOCTYPE nie ma już ładowania header.php, dopisz standardowy bootstrap nagłówka na początku pliku.
        $before_doctype = $content;
        $pos = stripos($content, '<!DOCTYPE');
        if ($pos !== false) $before_doctype = substr($content, 0, $pos);

        if (strpos($before_doctype, 'header.php') === false && preg_match('~^<\?php\s*~', $content)) {
            $block = spidercms_header_bootstrap_block($depth);
            $inner = trim(preg_replace('~^<\?php|\?>$~', '', $block));
            $content = preg_replace('~^<\?php\s*~', "<?php\n" . $inner . "\n", $content, 1);
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }
    return $updated;
}

spidercms_repair_pages_after_header_body_move();


// ----------------------------------------------------------------------
// Globalna szerokość treści strony
// Dodaje/odświeża końcowy CSS w istniejących stronach, aby ustawienie
// --content-width działało także na stronach utworzonych wcześniej.
// ----------------------------------------------------------------------
function spidercms_sync_content_width_in_pages() {
    $updated = 0;
    $files = [];

    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    foreach (spidercms_available_page_folders() as $folder) {
        $dir = spidercms_page_folder_dir($folder);
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    $root_index = __DIR__ . '/index.php';
    if (is_file($root_index)) {
        $files[] = $root_index;
    }

    $files = array_values(array_unique($files));

    $fix_css = <<<'CSS'
<style id="spidercms-content-width-fix">
main{
    width:100%;
    max-width:var(--content-width,1240px);
    margin-left:auto!important;
    margin-right:auto!important;
}
@media(max-width:768px){
    main{
        max-width:100%;
    }
}
</style>
CSS;

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') {
            continue;
        }

        $content = file_get_contents($file);
        $original = $content;

        $content = preg_replace('~<style\s+id=["\']spidercms-content-width-fix["\'][^>]*>.*?</style>\s*~is', '', $content);

        if (stripos($content, '</head>') !== false) {
            $content = str_ireplace('</head>', $fix_css . "\n</head>", $content);
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            $updated++;
        }
    }

    return $updated;
}

// Automatycznie utrzymuj wspólną szerokość treści na istniejących stronach.
// Wyłączone: nie modyfikujemy stron przy samym wejściu do panelu.
// spidercms_sync_content_width_in_pages();


// ----------------------------------------------------------------------
// NAPRAWA ŚMIECI NA POCZĄTKU WYGENEROWANYCH STRON
// Usuwa przypadkowo zapisane znaki typu >>>>>> albo " > " > przed <?php / <!DOCTYPE / <html.
// To naprawia strony uszkodzone przez wcześniejszą błędną wersję generatora.
// ----------------------------------------------------------------------
// JEDNORAZOWA NAPRAWA ŚMIECI TYPU >>>>>> W PUBLICZNYCH STRONACH
// Ta funkcja TYLKO CZYŚCI istniejące pliki. Niczego nie dopisuje.
// Usuwa linie składające się wyłącznie z >, ", spacji i encji &gt;,
// które mogły zostać zapisane przez wcześniejszą uszkodzoną wersję generatora.
// ----------------------------------------------------------------------
function spidercms_cleanup_leading_garbage_in_public_pages() {
    $updated = 0;
    $files = [];

    $root_index = __DIR__ . '/index.php';
    if (is_file($root_index)) $files[] = $root_index;

    if (defined('ACTIVE_PAGES_DIR')) {
        foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $file) $files[] = $file;
    }

    if (function_exists('spidercms_available_page_folders')) {
        foreach (spidercms_available_page_folders() as $folder) {
            $dir = spidercms_page_folder_dir($folder);
            foreach (glob($dir . '/*.php') ?: [] as $file) $files[] = $file;
        }
    }

    $files = array_values(array_unique($files));

    foreach ($files as $file) {
        if (!is_file($file) || basename($file) === 'admin.php') continue;

        $content = file_get_contents($file);
        $original = $content;

        // 1) Usuń śmieci z samego początku pliku.
        $content = preg_replace('~\\A(?:\\s*(?:>|"|&gt;|&quot;))+\\s*~i', '', $content);

        // 2) Usuń osobne linie składające się tylko ze śmieci.
        $content = preg_replace('~^[\\s]*(?:>|"|&gt;|&quot;|;)+[\\s]*$~mi', '', $content);

        // 3) Usuń długie ciągi śmieci w pierwszych 3000 znakach pliku.
        $head = substr($content, 0, 3000);
        $tail = substr($content, 3000);
        $head = preg_replace('~(?:\\s*(?:>|"|&gt;|&quot;)){8,}\\s*~i', '', $head);
        $content = $head . $tail;

        if ($content !== $original) {
            file_put_contents($file, $content, LOCK_EX);
            $updated++;
        }
    }

    return $updated;
}

// Czyścimy raz przy wejściu do panelu. Funkcja nie dopisuje kodu do stron.
spidercms_cleanup_leading_garbage_in_public_pages();


// ----------------------------------------------------------------------
// Funkcja zapisująca przekierowanie strony głównej
// ----------------------------------------------------------------------
function write_homepage_redirect($slug) {
    $slug = preg_replace('/[^a-z0-9\-_]+/i', '', (string)$slug);
    if ($slug === '') {
        $slug = 'index';
    }

    $index_path = __DIR__ . '/index.php';
    $content = "<?php\n";
    $content .= "require_once __DIR__ . '/config.php';\n";
    $content .= "\$homepage = '" . addslashes($slug) . "';\n";
    $target_base = rtrim($GLOBALS['active_pages_url'] ?? (defined('ACTIVE_PAGES_URL') ? ACTIVE_PAGES_URL : '/pages/'), '/');
    $content .= "\$target = '" . addslashes($target_base) . "/' . \$homepage . '.php';\n";
    $content .= "header('Location: ' . \$target);\n";
    $content .= "exit;\n";

    return file_put_contents($index_path, $content) !== false;
}


// ----------------------------------------------------------------------
// Nagłówek z obsługą podmenu
// Zachowuje dotychczasowe klasy: site-header, header-container, logo, nav-menu, menu-toggle.
// Dzięki temu istniejący styl nagłówka nadal działa tak jak wcześniej.
// ----------------------------------------------------------------------
function spidercms_menu_icon_html($icon) {
    $icon = trim((string)$icon);
    if ($icon === '') return '';
    if (preg_match('~^(https?:)?//|^/|\.(png|jpe?g|gif|webp|svg)$~i', $icon)) {
        return '<img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt="">';
    }
    return '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>';
}

function spidercms_write_header_with_submenu_support() {
    $header = <<<'PHP'
<?php
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}

$settings_file = __DIR__ . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode((string)file_get_contents($settings_file), true) : [];
if (!is_array($settings)) $settings = [];
if (!array_key_exists('show_site_name_in_header', $settings)) $settings['show_site_name_in_header'] = '0';
if (!array_key_exists('header_title_font_size', $settings)) $settings['header_title_font_size'] = '22';
if (!array_key_exists('header_title_font_weight', $settings)) $settings['header_title_font_weight'] = '800';
if (!array_key_exists('header_title_color', $settings)) $settings['header_title_color'] = '';
if (!array_key_exists('header_title_gap', $settings)) $settings['header_title_gap'] = '10';
if (!array_key_exists('header_title_uppercase', $settings)) $settings['header_title_uppercase'] = '0';
if (!array_key_exists('header_title_italic', $settings)) $settings['header_title_italic'] = '0';
if (!array_key_exists('header_title_shadow', $settings)) $settings['header_title_shadow'] = '0';
if (!array_key_exists('header_title_bg', $settings)) $settings['header_title_bg'] = '';
if (!array_key_exists('header_title_radius', $settings)) $settings['header_title_radius'] = '0';
$show_site_name_in_header = (string)($settings['show_site_name_in_header'] ?? '0') === '1';

if (!function_exists('spidercms_header_public_url')) {
    function spidercms_header_public_url($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('~^(https?:)?//|^data:|^mailto:|^tel:|^/~i', $url)) return $url;
        $base = defined('BASE_URL') ? trim((string)BASE_URL) : '';
        if ($base === '') {
            $base = '/';
        }
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}

$logo_url = $logo_url ?? ($settings['logo'] ?? ((defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png'));
$logo_url = spidercms_header_public_url($logo_url);
$menu_enabled = $menu_enabled ?? file_exists(__DIR__ . '/.menu_enabled');
$menu_items = $menu_items ?? json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true);
if (!is_array($menu_items)) $menu_items = [];

$theme_file = __DIR__ . '/.theme.json';
$theme = file_exists($theme_file) ? json_decode((string)file_get_contents($theme_file), true) : [];
if (!is_array($theme)) $theme = [];
$menu_position = $theme['menu-position'] ?? 'right';
if (!in_array($menu_position, ['left','center','right'], true)) $menu_position = 'right';

if (!function_exists('spidercms_header_icon_html')) {
    function spidercms_header_icon_html($icon) {
        $icon = trim((string)$icon);
        if ($icon === '') return '';
        if (preg_match('~^(https?:)?//|^/|\.(png|jpe?g|gif|webp|svg)$~i', $icon)) {
            if (function_exists('spidercms_header_public_url')) {
                $icon = spidercms_header_public_url($icon);
            }
            return '<img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt="">';
        }
        return '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>';
    }
}
$header_title_font_size = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_font_size'] ?? '22'));
if ($header_title_font_size === '') $header_title_font_size = '22';
$header_title_font_weight = preg_replace('/[^0-9]/', '', (string)($settings['header_title_font_weight'] ?? '800'));
if ($header_title_font_weight === '') $header_title_font_weight = '800';
$header_title_gap = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_gap'] ?? '10'));
if ($header_title_gap === '') $header_title_gap = '10';
$header_title_radius = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_radius'] ?? '0'));
if ($header_title_radius === '') $header_title_radius = '0';
$header_title_color_raw = trim((string)($settings['header_title_color'] ?? ''));
$header_title_color = $header_title_color_raw !== '' ? preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', $header_title_color_raw) : 'var(--header-text,#374151)';
$header_title_bg_raw = trim((string)($settings['header_title_bg'] ?? ''));
$header_title_bg = $header_title_bg_raw !== '' ? preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', $header_title_bg_raw) : 'transparent';
$header_title_transform = ((string)($settings['header_title_uppercase'] ?? '0') === '1') ? 'uppercase' : 'none';
$header_title_style = ((string)($settings['header_title_italic'] ?? '0') === '1') ? 'italic' : 'normal';
$header_title_shadow = ((string)($settings['header_title_shadow'] ?? '0') === '1') ? '0 2px 10px rgba(0,0,0,.25)' : 'none';
?>
<?php
$spidercms_theme_css_file = __DIR__ . '/assets/spidercms-theme.css';
$spidercms_theme_css_v = file_exists($spidercms_theme_css_file) ? filemtime($spidercms_theme_css_file) : time();
$spidercms_theme_css_href = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '') . '/assets/spidercms-theme.css?v=' . $spidercms_theme_css_v;
?>
<link id="spidercms-global-theme-css" rel="stylesheet" href="<?= htmlspecialchars($spidercms_theme_css_href, ENT_QUOTES, 'UTF-8') ?>">
<style id="spidercms-submenu-style">
.nav-item{position:relative;display:flex;align-items:center}.nav-item>a{white-space:nowrap}.nav-item.has-submenu>a::after{content:"▾";font-size:.72em;margin-left:.35rem;opacity:.75}.submenu{display:none;position:absolute;left:0;top:100%;min-width:220px;background:var(--header-bg,#fff);box-shadow:0 14px 35px rgba(0,0,0,.16);border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:.45rem;z-index:1005}.nav-item.has-submenu:hover>.submenu,.nav-item.has-submenu:focus-within>.submenu{display:flex;flex-direction:column;gap:.15rem}.submenu a{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;border-radius:10px;color:var(--header-text,#374151);text-decoration:none;white-space:nowrap}.submenu a:hover{background:rgba(168,85,247,.10);color:var(--primary,#a855f7)}.logo{gap:.65rem}.header-site-name{display:inline-block;color:var(--header-text,#374151);font-weight:800;font-size:clamp(1rem,1.8vw,1.35rem);line-height:1.1;white-space:nowrap}.logo img + .header-site-name{margin-left:.1rem}@media(max-width:768px){.nav-item{width:100%;display:block}.submenu{position:static;display:flex;flex-direction:column;box-shadow:none;border:0;background:rgba(0,0,0,.03);margin:.2rem 0 .4rem 1rem;min-width:0}.submenu a{white-space:normal}.header-site-name{font-size:1rem;max-width:52vw;overflow:hidden;text-overflow:ellipsis}}
</style>
<style id="spidercms-header-title-style">
.logo{gap:<?= htmlspecialchars($header_title_gap, ENT_QUOTES, 'UTF-8') ?>px!important;}
.header-site-name{
    color:<?= htmlspecialchars($header_title_color, ENT_QUOTES, 'UTF-8') ?>!important;
    font-size:<?= htmlspecialchars($header_title_font_size, ENT_QUOTES, 'UTF-8') ?>px!important;
    font-weight:<?= htmlspecialchars($header_title_font_weight, ENT_QUOTES, 'UTF-8') ?>!important;
    text-transform:<?= htmlspecialchars($header_title_transform, ENT_QUOTES, 'UTF-8') ?>!important;
    font-style:<?= htmlspecialchars($header_title_style, ENT_QUOTES, 'UTF-8') ?>!important;
    text-shadow:<?= htmlspecialchars($header_title_shadow, ENT_QUOTES, 'UTF-8') ?>!important;
    background:<?= htmlspecialchars($header_title_bg, ENT_QUOTES, 'UTF-8') ?>!important;
    border-radius:<?= htmlspecialchars($header_title_radius, ENT_QUOTES, 'UTF-8') ?>px!important;
    padding:<?= $header_title_bg !== 'transparent' ? '0.18em 0.42em' : '0' ?>!important;
}
@media(max-width:768px){.header-site-name{font-size:min(<?= htmlspecialchars($header_title_font_size, ENT_QUOTES, 'UTF-8') ?>px,1rem)!important;}}
</style>
<header class="site-header menu-<?= htmlspecialchars($menu_position, ENT_QUOTES, 'UTF-8') ?>">
    <div class="header-container">
        <a href="<?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') ?: '/', ENT_QUOTES, 'UTF-8') ?>" class="logo">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Logo', ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <?php if ($show_site_name_in_header || empty($logo_url)): ?>
                <span class="header-site-name"><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'SpiderCMS', ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </a>

        <?php if ($menu_enabled && !empty($menu_items)): ?>
            <nav class="nav-menu" id="spidercms-nav-menu">
                <?php foreach ($menu_items as $item): ?>
                    <?php
                    $label = trim((string)($item['label'] ?? ''));
                    $url = trim((string)($item['url'] ?? '#'));
                    $icon = trim((string)($item['icon'] ?? ''));
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    if ($label === '' && $url === '') continue;
                    ?>
                    <?php if (!empty($children)): ?>
                        <div class="nav-item has-submenu">
                            <a href="<?= htmlspecialchars($url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($icon) ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                            <div class="submenu">
                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $child_label = trim((string)($child['label'] ?? ''));
                                    $child_url = trim((string)($child['url'] ?? '#'));
                                    $child_icon = trim((string)($child['icon'] ?? ''));
                                    if ($child_label === '' && $child_url === '') continue;
                                    ?>
                                    <a href="<?= htmlspecialchars($child_url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($child_icon) ?><?= htmlspecialchars($child_label, ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($icon) ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <div class="menu-toggle" onclick="document.getElementById('spidercms-nav-menu')?.classList.toggle('active')">☰</div>
        <?php endif; ?>
    </div>
</header>
PHP;
    @file_put_contents(__DIR__ . '/header.php', $header);
}

// Automatycznie odśwież header.php po wejściu do panelu, żeby naprawić ścieżki logo
// bez potrzeby ponownego zapisywania ustawień.
spidercms_write_header_with_submenu_support();
spidercms_write_theme_css_file();
// Wyłączone: nie dopisujemy linków CSS przy każdym wejściu do panelu.
// spidercms_sync_theme_css_link_in_pages();

// ----------------------------------------------------------------------
// Hasło + brute-force
// ----------------------------------------------------------------------
$hash_file = __DIR__ . '/.admin_hash';
if (!file_exists($hash_file)) {
    $default_password = 'admin2026';
    file_put_contents($hash_file, password_hash($default_password, PASSWORD_ARGON2ID));
    chmod($hash_file, 0600);
}
$ADMIN_HASH = trim(file_get_contents($hash_file));
$MAX_LOGIN_ATTEMPTS = 5;
$BLOCK_DURATION = 15 * 60;
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['login_block_until'])) $_SESSION['login_block_until'] = 0;
if ($_SESSION['login_block_until'] > time()) {
    $remaining = $_SESSION['login_block_until'] - time();
    $minutes = ceil($remaining / 60);
    $login_error = "Zbyt wiele prób. Blokada na $minutes minut.";
} else {
    if ($_SESSION['login_block_until'] > 0 && $_SESSION['login_block_until'] <= time()) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_block_until'] = 0;
    }
}


// ----------------------------------------------------------------------
// SPIDERCMS LIVE EDITOR START
// Opcjonalny edytor strony na żywo.
// Działa jako tryb panelu admin.php?action=live_editor&file=nazwa.php.
// Zapisuje zawartość <main> lub zmienną $content = <<<HTML ... HTML;
// Tworzy kopię zapasową przed zapisem.
// ----------------------------------------------------------------------

$live_settings_file = __DIR__ . '/.live_editor.json';
$live_settings_defaults = [
    'enabled' => '1',
    'backup_enabled' => '1',
    'editable_selector' => 'main',
];

$live_settings_loaded = file_exists($live_settings_file) ? json_decode((string)file_get_contents($live_settings_file), true) : [];
if (!is_array($live_settings_loaded)) {
    $live_settings_loaded = [];
}
$live_settings = array_merge($live_settings_defaults, $live_settings_loaded);

function spidercms_live_is_enabled() {
    global $live_settings;
    return (($live_settings['enabled'] ?? '1') === '1');
}

function spidercms_live_safe_file($file) {
    $file = basename((string)$file);
    if ($file === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $file)) {
        return '';
    }
    return $file;
}

function spidercms_live_page_path($file) {
    $file = spidercms_live_safe_file($file);
    if ($file === '') {
        return '';
    }

    if (defined('ACTIVE_PAGES_DIR')) {
        $path = ACTIVE_PAGES_DIR . '/' . $file;
        if (is_file($path)) {
            return $path;
        }
    }

    $fallback = __DIR__ . '/pages/' . $file;
    if (is_file($fallback)) {
        return $fallback;
    }

    $root = __DIR__ . '/' . $file;
    if (is_file($root) && $file === 'index.php') {
        return $root;
    }

    return '';
}

function spidercms_live_page_url($file) {
    $file = spidercms_live_safe_file($file);
    if ($file === '') {
        return '';
    }

    if (defined('ACTIVE_PAGES_URL')) {
        return rtrim(ACTIVE_PAGES_URL, '/') . '/' . rawurlencode($file);
    }

    return 'pages/' . rawurlencode($file);
}

function spidercms_live_extract_title($source, $fallback = 'Strona') {
    if (preg_match('/\$title\s*=\s*[\'"](.+?)[\'"]\s*;/s', $source, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/\$page_title\s*=\s*[\'"](.+?)[\'"]\s*;/s', $source, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $source, $m)) {
        return trim(strip_tags($m[1]));
    }
    return $fallback;
}

function spidercms_live_extract_content($source) {
    if (preg_match('/\$content\s*=\s*<<<HTML\s*(.*?)\s*HTML;/s', $source, $m)) {
        return $m[1];
    }

    if (preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $source, $m)) {
        return $m[1];
    }

    return '';
}

function spidercms_live_create_backup($path) {
    $backup_dir = __DIR__ . '/.backups/live-editor';
    if (!is_dir($backup_dir)) {
        @mkdir($backup_dir, 0750, true);
    }

    $name = basename($path, '.php') . '-' . date('Ymd-His') . '.php.bak';
    return @copy($path, $backup_dir . '/' . $name);
}


function spidercms_live_prepare_content_for_editor($html) {
    $html = (string)$html;

    // W edytorze LIVE nie pokazujemy linków administracyjnych Rezerwacje, jeśli zostały kiedyś błędnie wstrzyknięte.
    $html = preg_replace('~<a\s+[^>]*href=["\']admin\.php\?tab=bookings["\'][^>]*>.*?Rezerwacje.*?</a>~is', '', $html);

    $html = preg_replace(
        '/\[booking(?:\s+id="([^"]*)")?\]/i',
        '<div class="spidercms-live-module-placeholder" data-spidercms-shortcode="[booking]" contenteditable="false">📅 Moduł rezerwacji<br><small>Ten blok jest aktywny na stronie publicznej. W edytorze LIVE pokazujemy tylko placeholder.</small></div>',
        $html
    );

    $html = preg_replace(
        '~<div\s+[^>]*data-spidercms-booking[^>]*>.*?</div>\s*(?:<script[^>]*booking-embed\.js[^>]*></script>)?~is',
        '<div class="spidercms-live-module-placeholder" data-spidercms-shortcode="[booking]" contenteditable="false">📅 Moduł rezerwacji<br><small>Ten blok jest aktywny na stronie publicznej. W edytorze LIVE pokazujemy tylko placeholder.</small></div>',
        $html
    );

    $html = preg_replace('~<script\b[^>]*booking[^>]*>.*?</script>~is', '', $html);

    return $html;
}

function spidercms_live_restore_shortcodes_from_editor($html) {
    $html = (string)$html;

    $html = preg_replace(
        '~<div\s+[^>]*data-spidercms-shortcode="\[booking\]"[^>]*>.*?</div>~is',
        '[booking]',
        $html
    );

    $html = preg_replace(
        '~<div\s+[^>]*data-spidercms-booking[^>]*>.*?</div>\s*(?:<script[^>]*booking-embed\.js[^>]*></script>)?~is',
        '[booking]',
        $html
    );

    return $html;
}

function spidercms_live_sanitize_html($html) {
    $html = (string)$html;

    // Usuwamy elementy i atrybuty szczególnie ryzykowne.
    $html = preg_replace('~<\s*(script|iframe|object|embed|form|input|button|textarea|select|option|link|meta|base)[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);
    $html = preg_replace('~<\s*(script|iframe|object|embed|form|input|button|textarea|select|option|link|meta|base)[^>]*\/?\s*>~is', '', $html);
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
    $html = preg_replace('/javascript\s*:/is', '', $html);

    return trim($html);
}


function spidercms_replace_page_content_source($source, $new_content) {
    $source = (string)$source;
    $new_content = (string)$new_content;

    // Supports:
    // $content = <<<HTML ... HTML;
    // $content = <<<'HTML' ... HTML;
    // $content = <<<"HTML" ... HTML;
    $heredoc_pattern = '/\$content\s*=\s*<<<[ \t]*(?:[\'"]?HTML[\'"]?)[ \t]*\R.*?\RHTML[ \t]*;/s';

    if (preg_match($heredoc_pattern, $source)) {
        return preg_replace_callback($heredoc_pattern, function() use ($new_content) {
            return '$content = <<<HTML' . "\n" . $new_content . "\n" . 'HTML;';
        }, $source, 1);
    }

    // Fallback for pages that do not use $content but have a <main> block.
    if (preg_match('/<main\b[^>]*>.*?<\/main>/is', $source)) {
        return preg_replace_callback('/(<main\b[^>]*>).*?(<\/main>)/is', function($m) use ($new_content) {
            return $m[1] . "\n" . $new_content . "\n" . $m[2];
        }, $source, 1);
    }

    return false;
}

function spidercms_live_save_content_to_page($path, $new_content) {
    if (!is_file($path) || !is_writable($path)) {
        return false;
    }

    $source = (string)file_get_contents($path);
    $new_content = spidercms_live_restore_shortcodes_from_editor($new_content);
    $new_content = spidercms_live_sanitize_html($new_content);

    if (($GLOBALS['live_settings']['backup_enabled'] ?? '1') === '1') {
        spidercms_live_create_backup($path);
    }

    $updated = spidercms_replace_page_content_source($source, $new_content);
    if ($updated === false) {
        return false;
    }

    return file_put_contents($path, $updated, LOCK_EX) !== false;
}

// Endpoint zapisu LIVE.
if (($_POST['action'] ?? '') === 'live_editor_save') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Brak autoryzacji.']);
        exit;
    }

    verify_csrf_or_die();

    if (!spidercms_live_is_enabled()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Edytor LIVE jest wyłączony.']);
        exit;
    }

    $file = spidercms_live_safe_file($_POST['file'] ?? '');
    $path = spidercms_live_page_path($file);

    if ($path === '') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Nie znaleziono strony.']);
        exit;
    }

    $html = $_POST['html'] ?? '';
    $ok = spidercms_live_save_content_to_page($path, $html);

    if (function_exists('spidercms_admin_log')) {
        spidercms_admin_log($ok ? 'live_editor_save' : 'live_editor_save_failed', [
            'file' => $file
        ]);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $ok ? 'Zapisano zmiany LIVE.' : 'Nie udało się zapisać zmian. Sprawdź czy strona ma blok $content lub <main> oraz prawa zapisu pliku.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Panel edytora LIVE.
if (($_GET['action'] ?? '') === 'live_editor') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: admin.php');
        exit;
    }

    $file = spidercms_live_safe_file($_GET['file'] ?? '');
    $path = spidercms_live_page_path($file);

    if ($path === '') {
        http_response_code(404);
        echo 'Nie znaleziono strony do edycji LIVE.';
        exit;
    }

    if (!spidercms_live_is_enabled()) {
        echo 'Edytor LIVE jest wyłączony w ustawieniach.';
        exit;
    }

    $source = (string)file_get_contents($path);
    $title = spidercms_live_extract_title($source, $file);
    $content = spidercms_live_prepare_content_for_editor(spidercms_live_extract_content($source));
    $preview_url = spidercms_live_page_url($file);
    $token = csrf_token();

    $live_media = [];
    $live_uploads_dir = __DIR__ . '/uploads';
    if (is_dir($live_uploads_dir)) {
        foreach (glob($live_uploads_dir . '/*') ?: [] as $img) {
            if (is_file($img) && preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $img)) {
                $live_media[] = 'uploads/' . basename($img);
            }
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edytor LIVE – <?= e($title) ?></title>
        <style>
            *{box-sizing:border-box}
            body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0f172a;color:#f8fafc}
            .live-shell{min-height:100vh;display:flex;flex-direction:column}
            .live-topbar{position:sticky;top:0;z-index:50;background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:1px solid rgba(255,255,255,.12);padding:.8rem 1rem;display:flex;gap:.75rem;align-items:center;justify-content:space-between;box-shadow:0 12px 30px rgba(0,0,0,.25)}
            .live-title{font-weight:900;display:flex;flex-direction:column;gap:.15rem}
            .live-title small{color:#94a3b8;font-weight:600}
            .live-actions{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
            .live-btn{border:0;border-radius:999px;padding:.72rem 1rem;font-weight:850;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.45rem}
            .live-btn.primary{background:#a855f7;color:#fff}
            .live-btn.dark{background:#334155;color:#fff}
            .live-btn.light{background:#e2e8f0;color:#0f172a}
            .live-btn.danger{background:#ef4444;color:#fff}
            .live-work{display:grid;grid-template-columns:280px 1fr;min-height:calc(100vh - 72px)}
            .live-panel{background:#111827;border-right:1px solid rgba(255,255,255,.1);padding:1rem;overflow:auto}
            .live-panel h3{margin:.2rem 0 1rem;font-size:1rem}
            .live-panel p{color:#cbd5e1;font-size:.92rem;line-height:1.5}
            .live-hint{padding:.85rem;border-radius:14px;background:rgba(168,85,247,.14);border:1px solid rgba(168,85,247,.35);margin-bottom:1rem}
            .live-field{margin-bottom:1rem}
            .live-field label{display:block;margin-bottom:.35rem;font-weight:800;color:#e2e8f0}
            .live-field input,.live-field select{width:100%;padding:.75rem;border-radius:12px;border:1px solid #334155;background:#020617;color:#fff}
            .live-stage{background:#e5e7eb;padding:1rem;overflow:auto}
            .live-canvas{max-width:1240px;margin:0 auto;background:#fff;color:#111827;min-height:70vh;border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.25);overflow:hidden}
            #liveEditable{padding:2rem;outline:0}
            #liveEditable [contenteditable="false"]{pointer-events:none}
            #liveEditable:focus{box-shadow:inset 0 0 0 3px #a855f7}
            .live-selected{outline:3px solid #a855f7!important;outline-offset:3px!important;border-radius:8px}
            .live-status{font-size:.9rem;color:#cbd5e1}
            .live-preview-link{color:#93c5fd}

            .spidercms-live-module-placeholder{padding:1.4rem;border:2px dashed #a855f7;border-radius:18px;background:#f5f3ff;color:#4c1d95;text-align:center;font-weight:900;margin:1rem 0}
            .spidercms-live-module-placeholder small{display:block;margin-top:.35rem;color:#6d28d9;font-weight:700}


            /* LIVE EXTRA TOOLS */
            .live-tool-section{margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.12)}
            .live-mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
            .live-mini-btn{border:1px solid rgba(255,255,255,.14);border-radius:12px;background:#020617;color:#e2e8f0;padding:.65rem;font-weight:800;cursor:pointer;text-align:center}
            .live-mini-btn:hover{background:#1e293b}
            .live-canvas.is-tablet{max-width:820px}
            .live-canvas.is-mobile{max-width:390px}
            .live-canvas.is-mobile #liveEditable{padding:1rem}
            .live-gallery{display:grid;grid-template-columns:repeat(3,1fr);gap:.45rem;max-height:210px;overflow:auto;padding-right:.2rem}
            .live-gallery img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px;border:2px solid transparent;cursor:pointer;background:#fff}
            .live-gallery img:hover{border-color:#a855f7}
            .live-modal{display:none;position:fixed;inset:0;z-index:100;background:rgba(2,6,23,.72);align-items:center;justify-content:center;padding:1rem}
            .live-modal.open{display:flex}
            .live-modal-box{width:min(560px,100%);background:#111827;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:1rem;box-shadow:0 30px 90px rgba(0,0,0,.45)}
            .live-modal-box h3{margin-top:0}
            .live-modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;flex-wrap:wrap}

            @media(max-width:900px){
                .live-topbar{align-items:flex-start;flex-direction:column}
                .live-work{grid-template-columns:1fr}
                .live-panel{border-right:0;border-bottom:1px solid rgba(255,255,255,.1)}
                #liveEditable{padding:1rem}
            }
        </style>
    </head>
    <body>
    <div class="live-shell">
        <div class="live-topbar">
            <div class="live-title">
                <span>✨ Edytor LIVE: <?= e($title) ?></span>
                <small>Plik: <?= e($file) ?> · <a class="live-preview-link" href="<?= e($preview_url) ?>" target="_blank">Podgląd strony</a></small>
            </div>
            <div class="live-actions">
                <a class="live-btn light" href="admin.php?tab=pages">← Wróć</a>

<button class="live-btn dark" type="button" onclick="document.execCommand('bold')">B</button>
                <button class="live-btn dark" type="button" onclick="document.execCommand('italic')"><i>I</i></button>
                <button class="live-btn dark" type="button" onclick="document.execCommand('formatBlock', false, 'h2')">H2</button>
                <button class="live-btn dark" type="button" onclick="document.execCommand('formatBlock', false, 'p')">P</button>
                <button class="live-btn dark" type="button" id="liveLinkBtn">Link</button>
                <button class="live-btn dark" type="button" id="liveClearBtn">Wyczyść format</button>
                <button class="live-btn dark" type="button" id="liveUndoBtn">↶ Cofnij</button>
                <button class="live-btn dark" type="button" id="liveRedoBtn">↷ Ponów</button>
                <button class="live-btn dark" type="button" data-device="desktop">Desktop</button>
                <button class="live-btn dark" type="button" data-device="tablet">Tablet</button>
                <button class="live-btn dark" type="button" data-device="mobile">Mobile</button>
                <button class="live-btn primary" type="button" id="liveSaveBtn">Zapisz</button>
            </div>
        </div>

        <div class="live-work">
            <aside class="live-panel">
                <div class="live-hint">
                    Kliknij tekst lub element na stronie i edytuj go bezpośrednio. Zapis tworzy kopię zapasową w <strong>.backups/live-editor</strong>.
                </div>

                <h3>Narzędzia elementu</h3>

                <div class="live-field">
                    <label>Kolor tekstu</label>
                    <input type="color" id="liveColor" value="#111827">
                </div>

                <div class="live-field">
                    <label>Tło elementu</label>
                    <input type="color" id="liveBg" value="#ffffff">
                </div>

                <div class="live-field">
                    <label>Rozmiar tekstu</label>
                    <select id="liveFontSize">
                        <option value="">Bez zmian</option>
                        <option value="14px">14 px</option>
                        <option value="16px">16 px</option>
                        <option value="18px">18 px</option>
                        <option value="22px">22 px</option>
                        <option value="28px">28 px</option>
                        <option value="36px">36 px</option>
                        <option value="48px">48 px</option>
                    </select>
                </div>

                <div class="live-actions">
                    <button class="live-btn dark" type="button" id="liveApplyStyle">Zastosuj styl</button>
                    <button class="live-btn danger" type="button" id="liveRemoveElement">Usuń element</button>
                </div>

                <div class="live-tool-section">
                    <h3>Gotowe sekcje</h3>
                    <div class="live-mini-grid">
                        <button class="live-mini-btn" type="button" data-section="hero">Hero</button>
                        <button class="live-mini-btn" type="button" data-section="cards">Karty</button>
                        <button class="live-mini-btn" type="button" data-section="cta">CTA</button>
                        <button class="live-mini-btn" type="button" data-section="faq">FAQ</button>
                    </div>
                </div>

                <div class="live-tool-section">
                    <h3>Obrazy z galerii</h3>
                    <p>Kliknij obraz na stronie, potem wybierz zdjęcie.</p>
                    <div class="live-gallery">
                        <?php foreach ($live_media as $img): ?>
                            <img src="<?= e($img) ?>" data-live-img="<?= e($img) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="live-status" id="liveStatus">Gotowy do edycji.</p>
            </aside>

            <main class="live-stage">
                <div class="live-canvas">
                    <div id="liveEditable" contenteditable="true"><?= $content ?></div>
                </div>
            </main>
        </div>
    </div>

    <script>
    (function(){
        const editable = document.getElementById('liveEditable');
        const status = document.getElementById('liveStatus');
        const saveBtn = document.getElementById('liveSaveBtn');
        const canvas = document.querySelector('.live-canvas');
        let selected = null;
        let history = [editable.innerHTML];
        let historyIndex = 0;
        let historyTimer = null;

        function setStatus(text){ status.textContent = text; }

        function pushHistory(){
            clearTimeout(historyTimer);
            historyTimer = setTimeout(function(){
                const current = editable.innerHTML;
                if (history[historyIndex] === current) return;
                history = history.slice(0, historyIndex + 1);
                history.push(current);
                if (history.length > 50) history.shift();
                historyIndex = history.length - 1;
            }, 300);
        }

        editable.addEventListener('input', pushHistory);

        function restoreHistory(index){
            if(index < 0 || index >= history.length) return;
            historyIndex = index;
            editable.innerHTML = history[historyIndex];
            setStatus('Przywrócono zmianę lokalną.');
        }

        document.getElementById('liveUndoBtn')?.addEventListener('click', function(){
            restoreHistory(historyIndex - 1);
        });

        document.getElementById('liveRedoBtn')?.addEventListener('click', function(){
            restoreHistory(historyIndex + 1);
        });

        document.querySelectorAll('[data-device]').forEach(function(btn){
            btn.addEventListener('click', function(){
                canvas.classList.remove('is-tablet','is-mobile');
                const d = btn.dataset.device;
                if(d === 'tablet') canvas.classList.add('is-tablet');
                if(d === 'mobile') canvas.classList.add('is-mobile');
                setStatus('Podgląd: ' + d);
            });
        });

        editable.addEventListener('click', function(e){
            if (selected) selected.classList.remove('live-selected');
            selected = e.target.closest('section,div,article,h1,h2,h3,h4,p,ul,ol,li,img,a,span');
            if (selected && selected !== editable) {
                selected.classList.add('live-selected');
                setStatus('Wybrano: ' + selected.tagName.toLowerCase());
                e.stopPropagation();
            }
        });

        document.getElementById('liveApplyStyle').addEventListener('click', function(){
            if (!selected || selected === editable) { setStatus('Najpierw kliknij element.'); return; }
            const color = document.getElementById('liveColor').value;
            const bg = document.getElementById('liveBg').value;
            const fs = document.getElementById('liveFontSize').value;
            if (color) selected.style.color = color;
            if (bg) selected.style.backgroundColor = bg;
            if (fs) selected.style.fontSize = fs;
            pushHistory();
            setStatus('Zastosowano styl elementu.');
        });

        document.getElementById('liveRemoveElement').addEventListener('click', function(){
            if (!selected || selected === editable) { setStatus('Najpierw kliknij element.'); return; }
            if (confirm('Usunąć wybrany element?')) {
                const tmp = selected; selected = null; tmp.remove(); pushHistory(); setStatus('Usunięto element.');
            }
        });

        document.getElementById('liveLinkBtn')?.addEventListener('click', function(){
            const url = prompt('Podaj adres linku:', 'https://');
            if(!url) return;
            document.execCommand('createLink', false, url);
            pushHistory();
            setStatus('Dodano/zmieniono link.');
        });

        document.getElementById('liveClearBtn')?.addEventListener('click', function(){
            document.execCommand('removeFormat');
            pushHistory();
            setStatus('Wyczyszczono formatowanie zaznaczenia.');
        });

        document.querySelectorAll('[data-section]').forEach(function(btn){
            btn.addEventListener('click', function(){
                const type = btn.dataset.section;
                let html = '';
                if(type === 'hero'){
                    html = '<section class="cms-hero" style="padding:3rem 2rem;border-radius:22px;background:linear-gradient(135deg,#f3e8ff,#e0f2fe);margin:1rem 0;"><h1>Nowy nagłówek sekcji</h1><p>Krótki opis, który możesz od razu zmienić w edytorze LIVE.</p><a class="cms-btn" href="#" style="display:inline-block;margin-top:1rem;padding:.8rem 1.2rem;border-radius:999px;background:#a855f7;color:white;text-decoration:none;font-weight:800;">Wezwanie do działania</a></section>';
                }
                if(type === 'cards'){
                    html = '<section class="cms-card-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin:1rem 0;"><div class="cms-card" style="padding:1.2rem;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08);"><h3>Karta 1</h3><p>Opis elementu.</p></div><div class="cms-card" style="padding:1.2rem;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08);"><h3>Karta 2</h3><p>Opis elementu.</p></div><div class="cms-card" style="padding:1.2rem;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08);"><h3>Karta 3</h3><p>Opis elementu.</p></div></section>';
                }
                if(type === 'cta'){
                    html = '<section style="padding:2rem;border-radius:20px;background:#111827;color:white;text-align:center;margin:1rem 0;"><h2>Gotowy na współpracę?</h2><p>Dodaj własny tekst i przycisk kontaktowy.</p><a href="#" style="display:inline-block;margin-top:1rem;padding:.8rem 1.2rem;border-radius:999px;background:#22c55e;color:white;text-decoration:none;font-weight:800;">Kontakt</a></section>';
                }
                if(type === 'faq'){
                    html = '<section class="cms-faq" style="margin:1rem 0;"><h2>Najczęstsze pytania</h2><details style="padding:1rem;border-radius:14px;background:#fff;margin:.6rem 0;"><summary>Pytanie numer 1</summary><p>Odpowiedź na pytanie.</p></details><details style="padding:1rem;border-radius:14px;background:#fff;margin:.6rem 0;"><summary>Pytanie numer 2</summary><p>Odpowiedź na pytanie.</p></details></section>';
                }
                editable.insertAdjacentHTML('beforeend', html);
                pushHistory();
                setStatus('Dodano sekcję: ' + type);
            });
        });

        document.querySelectorAll('[data-live-img]').forEach(function(img){
            img.addEventListener('click', function(){
                if(!selected || selected.tagName.toLowerCase() !== 'img'){
                    setStatus('Najpierw kliknij obraz na stronie.');
                    return;
                }
                selected.setAttribute('src', img.dataset.liveImg);
                selected.removeAttribute('srcset');
                pushHistory();
                setStatus('Zmieniono obraz.');
            });
        });

        document.addEventListener('keydown', function(e){
            if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){
                e.preventDefault();
                saveBtn.click();
            }
        });

        saveBtn.addEventListener('click', function(){
            if (selected) selected.classList.remove('live-selected');
            const fd = new FormData();
            fd.set('action', 'live_editor_save');
            fd.set('csrf_token', <?= json_encode($token) ?>);
            fd.set('file', <?= json_encode($file) ?>);
            fd.set('html', editable.innerHTML);
            saveBtn.disabled = true;
            setStatus('Zapisywanie...');
            fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){
                return r.text().then(function(txt){
                    try { return JSON.parse(txt); }
                    catch(e) { return {ok:false, message:'Błąd odpowiedzi serwera: ' + txt.substring(0,160)}; }
                });
            })
            .then(d => {
                saveBtn.disabled = false;
                setStatus(d.message || (d.ok ? 'Zapisano.' : 'Błąd zapisu.'));
                if(d.ok){ history = [editable.innerHTML]; historyIndex = 0; }
            })
            .catch(() => {
                saveBtn.disabled = false;
                setStatus('Błąd połączenia podczas zapisu.');
            });
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

// Zapis ustawień LIVE z panelu.
if (($_POST['action'] ?? '') === 'save_live_editor_settings') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(403);
        exit('Brak autoryzacji.');
    }

    verify_csrf_or_die();

    $live_settings = [
        'enabled' => isset($_POST['live_editor_enabled']) ? '1' : '0',
        'backup_enabled' => isset($_POST['live_editor_backup_enabled']) ? '1' : '0',
        'editable_selector' => 'main',
    ];

    file_put_contents(
        $live_settings_file,
        json_encode($live_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if (function_exists('spidercms_admin_log')) {
        spidercms_admin_log('live_editor_settings_saved');
    }

    header('Location: admin.php?tab=settings&saved=live-editor');
    exit;
}
// SPIDERCMS LIVE EDITOR END


// ----------------------------------------------------------------------
// SPIDERCMS BOOKING SYSTEM START
// Moduł rezerwacji: flat-file, bez bazy danych.
// Dane: .bookings/settings.json, .bookings/reservations.json, .bookings/blocked.json
// Publiczny shortcode / include: booking-widget.php
// ----------------------------------------------------------------------

$booking_dir = __DIR__ . '/.bookings';
if (!is_dir($booking_dir)) {
    @mkdir($booking_dir, 0750, true);
}
spidercms_write_htaccess($booking_dir, "Options -Indexes\nRequire all denied\nDeny from all\n");

$booking_settings_file = $booking_dir . '/settings.json';
$booking_reservations_file = $booking_dir . '/reservations.json';
$booking_blocked_file = $booking_dir . '/blocked.json';

$booking_defaults = [
    'enabled' => '1',
    'title' => 'Zarezerwuj termin',
    'subtitle' => 'Wybierz dogodny dzień i godzinę. Potwierdzenie wyślemy e-mailem.',
    'service_name' => 'Konsultacja',
    'slot_minutes' => '60',
    'days_ahead' => '30',
    'min_notice_hours' => '4',
    'work_days' => ['1','2','3','4','5'],
    'work_start' => '09:00',
    'work_end' => '17:00',
    'admin_email' => '',
    'notify_admin' => '1',
    'notify_client' => '1',
    'require_phone' => '0',
    'confirmation_mode' => 'manual',
];

function spidercms_booking_load_json($file, $default = []) {
    if (!file_exists($file)) return $default;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function spidercms_booking_save_json($file, array $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

$booking_settings_loaded = spidercms_booking_load_json($booking_settings_file, []);
if (!is_array($booking_settings_loaded)) $booking_settings_loaded = [];
$booking_settings = array_merge($booking_defaults, $booking_settings_loaded);
$booking_settings['work_days'] = spidercms_booking_normalize_work_days($booking_settings['work_days'] ?? ['1','2','3','4','5']);


function spidercms_booking_normalize_work_days($value) {
    if (is_string($value)) {
        $value = array_filter(array_map('trim', explode(',', $value)));
    }

    if (!is_array($value)) {
        $value = ['1','2','3','4','5'];
    }

    $allowed = ['1','2','3','4','5','6','7'];
    $out = [];

    foreach ($value as $v) {
        $v = (string)$v;
        if (in_array($v, $allowed, true)) {
            $out[] = $v;
        }
    }

    $out = array_values(array_unique($out));

    // Jeśli użytkownik nie zaznaczy nic, nie zostawiamy pustego kalendarza.
    // Domyślnie wracamy do dni roboczych.
    if (!$out) {
        $out = ['1','2','3','4','5'];
    }

    return $out;
}

function spidercms_booking_clean($value, $max = 500) {
    $value = trim((string)$value);
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
    return substr($value, 0, $max);
}

function spidercms_booking_valid_date($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function spidercms_booking_valid_time($time) {
    return is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time);
}

function spidercms_booking_public_rate_limit($limit = 6, $window = 300) {
    $dir = __DIR__ . '/.bookings/rate';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $items = file_exists($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($items)) $items = [];
    $items = array_values(array_filter($items, fn($t) => is_int($t) && $t > ($now - $window)));
    if (count($items) >= $limit) return false;
    $items[] = $now;
    file_put_contents($file, json_encode($items), LOCK_EX);
    return true;
}

function spidercms_booking_load_reservations() {
    global $booking_reservations_file;
    $data = spidercms_booking_load_json($booking_reservations_file, []);
    return is_array($data) ? $data : [];
}


function spidercms_booking_stats_summary() {
    $items = function_exists('spidercms_booking_load_reservations') ? spidercms_booking_load_reservations() : [];
    $today = date('Y-m-d');
    $all = 0;
    $new = 0;
    $confirmed = 0;
    $today_count = 0;
    $upcoming = 0;

    foreach ($items as $r) {
        $status = $r['status'] ?? 'new';
        if ($status === 'cancelled') {
            continue;
        }

        $all++;
        if ($status === 'new') $new++;
        if ($status === 'confirmed') $confirmed++;
        if (($r['date'] ?? '') === $today) $today_count++;
        if (($r['date'] ?? '') >= $today) $upcoming++;
    }

    return [
        'all' => $all,
        'new' => $new,
        'confirmed' => $confirmed,
        'today' => $today_count,
        'upcoming' => $upcoming,
    ];
}

function spidercms_booking_save_reservations(array $data) {
    global $booking_reservations_file;
    return spidercms_booking_save_json($booking_reservations_file, $data);
}

function spidercms_booking_load_blocked() {
    global $booking_blocked_file;
    $data = spidercms_booking_load_json($booking_blocked_file, []);
    return is_array($data) ? $data : [];
}

function spidercms_booking_is_taken($date, $time, $ignore_id = '') {
    foreach (spidercms_booking_load_reservations() as $r) {
        if (($r['id'] ?? '') === $ignore_id) continue;
        if (($r['status'] ?? 'new') === 'cancelled') continue;
        if (($r['date'] ?? '') === $date && ($r['time'] ?? '') === $time) {
            return true;
        }
    }
    return false;
}

function spidercms_booking_is_blocked($date, $time) {
    foreach (spidercms_booking_load_blocked() as $b) {
        if (($b['date'] ?? '') !== $date) continue;
        $from = $b['from'] ?? '';
        $to = $b['to'] ?? '';
        if ($from === '' && $to === '') return true;
        if ($from !== '' && $to !== '' && $time >= $from && $time < $to) return true;
    }
    return false;
}

function spidercms_booking_slots_for_date($date) {
    global $booking_settings;
    if (!spidercms_booking_valid_date($date)) return [];

    $ts = strtotime($date . ' 00:00:00');
    if (!$ts) return [];

    $weekday = (string)date('N', $ts);
    $work_days = spidercms_booking_normalize_work_days($booking_settings['work_days'] ?? ['1','2','3','4','5']);

    if (!in_array($weekday, $work_days, true)) return [];

    $slot_minutes = max(15, (int)($booking_settings['slot_minutes'] ?? 60));
    $start = $booking_settings['work_start'] ?? '09:00';
    $end = $booking_settings['work_end'] ?? '17:00';

    if (!spidercms_booking_valid_time($start) || !spidercms_booking_valid_time($end)) return [];

    $start_ts = strtotime($date . ' ' . $start);
    $end_ts = strtotime($date . ' ' . $end);
    if (!$start_ts || !$end_ts || $start_ts >= $end_ts) return [];

    $min_notice = max(0, (int)($booking_settings['min_notice_hours'] ?? 4));
    $min_allowed = time() + ($min_notice * 3600);

    $slots = [];
    for ($t = $start_ts; $t + ($slot_minutes * 60) <= $end_ts; $t += $slot_minutes * 60) {
        if ($t < $min_allowed) continue;
        $time = date('H:i', $t);
        if (spidercms_booking_is_taken($date, $time)) continue;
        if (spidercms_booking_is_blocked($date, $time)) continue;
        $slots[] = $time;
    }

    return $slots;
}

function spidercms_booking_email_log($message, array $context = []) {
    $dir = __DIR__ . '/.bookings';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'message' => (string)$message,
        'context' => $context,
    ];

    @file_put_contents(
        $dir . '/email.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function spidercms_booking_call_chat_mailer($to, $subject, $body, $reply_to = '') {
    // Rezerwacje używają najpierw tego samego mechanizmu, który działa w module Chat.
    // Obsługujemy kilka możliwych nazw funkcji, bo w kolejnych wersjach pliku mogły mieć różne nazwy.
    $candidates = [
        'chat_send_email_notification',
        'chat_send_email',
        'spidercms_chat_send_email',
        'spidercms_chat_send_notification_email',
        'spidercms_send_chat_email',
        'spidercms_send_email_notification',
    ];

    foreach ($candidates as $fn) {
        if (function_exists($fn)) {
            try {
                $ref = new ReflectionFunction($fn);
                $params = $ref->getNumberOfParameters();

                if ($params >= 4) {
                    $ok = $fn($to, $subject, $body, $reply_to);
                } elseif ($params === 3) {
                    $ok = $fn($to, $subject, $body);
                } elseif ($params === 2) {
                    $ok = $fn($subject, $body);
                } else {
                    continue;
                }

                spidercms_booking_email_log('Próba wysyłki przez mailer czatu: ' . $fn, [
                    'to' => $to,
                    'subject' => $subject,
                    'result' => (bool)$ok
                ]);

                if ($ok) {
                    return true;
                }
            } catch (Throwable $e) {
                spidercms_booking_email_log('Błąd mailera czatu: ' . $fn . ' / ' . $e->getMessage(), [
                    'to' => $to,
                    'subject' => $subject
                ]);
            }
        }
    }

    return false;
}

function spidercms_booking_send_email($to, $subject, $body, $reply_to = '') {
    $to = trim((string)$to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        spidercms_booking_email_log('Niepoprawny adres odbiorcy.', ['to' => $to]);
        return false;
    }

    // 1) Najpierw użyj dokładnie działającego mechanizmu czatu.
    if (spidercms_booking_call_chat_mailer($to, $subject, $body, $reply_to)) {
        spidercms_booking_email_log('Wysłano e-mail modułu rezerwacji przez mechanizm czatu.', [
            'to' => $to,
            'subject' => $subject
        ]);
        return true;
    }

    // 2) Jeśli w tym pliku nie ma funkcji czatu, użyj ustawień czatu jako konfiguracji mail().
    global $chat_settings, $booking_settings;

    $from = '';
    if (is_array($chat_settings ?? null)) {
        $from = trim((string)(
            $chat_settings['from_email']
            ?? $chat_settings['email_from']
            ?? $chat_settings['smtp_user']
            ?? $chat_settings['admin_email']
            ?? ''
        ));
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = trim((string)(
            $booking_settings['from_email']
            ?? $booking_settings['admin_email']
            ?? ''
        ));
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $host = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $from = 'no-reply@' . $host;
    }

    $site = defined('SITE_NAME') ? SITE_NAME : 'SpiderCMS';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . $site . ' <' . $from . '>';
    if ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    $ok = @mail(
        $to,
        '=?UTF-8?B?' . base64_encode((string)$subject) . '?=',
        (string)$body,
        implode("\r\n", $headers),
        '-f' . $from
    );

    spidercms_booking_email_log($ok ? 'Fallback mail() zwróciło OK.' : 'Fallback mail() zwróciło błąd.', [
        'to' => $to,
        'subject' => $subject,
        'from' => $from
    ]);

    return $ok;
}


function spidercms_booking_send_confirmation_to_client(array $reservation) {
    $email = trim((string)($reservation['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        spidercms_booking_email_log('Nie wysłano potwierdzenia: brak poprawnego e-mail klienta.', [
            'reservation_id' => $reservation['id'] ?? '',
            'email' => $email
        ]);
        return false;
    }

    $name = spidercms_booking_clean($reservation['name'] ?? 'Kliencie', 120);
    $date = spidercms_booking_clean($reservation['date'] ?? '', 20);
    $time = spidercms_booking_clean($reservation['time'] ?? '', 20);
    $service = spidercms_booking_clean($reservation['service'] ?? 'Rezerwacja', 160);

    global $booking_settings;
    $admin_email = trim((string)($booking_settings['admin_email'] ?? ''));

    $subject = 'Wizyta została potwierdzona: ' . $date . ' ' . $time;

    $body =
        "Dzień dobry {$name},\n\n" .
        "Twoja wizyta została potwierdzona.\n\n" .
        "Szczegóły rezerwacji:\n" .
        "Usługa: {$service}\n" .
        "Data: {$date}\n" .
        "Godzina: {$time}\n\n" .
        "Do zobaczenia!\n";

    $ok = spidercms_booking_send_email($email, $subject, $body, $admin_email);

    spidercms_booking_email_log($ok ? 'Wysłano potwierdzenie wizyty do klienta.' : 'Nie udało się wysłać potwierdzenia wizyty do klienta.', [
        'reservation_id' => $reservation['id'] ?? '',
        'to' => $email,
        'date' => $date,
        'time' => $time
    ]);

    return $ok;
}

function spidercms_booking_create_widget_file() {
    global $booking_settings;
    $payload = var_export($booking_settings, true);
    $widget = <<<'PHP'
<?php
$spidercms_booking_settings = __BOOKING_SETTINGS__;
if (($spidercms_booking_settings['enabled'] ?? '1') !== '1') return;
$spidercms_booking_endpoint = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/admin.php';
?>
<div class="spidercms-booking-widget" data-endpoint="<?php echo htmlspecialchars($spidercms_booking_endpoint, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="spidercms-booking-head">
    <h2><?php echo htmlspecialchars($spidercms_booking_settings['title'] ?? 'Zarezerwuj termin', ENT_QUOTES, 'UTF-8'); ?></h2>
    <p><?php echo htmlspecialchars($spidercms_booking_settings['subtitle'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
  </div>

  <div class="spidercms-booking-layout">
    <div class="spidercms-booking-calendar">
      <div class="spidercms-booking-monthbar">
        <button type="button" data-cal-prev>‹</button>
        <strong data-cal-title></strong>
        <button type="button" data-cal-next>›</button>
      </div>
      <div class="spidercms-booking-weekdays">
        <span>Pn</span><span>Wt</span><span>Śr</span><span>Cz</span><span>Pt</span><span>Sb</span><span>Nd</span>
      </div>
      <div class="spidercms-booking-days" data-cal-days></div>
    </div>

    <form class="spidercms-booking-form">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">
      <input type="hidden" name="date" required>

      <label>Wybrana data
        <input type="text" data-selected-date readonly placeholder="Wybierz dzień w kalendarzu">
      </label>

      <label>Godzina
        <select name="time" required>
          <option value="">Najpierw wybierz datę</option>
        </select>
      </label>

      <label>Imię i nazwisko
        <input type="text" name="name" required maxlength="120">
      </label>

      <label>E-mail
        <input type="email" name="email" required maxlength="160">
      </label>

      <label>Telefon
        <input type="tel" name="phone" maxlength="80" <?php echo (($spidercms_booking_settings['require_phone'] ?? '0') === '1') ? 'required' : ''; ?>>
      </label>

      <label>Wiadomość
        <textarea name="message" rows="3" maxlength="1200"></textarea>
      </label>

      <button type="submit">Zarezerwuj termin</button>
      <p class="spidercms-booking-status"></p>
    </form>
  </div>
</div>

<style>
.spidercms-booking-widget{max-width:1040px;margin:2rem auto;padding:1.5rem;border-radius:24px;background:#fff;box-shadow:0 18px 50px rgba(15,23,42,.12);border:1px solid #e5e7eb;color:#111827;font-family:system-ui,sans-serif}
.spidercms-booking-head h2{margin:0 0 .35rem;color:var(--primary,#a855f7);font-size:clamp(1.6rem,3vw,2.4rem)}
.spidercms-booking-head p{margin:0 0 1.2rem;color:#64748b}
.spidercms-booking-layout{display:grid;grid-template-columns:1.15fr .85fr;gap:1.2rem;align-items:start}
.spidercms-booking-calendar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:1rem}
.spidercms-booking-monthbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem}
.spidercms-booking-monthbar button{width:38px;height:38px;border:0;border-radius:12px;background:#111827;color:white;font-size:1.6rem;line-height:1;cursor:pointer}
.spidercms-booking-monthbar strong{font-size:1.05rem}
.spidercms-booking-weekdays,.spidercms-booking-days{display:grid;grid-template-columns:repeat(7,1fr);gap:.45rem}
.spidercms-booking-weekdays span{text-align:center;color:#64748b;font-weight:800;font-size:.85rem;padding:.35rem 0}
.spidercms-booking-day{aspect-ratio:1/1;border:1px solid #e2e8f0;border-radius:14px;background:white;color:#111827;font-weight:850;cursor:pointer;display:flex;align-items:center;justify-content:center;position:relative}
.spidercms-booking-day:hover{border-color:var(--primary,#a855f7);box-shadow:0 8px 20px rgba(168,85,247,.12)}
.spidercms-booking-day.is-muted{opacity:.25;pointer-events:none}
.spidercms-booking-day.is-disabled{opacity:.35;cursor:not-allowed;background:#e5e7eb;pointer-events:none}
.spidercms-booking-day.is-selected{background:var(--primary,#a855f7);color:#fff;border-color:var(--primary,#a855f7)}
.spidercms-booking-day.has-slots::after{content:"";position:absolute;bottom:7px;width:6px;height:6px;border-radius:999px;background:#22c55e}
.spidercms-booking-form{display:grid;gap:.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:1rem}
.spidercms-booking-form label{display:grid;gap:.35rem;font-weight:750;color:#334155}
.spidercms-booking-form input,.spidercms-booking-form select,.spidercms-booking-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:.8rem;font:inherit;background:white;color:#111827}
.spidercms-booking-form button{border:0;border-radius:999px;background:var(--button-bg,#a855f7);color:var(--button-text,#fff);font-weight:900;padding:.9rem 1.2rem;cursor:pointer}
.spidercms-booking-status{min-height:1.4rem;color:#475569;font-weight:700}
@media(max-width:820px){.spidercms-booking-layout{grid-template-columns:1fr}.spidercms-booking-widget{margin:1rem;padding:1rem;border-radius:18px}.spidercms-booking-day{border-radius:10px;font-size:.9rem}}
</style>

<script>
(function(){
  document.querySelectorAll('.spidercms-booking-widget').forEach(function(widget){
    if(widget.dataset.ready === '1') return;
    widget.dataset.ready = '1';

    const endpoint = widget.dataset.endpoint || '/admin.php';
    const form = widget.querySelector('.spidercms-booking-form');
    const hiddenDate = form.querySelector('[name="date"]');
    const selectedDateView = form.querySelector('[data-selected-date]');
    const time = form.querySelector('[name="time"]');
    const status = widget.querySelector('.spidercms-booking-status');
    const daysBox = widget.querySelector('[data-cal-days]');
    const title = widget.querySelector('[data-cal-title]');
    const prev = widget.querySelector('[data-cal-prev]');
    const next = widget.querySelector('[data-cal-next]');
    const maxDays = parseInt(<?php echo json_encode((int)($spidercms_booking_settings['days_ahead'] ?? 30)); ?>,10) || 30;

    let current = new Date();
    current.setDate(1);
    const today = new Date();
    today.setHours(0,0,0,0);
    const max = new Date();
    max.setDate(max.getDate() + maxDays);
    max.setHours(0,0,0,0);

    function fmtDate(d){
      const y = d.getFullYear();
      const m = String(d.getMonth()+1).padStart(2,'0');
      const day = String(d.getDate()).padStart(2,'0');
      return y + '-' + m + '-' + day;
    }

    function setStatus(msg){ status.textContent = msg || ''; }

    function loadSlots(dateValue){
      time.innerHTML = '<option value="">Ładowanie...</option>';
      return fetch(endpoint + '?action=booking_public_slots&date=' + encodeURIComponent(dateValue), {credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
          time.innerHTML = '';
          if(!d.ok || !d.slots || !d.slots.length){
            time.innerHTML = '<option value="">Brak wolnych terminów</option>';
            return [];
          }
          time.innerHTML = '<option value="">Wybierz godzinę</option>';
          d.slots.forEach(function(s){
            const o = document.createElement('option');
            o.value = s; o.textContent = s; time.appendChild(o);
          });
          return d.slots;
        })
        .catch(() => {
          time.innerHTML = '<option value="">Błąd ładowania</option>';
          return [];
        });
    }

    function renderCalendar(){
      daysBox.innerHTML = '';
      const monthNames = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
      title.textContent = monthNames[current.getMonth()] + ' ' + current.getFullYear();

      const first = new Date(current.getFullYear(), current.getMonth(), 1);
      const startOffset = (first.getDay() + 6) % 7;
      const daysInMonth = new Date(current.getFullYear(), current.getMonth()+1, 0).getDate();

      for(let i=0;i<startOffset;i++){
        const blank = document.createElement('div');
        blank.className = 'spidercms-booking-day is-muted';
        daysBox.appendChild(blank);
      }

      for(let d=1; d<=daysInMonth; d++){
        const dateObj = new Date(current.getFullYear(), current.getMonth(), d);
        dateObj.setHours(0,0,0,0);
        const dateValue = fmtDate(dateObj);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'spidercms-booking-day';
        btn.textContent = d;
        btn.dataset.date = dateValue;

        if(dateObj < today || dateObj > max){
          btn.classList.add('is-disabled');
        }else{
          btn.classList.add('has-slots');
        }

        btn.addEventListener('click', function(){
          widget.querySelectorAll('.spidercms-booking-day.is-selected').forEach(el => el.classList.remove('is-selected'));
          btn.classList.add('is-selected');
          hiddenDate.value = dateValue;
          selectedDateView.value = dateValue;
          setStatus('Ładowanie dostępnych godzin...');
          loadSlots(dateValue).then(function(slots){
            setStatus(slots.length ? 'Wybierz godzinę i uzupełnij dane.' : 'Brak wolnych godzin w tym dniu.');
          });
        });

        daysBox.appendChild(btn);
      }
    }

    prev.addEventListener('click', function(){
      current.setMonth(current.getMonth()-1);
      renderCalendar();
    });

    next.addEventListener('click', function(){
      current.setMonth(current.getMonth()+1);
      renderCalendar();
    });

    form.addEventListener('submit', function(e){
      e.preventDefault();
      if(!hiddenDate.value){
        setStatus('Wybierz datę w kalendarzu.');
        return;
      }
      const fd = new FormData(form);
      fd.set('action','booking_public_create');
      setStatus('Wysyłanie rezerwacji...');
      fetch(endpoint, {method:'POST', body:fd, credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
          if(d.ok){
            form.reset();
            hiddenDate.value = '';
            selectedDateView.value = '';
            time.innerHTML = '<option value="">Najpierw wybierz datę</option>';
            widget.querySelectorAll('.spidercms-booking-day.is-selected').forEach(el => el.classList.remove('is-selected'));
            setStatus(d.message || 'Rezerwacja została zapisana.');
          }else{
            setStatus(d.error || 'Nie udało się zapisać rezerwacji.');
          }
        })
        .catch(() => setStatus('Błąd połączenia.'));
    });

    renderCalendar();
  });
})();
</script>
PHP;
    $widget = str_replace('__BOOKING_SETTINGS__', $payload, $widget);
    file_put_contents(__DIR__ . '/booking-widget.php', $widget, LOCK_EX);
}


function spidercms_booking_create_embed_js_file() {
    $js = <<<'JS'
(function(){
  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function(){
    document.querySelectorAll('[data-spidercms-booking]').forEach(function(box){
      if(box.dataset.loaded === '1') return;
      box.dataset.loaded = '1';
      var endpoint = box.getAttribute('data-endpoint') || '/admin.php';
      box.innerHTML = '<div style="padding:1rem;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-family:system-ui,sans-serif">Ładowanie rezerwacji...</div>';

      fetch(endpoint + '?action=booking_public_widget', {credentials:'same-origin'})
        .then(function(r){ return r.text(); })
        .then(function(html){
          box.innerHTML = html;
          box.querySelectorAll('script').forEach(function(oldScript){
            var s = document.createElement('script');
            if(oldScript.src) s.src = oldScript.src;
            s.text = oldScript.textContent || '';
            document.body.appendChild(s);
            oldScript.remove();
          });
        })
        .catch(function(){
          box.innerHTML = '<div style="padding:1rem;border-radius:14px;background:#fee2e2;color:#991b1b">Nie udało się załadować modułu rezerwacji.</div>';
        });
    });
  });
})();
JS;
    file_put_contents(__DIR__ . '/booking-embed.js', $js, LOCK_EX);
}

function spidercms_booking_embed_html($depth = null) {
    if ($depth === null) {
        $depth = defined('ACTIVE_PAGES_DEPTH') ? (int)ACTIVE_PAGES_DEPTH : 1;
    }
    $depth = max(1, (int)$depth);
    $prefix = str_repeat('../', $depth);
    $admin = $prefix . 'admin.php';
    $js = $prefix . 'booking-embed.js';

    return '<div data-spidercms-booking data-endpoint="' . htmlspecialchars($admin, ENT_QUOTES, 'UTF-8') . '"></div>' . "\n" .
           '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>';
}

function spidercms_booking_cleanup_bad_autoload_footer() {
    $footer = __DIR__ . '/footer.php';
    if (!is_file($footer)) return false;

    $content = file_get_contents($footer);
    $original = $content;

    $content = preg_replace('~<\?php\s+if\s*\(file_exists\(__DIR__\s*\.\s*\'/booking-autoload\.php\'\)\)\s*require_once\s+__DIR__\s*\.\s*\'/booking-autoload\.php\';\s*\?>~', '', $content);
    $content = preg_replace('~<\?php\s+require_once\s+__DIR__\s*\.\s*\'/booking-autoload\.php\';\s*\?>~', '', $content);
    $content = preg_replace('~<script>.*?SPIDERCMS Booking Autoload.*?</script>~is', '', $content);

    if ($content !== $original) {
        file_put_contents($footer, $content, LOCK_EX);
        return true;
    }

    return false;
}

function spidercms_booking_repair_pages_after_bad_shortcode() {
    $updated = 0;
    $dirs = [];

    if (defined('ACTIVE_PAGES_DIR')) {
        $dirs[] = [ACTIVE_PAGES_DIR, defined('ACTIVE_PAGES_DEPTH') ? (int)ACTIVE_PAGES_DEPTH : 1];
    }

    $dirs[] = [__DIR__ . '/pages', 1];

    if (function_exists('spidercms_available_page_folders')) {
        foreach (spidercms_available_page_folders() as $folder) {
            $dirs[] = [spidercms_page_folder_dir($folder), spidercms_page_folder_depth($folder)];
        }
    }

    $seen = [];

    foreach ($dirs as $pair) {
        [$dir, $depth] = $pair;
        if (!is_dir($dir)) continue;

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $real = realpath($file) ?: $file;
            if (isset($seen[$real])) continue;
            $seen[$real] = true;

            $content = file_get_contents($file);
            $original = $content;

            // Cofnij wcześniejsze błędne include PHP w treści.
            $content = preg_replace('~<\?php\s+require_once\s+dirname\(__DIR__,\s*\d+\)\s*\.\s*\'/booking-widget\.php\';\s*\?>~', '[booking]', $content);
            $content = preg_replace('~<\?php\s+require_once\s+dirname\(__DIR__\)\s*\.\s*\'/booking-widget\.php\';\s*\?>~', '[booking]', $content);

            // Usuń runtime processor, jeśli został wstrzyknięty do strony.
            $content = preg_replace('~<\?php\s*// SPIDERCMS BOOKING RUNTIME SHORTCODE START.*?// SPIDERCMS BOOKING RUNTIME SHORTCODE END\s*\?>~s', '', $content);

            // Zamień shortcode na bezpieczny HTML/JS embed.
            if (strpos($content, '[booking') !== false) {
                $embed = spidercms_booking_embed_html($depth);
                $content = preg_replace('/\[booking(?:\s+id="[^"]*")?\]/i', $embed, $content);
            }

            if ($content !== $original) {
                file_put_contents($file, $content, LOCK_EX);
                $updated++;
            }
        }
    }

    return $updated;
}

function spidercms_booking_shortcode_html($depth = null) {
    if ($depth === null) {
        $depth = defined('ACTIVE_PAGES_DEPTH') ? (int)ACTIVE_PAGES_DEPTH : 1;
    }
    $depth = max(1, (int)$depth);
    return "<?php require_once dirname(__DIR__, " . $depth . ") . '/booking-widget.php'; ?>";
}

function spidercms_booking_process_shortcodes_in_pages() {
    spidercms_booking_create_widget_file();
    spidercms_booking_create_embed_js_file();
    spidercms_booking_cleanup_bad_autoload_footer();
    return spidercms_booking_repair_pages_after_bad_shortcode();
}

spidercms_booking_create_widget_file();

// Publiczne endpointy rezerwacji.
$booking_public_action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($booking_public_action === 'booking_public_widget') {
    spidercms_booking_create_widget_file();
    if (file_exists(__DIR__ . '/booking-widget.php')) {
        require __DIR__ . '/booking-widget.php';
    }
    exit;
}

if ($booking_public_action === 'booking_public_slots') {
    header('Content-Type: application/json; charset=utf-8');
    $date = $_GET['date'] ?? '';
    echo json_encode([
        'ok' => spidercms_booking_valid_date($date),
        'slots' => spidercms_booking_valid_date($date) ? spidercms_booking_slots_for_date($date) : []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($booking_public_action === 'booking_public_create') {
    header('Content-Type: application/json; charset=utf-8');

    if (($booking_settings['enabled'] ?? '1') !== '1') {
        echo json_encode(['ok'=>false,'error'=>'Rezerwacje są aktualnie wyłączone.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!spidercms_booking_public_rate_limit()) {
        echo json_encode(['ok'=>false,'error'=>'Zbyt wiele prób rezerwacji. Spróbuj później.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (trim((string)($_POST['website'] ?? '')) !== '') {
        echo json_encode(['ok'=>false,'error'=>'Rezerwacja odrzucona.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $name = spidercms_booking_clean($_POST['name'] ?? '', 120);
    $email = spidercms_booking_clean($_POST['email'] ?? '', 160);
    $phone = spidercms_booking_clean($_POST['phone'] ?? '', 80);
    $message = spidercms_booking_clean($_POST['message'] ?? '', 1200);

    if (!spidercms_booking_valid_date($date) || !spidercms_booking_valid_time($time)) {
        echo json_encode(['ok'=>false,'error'=>'Wybierz poprawną datę i godzinę.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok'=>false,'error'=>'Podaj imię i poprawny e-mail.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($booking_settings['require_phone'] ?? '0') === '1' && $phone === '') {
        echo json_encode(['ok'=>false,'error'=>'Telefon jest wymagany.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($time, spidercms_booking_slots_for_date($date), true)) {
        echo json_encode(['ok'=>false,'error'=>'Ten termin nie jest już dostępny.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reservations = spidercms_booking_load_reservations();
    $id = 'res_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $status = (($booking_settings['confirmation_mode'] ?? 'manual') === 'auto') ? 'confirmed' : 'new';

    $reservation = [
        'id' => $id,
        'date' => $date,
        'time' => $time,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'message' => $message,
        'service' => $booking_settings['service_name'] ?? 'Rezerwacja',
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s'),
        'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    $reservations[] = $reservation;
    spidercms_booking_save_reservations($reservations);

    $admin_email = trim((string)($booking_settings['admin_email'] ?? ''));
    $subject = 'Nowa rezerwacja: ' . $date . ' ' . $time;
    $body = "Nowa rezerwacja\n\nUsługa: {$reservation['service']}\nData: {$date}\nGodzina: {$time}\nImię: {$name}\nE-mail: {$email}\nTelefon: {$phone}\n\nWiadomość:\n{$message}\n";
    if (($booking_settings['notify_admin'] ?? '1') === '1' && $admin_email !== '') {
        spidercms_booking_send_email($admin_email, $subject, $body, $email);
    }
    if (($booking_settings['notify_client'] ?? '1') === '1') {
        spidercms_booking_send_email(
            $email,
            'Potwierdzenie rezerwacji: ' . $date . ' ' . $time,
            "Dzień dobry {$name},\n\nDziękujemy. Twoja rezerwacja została przyjęta.\n\nUsługa: {$reservation['service']}\nData: {$date}\nGodzina: {$time}\nStatus: {$status}\n\nW razie pytań odpowiedz na tę wiadomość.\n",
            $admin_email
        );
    }

    if (function_exists('spidercms_admin_log')) {
        spidercms_admin_log('booking_created_public', ['date'=>$date,'time'=>$time]);
    }

    echo json_encode(['ok'=>true,'message'=>'Rezerwacja została zapisana.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Akcje admina rezerwacji.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $booking_admin_action = $_POST['action'] ?? '';

    if ($booking_admin_action === 'booking_save_settings') {
        verify_csrf_or_die();

        $booking_settings = [
            'enabled' => isset($_POST['enabled']) ? '1' : '0',
            'title' => spidercms_booking_clean($_POST['title'] ?? 'Zarezerwuj termin', 160),
            'subtitle' => spidercms_booking_clean($_POST['subtitle'] ?? '', 300),
            'service_name' => spidercms_booking_clean($_POST['service_name'] ?? 'Konsultacja', 120),
            'slot_minutes' => (string)max(15, (int)($_POST['slot_minutes'] ?? 60)),
            'days_ahead' => (string)max(1, (int)($_POST['days_ahead'] ?? 30)),
            'min_notice_hours' => (string)max(0, (int)($_POST['min_notice_hours'] ?? 4)),
            'work_days' => spidercms_booking_normalize_work_days(
                $_POST['work_days']
                ?? $_POST['booking_work_days']
                ?? $_POST['days']
                ?? $_POST['work_days_csv']
                ?? []
            ),
            'work_start' => spidercms_booking_valid_time($_POST['work_start'] ?? '') ? $_POST['work_start'] : '09:00',
            'work_end' => spidercms_booking_valid_time($_POST['work_end'] ?? '') ? $_POST['work_end'] : '17:00',
            'admin_email' => spidercms_booking_clean($_POST['admin_email'] ?? '', 160),
            'notify_admin' => isset($_POST['notify_admin']) ? '1' : '0',
            'notify_client' => isset($_POST['notify_client']) ? '1' : '0',
            'require_phone' => isset($_POST['require_phone']) ? '1' : '0',
            'confirmation_mode' => in_array(($_POST['confirmation_mode'] ?? 'manual'), ['manual','auto'], true) ? $_POST['confirmation_mode'] : 'manual',
            'email_mode' => in_array(($_POST['email_mode'] ?? 'smtp'), ['smtp','mail'], true) ? $_POST['email_mode'] : 'smtp',
            'from_email' => spidercms_booking_clean($_POST['from_email'] ?? '', 160),
            'from_name' => spidercms_booking_clean($_POST['from_name'] ?? 'SpiderCMS', 120),
            'smtp_host' => spidercms_booking_clean($_POST['smtp_host'] ?? '', 160),
            'smtp_port' => (string)max(1, (int)($_POST['smtp_port'] ?? 587)),
            'smtp_secure' => in_array(($_POST['smtp_secure'] ?? 'tls'), ['tls','ssl','none'], true) ? $_POST['smtp_secure'] : 'tls',
            'smtp_user' => spidercms_booking_clean($_POST['smtp_user'] ?? '', 180),
            'smtp_pass' => (string)($_POST['smtp_pass'] ?? ($booking_settings['smtp_pass'] ?? '')),
        ];

        spidercms_booking_save_json($booking_settings_file, $booking_settings);
        spidercms_booking_create_widget_file();
        if (function_exists('spidercms_admin_log')) spidercms_admin_log('booking_settings_saved');
        header('Location: admin.php?tab=bookings&saved=1');
        exit;
    }

    if ($booking_admin_action === 'booking_update_status') {
        verify_csrf_or_die();

        $id = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? 'new';

        if (!in_array($status, ['new','confirmed','cancelled','done'], true)) {
            $status = 'new';
        }

        $items = spidercms_booking_load_reservations();
        $confirmation_sent_now = false;

        foreach ($items as &$r) {
            if (($r['id'] ?? '') === $id) {
                $old_status = $r['status'] ?? 'new';
                $r['status'] = $status;
                $r['updated_at'] = date('Y-m-d H:i:s');

                // Jeżeli admin ręcznie potwierdza wizytę, wyślij e-mail do klienta.
                // Nie wysyłamy ponownie, jeśli potwierdzenie było już wysłane wcześniej.
                if (
                    $status === 'confirmed'
                    && $old_status !== 'confirmed'
                    && empty($r['client_confirmation_sent_at'])
                ) {
                    $ok = spidercms_booking_send_confirmation_to_client($r);
                    if ($ok) {
                        $r['client_confirmation_sent_at'] = date('Y-m-d H:i:s');
                        $confirmation_sent_now = true;
                    }
                }

                break;
            }
        }
        unset($r);

        spidercms_booking_save_reservations($items);

        if (function_exists('spidercms_admin_log')) {
            spidercms_admin_log('booking_status_updated', [
                'id' => $id,
                'status' => $status,
                'client_confirmation_sent' => $confirmation_sent_now ? '1' : '0'
            ]);
        }

        header('Location: admin.php?tab=bookings&confirmed_mail=' . ($confirmation_sent_now ? '1' : '0'));
        exit;
    }

    if ($booking_admin_action === 'booking_delete') {
        verify_csrf_or_die();
        $id = $_POST['id'] ?? '';
        $items = array_values(array_filter(spidercms_booking_load_reservations(), fn($r) => ($r['id'] ?? '') !== $id));
        spidercms_booking_save_reservations($items);
        if (function_exists('spidercms_admin_log')) spidercms_admin_log('booking_deleted', ['id'=>$id]);
        header('Location: admin.php?tab=bookings');
        exit;
    }


    if ($booking_admin_action === 'booking_test_client_email') {
        verify_csrf_or_die();
        $test_email = spidercms_booking_clean($_POST['test_email'] ?? '', 160);
        $ok = spidercms_booking_send_email(
            $test_email,
            'Test powiadomienia rezerwacji',
            "To jest testowa wiadomość z modułu rezerwacji SpiderCMS.\n\nJeżeli ją widzisz, wysyłka e-mail działa poprawnie.",
            trim((string)($booking_settings['admin_email'] ?? ''))
        );
        header('Location: admin.php?tab=bookings&emailtest=' . ($ok ? '1' : '0'));
        exit;
    }

    if ($booking_admin_action === 'booking_add_blocked') {
        verify_csrf_or_die();
        $blocked = spidercms_booking_load_blocked();
        $date = $_POST['date'] ?? '';
        $from = $_POST['from'] ?? '';
        $to = $_POST['to'] ?? '';
        if (spidercms_booking_valid_date($date)) {
            $blocked[] = [
                'id' => 'blk_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                'date' => $date,
                'from' => spidercms_booking_valid_time($from) ? $from : '',
                'to' => spidercms_booking_valid_time($to) ? $to : '',
                'note' => spidercms_booking_clean($_POST['note'] ?? '', 160),
            ];
            spidercms_booking_save_json($booking_blocked_file, $blocked);
        }
        header('Location: admin.php?tab=bookings');
        exit;
    }

    if ($booking_admin_action === 'booking_delete_blocked') {
        verify_csrf_or_die();
        $id = $_POST['id'] ?? '';
        $blocked = array_values(array_filter(spidercms_booking_load_blocked(), fn($b) => ($b['id'] ?? '') !== $id));
        spidercms_booking_save_json($booking_blocked_file, $blocked);
        header('Location: admin.php?tab=bookings');
        exit;
    }

    if ($booking_admin_action === 'booking_process_shortcodes') {
        verify_csrf_or_die();
        $updated = spidercms_booking_process_shortcodes_in_pages();
        header('Location: admin.php?tab=bookings&shortcodes=' . (int)$updated);
        exit;
    }
}

// Panel rezerwacji.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && (($_GET['tab'] ?? '') === 'bookings')) {
    $reservations = spidercms_booking_load_reservations();
    usort($reservations, function($a, $b) {
        return strcmp(($b['date'] ?? '') . ' ' . ($b['time'] ?? ''), ($a['date'] ?? '') . ' ' . ($a['time'] ?? ''));
    });
    $blocked = spidercms_booking_load_blocked();
    $csrf = csrf_field();
    $work_days = spidercms_booking_normalize_work_days($booking_settings['work_days'] ?? ['1','2','3','4','5']);

    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Rezerwacje – SpiderCMS</title>
        <style>
            body{margin:0;font-family:system-ui,sans-serif;background:#0f172a;color:#f8fafc}
            .booking-admin{display:grid;grid-template-columns:300px 1fr;min-height:100vh}
            .booking-side{background:#111827;border-right:1px solid rgba(255,255,255,.1);padding:1.5rem;position:sticky;top:0;height:100vh;overflow:auto}
            .booking-side a{display:block;color:#cbd5e1;text-decoration:none;padding:.8rem 1rem;border-radius:12px;margin:.35rem 0;background:rgba(255,255,255,.04)}
            .booking-side a:hover{background:rgba(168,85,247,.18);color:white}
            .booking-main{padding:1.5rem;overflow:auto}
            .booking-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
            .booking-card{background:#111827;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:1.2rem;box-shadow:0 18px 50px rgba(0,0,0,.18);margin-bottom:1rem}
            .booking-card h2{margin-top:0}
            label{display:block;margin:.75rem 0 .35rem;font-weight:800;color:#e2e8f0}
            input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #334155;background:#020617;color:#fff;border-radius:12px;padding:.8rem;font:inherit}
            .check-row{display:flex;gap:.7rem;flex-wrap:wrap;margin:.8rem 0}
            .check-row label{display:flex;gap:.35rem;align-items:center;margin:0;font-weight:700}
            .check-row input{width:auto}
            .btn{border:0;border-radius:999px;padding:.75rem 1rem;background:#a855f7;color:#fff;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;margin:.15rem}
            .btn.secondary{background:#334155}.btn.danger{background:#ef4444}.btn.ok{background:#22c55e}
            table{width:100%;border-collapse:collapse;background:#0b1220;border-radius:16px;overflow:hidden}
            th,td{padding:.75rem;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
            th{color:#cbd5e1;background:#020617}
            .status{display:inline-flex;border-radius:999px;padding:.25rem .6rem;font-weight:900;font-size:.8rem;background:#334155}
            .status.confirmed{background:#166534}.status.cancelled{background:#7f1d1d}.status.done{background:#1e40af}.status.new{background:#7c2d12}
            .notice{padding:.8rem 1rem;border-radius:14px;background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.25);margin-bottom:1rem;color:#bbf7d0}
            code{background:#020617;border:1px solid #334155;border-radius:8px;padding:.2rem .45rem}
            @media(max-width:980px){.booking-admin{grid-template-columns:1fr}.booking-side{position:relative;height:auto}.booking-grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}.booking-main{padding:1rem}}
        </style>
    </head>
    <body>
    <div class="booking-admin">
        <aside class="booking-side">
            <h1>📅 Rezerwacje</h1>
            <a href="admin.php?tab=dashboard">← Dashboard</a>
            <a href="admin.php?tab=pages">Strony</a>
<a href="admin.php?logout=1">Wyloguj</a>
            <hr style="border-color:rgba(255,255,255,.1)">
            <p style="color:#94a3b8">Shortcode:</p>
            <code>[booking]</code>
        </aside>

        <main class="booking-main">
            <h1>System rezerwacji</h1>

            <?php if (isset($_GET['saved'])): ?><div class="notice">Zapisano ustawienia rezerwacji.</div><?php endif; ?>
            <?php if (isset($_GET['confirmed_mail'])): ?>
                <div class="notice">
                    <?= $_GET['confirmed_mail'] === '1'
                        ? 'Rezerwacja potwierdzona i wysłano e-mail do klienta.'
                        : 'Status zapisany. E-mail potwierdzający nie został wysłany, bo nie był wymagany albo został już wysłany wcześniej.' ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['shortcodes'])): ?><div class="notice">Przetworzono shortcode na stronach: <?= (int)$_GET['shortcodes'] ?></div><?php endif; ?>

            <div class="booking-grid">
                <section class="booking-card">
                    <h2>Ustawienia</h2>
                    <form method="post">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="booking_save_settings">

                        <label><input type="checkbox" name="enabled" value="1" <?= (($booking_settings['enabled'] ?? '1') === '1') ? 'checked' : '' ?> style="width:auto"> Włącz rezerwacje</label>

                        <label>Tytuł widgetu</label>
                        <input name="title" value="<?= e($booking_settings['title'] ?? '') ?>">

                        <label>Opis</label>
                        <textarea name="subtitle" rows="2"><?= e($booking_settings['subtitle'] ?? '') ?></textarea>

                        <label>Nazwa usługi</label>
                        <input name="service_name" value="<?= e($booking_settings['service_name'] ?? '') ?>">

                        <div class="booking-grid">
                            <div>
                                <label>Długość wizyty / slotu</label>
                                <select name="slot_minutes">
                                    <?php foreach ([15,30,45,60,90,120] as $m): ?>
                                        <option value="<?= $m ?>" <?= ((int)($booking_settings['slot_minutes'] ?? 60) === $m) ? 'selected' : '' ?>><?= $m ?> min</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Dni do przodu</label>
                                <input type="number" name="days_ahead" min="1" max="365" value="<?= e($booking_settings['days_ahead'] ?? '30') ?>">
                            </div>
                        </div>

                        <label>Dni pracy</label>
                        <p style="color:#94a3b8;font-size:.9rem;margin:.4rem 0 1rem">
                            Zapisane dni: <code><?= e(implode(',', spidercms_booking_normalize_work_days($booking_settings['work_days'] ?? []))) ?></code>
                        </p>

                        <input type="hidden" name="booking_work_days_marker" value="1">
                        <input type="hidden" name="work_days_csv" value="">
                        <div class="check-row">
                            <?php $days = ['1'=>'Pon','2'=>'Wt','3'=>'Śr','4'=>'Czw','5'=>'Pt','6'=>'Sob','7'=>'Nd']; ?>
                            <?php foreach ($days as $num=>$label): ?>
                                <label><input type="checkbox" name="work_days[]" value="<?= $num ?>" <?= in_array($num, $work_days, true) ? 'checked' : '' ?>> <?= $label ?></label>
                            <?php endforeach; ?>
                        </div>

                        <div class="booking-grid">
                            <div>
                                <label>Od</label>
                                <input type="time" name="work_start" value="<?= e($booking_settings['work_start'] ?? '09:00') ?>">
                            </div>
                            <div>
                                <label>Do</label>
                                <input type="time" name="work_end" value="<?= e($booking_settings['work_end'] ?? '17:00') ?>">
                            </div>
                        </div>

                        <label>Minimalne wyprzedzenie (godziny)</label>
                        <input type="number" name="min_notice_hours" min="0" max="168" value="<?= e($booking_settings['min_notice_hours'] ?? '4') ?>">

                        <label>E-mail administratora</label>
                        <input type="email" name="admin_email" value="<?= e($booking_settings['admin_email'] ?? '') ?>">

                        <label><input type="checkbox" name="notify_admin" value="1" <?= (($booking_settings['notify_admin'] ?? '1') === '1') ? 'checked' : '' ?> style="width:auto"> Powiadom admina</label>
                        <label><input type="checkbox" name="notify_client" value="1" <?= (($booking_settings['notify_client'] ?? '1') === '1') ? 'checked' : '' ?> style="width:auto"> Potwierdzenie do klienta</label>
                        <label><input type="checkbox" name="require_phone" value="1" <?= (($booking_settings['require_phone'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto"> Telefon wymagany</label>

                        <label>Tryb potwierdzania</label>
                        <select name="confirmation_mode">
                            <option value="manual" <?= (($booking_settings['confirmation_mode'] ?? 'manual') === 'manual') ? 'selected' : '' ?>>Ręczne</option>
                            <option value="auto" <?= (($booking_settings['confirmation_mode'] ?? 'manual') === 'auto') ? 'selected' : '' ?>>Automatyczne</option>
                        </select>

                        <button class="btn" type="submit">Zapisz ustawienia</button>
                    </form>

                    <form method="post" style="margin-top:1rem">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="booking_test_client_email">
                        <label>Test wiadomości do klienta</label>
                        <input type="email" name="test_email" placeholder="adres testowy klienta" required>
                        <button class="btn secondary" type="submit">Wyślij test do klienta</button>
                    </form>
                </section>

                <section class="booking-card">
                    <h2>Blokady terminów</h2>
                    <form method="post">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="booking_add_blocked">
                        <label>Data</label>
                        <input type="date" name="date" required>
                        <div class="booking-grid">
                            <div><label>Od</label><input type="time" name="from"></div>
                            <div><label>Do</label><input type="time" name="to"></div>
                        </div>
                        <label>Notatka</label>
                        <input name="note" placeholder="np. urlop, spotkanie">
                        <button class="btn secondary" type="submit">Dodaj blokadę</button>
                    </form>

                    <h3>Aktualne blokady</h3>
                    <table>
                        <thead><tr><th>Data</th><th>Godziny</th><th>Notatka</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($blocked as $b): ?>
                            <tr>
                                <td><?= e($b['date'] ?? '') ?></td>
                                <td><?= e(($b['from'] ?? '') . ' - ' . ($b['to'] ?? '')) ?></td>
                                <td><?= e($b['note'] ?? '') ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Usunąć blokadę?')">
                                        <?= $csrf ?>
                                        <input type="hidden" name="action" value="booking_delete_blocked">
                                        <input type="hidden" name="id" value="<?= e($b['id'] ?? '') ?>">
                                        <button class="btn danger" type="submit">Usuń</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3>Wstawienie na stronę</h3>
                    <p>W treści strony wklej:</p>
                    <code>[booking]</code>
                    <form method="post" style="margin-top:1rem">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="booking_process_shortcodes">
                        <button class="btn ok" type="submit">Przetwórz shortcode na stronach</button>
                    </form>
                </section>
            </div>

            <section class="booking-card">
                <h2>Lista rezerwacji</h2>
                <table>
                    <thead>
                    <tr>
                        <th>Termin</th><th>Klient</th><th>Kontakt</th><th>Wiadomość</th><th>Status</th><th>Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reservations as $r): ?>
                        <tr>
                            <td><strong><?= e($r['date'] ?? '') ?></strong><br><?= e($r['time'] ?? '') ?></td>
                            <td><?= e($r['name'] ?? '') ?><br><small><?= e($r['service'] ?? '') ?></small></td>
                            <td><?= e($r['email'] ?? '') ?><br><?= e($r['phone'] ?? '') ?></td>
                            <td><?= e($r['message'] ?? '') ?></td>
                            <td>
                                <span class="status <?= e($r['status'] ?? 'new') ?>"><?= e($r['status'] ?? 'new') ?></span>
                                <?php if (!empty($r['client_confirmation_sent_at'])): ?>
                                    <br><small>Potwierdzenie klienta: <?= e($r['client_confirmation_sent_at']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" style="display:inline-block">
                                    <?= $csrf ?>
                                    <input type="hidden" name="action" value="booking_update_status">
                                    <input type="hidden" name="id" value="<?= e($r['id'] ?? '') ?>">
                                    <select name="status" onchange="this.form.submit()">
                                        <?php foreach (['new'=>'Nowa','confirmed'=>'Potwierdzona','done'=>'Zrealizowana','cancelled'=>'Anulowana'] as $k=>$v): ?>
                                            <option value="<?= $k ?>" <?= (($r['status'] ?? 'new') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="post" style="display:inline-block" onsubmit="return confirm('Usunąć rezerwację?')">
                                    <?= $csrf ?>
                                    <input type="hidden" name="action" value="booking_delete">
                                    <input type="hidden" name="id" value="<?= e($r['id'] ?? '') ?>">
                                    <button class="btn danger" type="submit">Usuń</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$reservations): ?>
                        <tr><td colspan="6">Brak rezerwacji.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
    
<script>
(function(){
    document.querySelectorAll('form').forEach(function(form){
        if(!form.querySelector('input[name="work_days[]"]')) return;
        form.addEventListener('submit', function(){
            var hidden = form.querySelector('input[name="work_days_csv"]');
            if(!hidden) return;
            var vals = Array.from(form.querySelectorAll('input[name="work_days[]"]:checked')).map(function(i){ return i.value; });
            hidden.value = vals.join(',');
        });
    });
})();
</script>

</body>
    </html>
    <?php
    exit;
}
// SPIDERCMS BOOKING SYSTEM END


// ----------------------------------------------------------------------
// Publiczne endpointy czatu – działają bez logowania do panelu
// ----------------------------------------------------------------------
$public_action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($public_action === 'chat_public_send') {
    chat_send_json(chat_public_add_message($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['message'] ?? '', $_POST['website'] ?? ''));
}
if ($public_action === 'chat_public_get') {
    chat_send_json(chat_public_get_messages());
}
if ($public_action === 'stats_track') {
    chat_send_json(stats_track_event($_POST));
}


// ----------------------------------------------------------------------
// Obsługa resetu hasła z ekranu logowania
// ----------------------------------------------------------------------
$reset_notice = '';
$reset_error = '';

if (!empty($_GET['reset_token']) && (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true)) {
    $reset_token = trim((string)$_GET['reset_token']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_token'], $_POST['new_password'], $_POST['repeat_password'])) {
        if (!hash_equals((string)$_POST['reset_token'], $reset_token)) {
            $reset_error = 'Nieprawidłowy token resetu.';
        } elseif ((string)$_POST['new_password'] !== (string)$_POST['repeat_password']) {
            $reset_error = 'Hasła nie są takie same.';
        } elseif (strlen((string)$_POST['new_password']) < 6) {
            $reset_error = 'Nowe hasło musi mieć minimum 6 znaków.';
        } elseif (spidercms_password_reset_apply($reset_token, (string)$_POST['new_password'])) {
            $reset_notice = 'Hasło zostało zmienione. Możesz się teraz zalogować.';
        } else {
            $reset_error = 'Nie udało się zresetować hasła. Link mógł wygasnąć.';
        }
    }

    if ($reset_notice === '' && !spidercms_password_reset_get_valid($reset_token)) {
        $reset_error = $reset_error !== '' ? $reset_error : 'Link resetu hasła jest nieprawidłowy albo wygasł.';
    }

    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reset hasła – SpiderCMS</title>
        <link rel="icon" type="image/png" href="/assets/images/spidercms-icon.png">
        <style>
            body{font-family:system-ui,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}
            .card{background:#1e293b;padding:2.5rem 2.2rem;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,0.4);width:100%;max-width:420px;border:1px solid #334155;color:#f8fafc;}
            h1{text-align:center;color:#a855f7;margin:0 0 1rem;font-size:1.9rem;font-weight:700;}
            input{width:100%;padding:1rem;margin:.65rem 0;border:1px solid #334155;background:#0f172a;color:#f8fafc;border-radius:8px;font-size:1.05rem;box-sizing:border-box;}
            button,.btn{width:100%;padding:1rem;background:#a855f7;color:white;border:none;border-radius:8px;font-size:1.05rem;font-weight:600;cursor:pointer;text-decoration:none;display:block;text-align:center;box-sizing:border-box;}
            button:hover,.btn:hover{background:#7e22ce;}
            .error{color:#fca5a5;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);padding:.75rem;border-radius:8px;text-align:center;margin-bottom:1rem;}
            .success{color:#bbf7d0;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);padding:.75rem;border-radius:8px;text-align:center;margin-bottom:1rem;}
            a{color:#c084fc;text-decoration:none}
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Reset hasła</h1>

            <?php if ($reset_notice): ?>
                <div class="success"><?= e($reset_notice) ?></div>
                <a class="btn" href="admin.php">Wróć do logowania</a>
            <?php else: ?>
                <?php if ($reset_error): ?><div class="error"><?= e($reset_error) ?></div><?php endif; ?>
                <?php if (spidercms_password_reset_get_valid($reset_token)): ?>
                    <form method="post">
                        <input type="hidden" name="reset_token" value="<?= e($reset_token) ?>">
                        <input type="password" name="new_password" placeholder="Nowe hasło" required minlength="6" autofocus>
                        <input type="password" name="repeat_password" placeholder="Powtórz nowe hasło" required minlength="6">
                        <button type="submit">Ustaw nowe hasło</button>
                    </form>
                <?php endif; ?>
                <p style="text-align:center;margin-top:1rem;"><a href="admin.php">Wróć do logowania</a></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (($_GET['forgot'] ?? '') === '1' && (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_login'])) {
        $reset = spidercms_password_reset_create_token($_POST['reset_login']);
        spidercms_log_action('password_reset_request', $reset ? 'success' : 'warning', ['login' => $_POST['reset_login'] ?? '']);

        // Zawsze pokazujemy neutralny komunikat, żeby nie ujawniać, czy konto istnieje.
        $reset_notice = 'Jeżeli konto istnieje, przygotowano link resetu hasła.';

        if ($reset && !empty($reset['user']['email'])) {
            $sent = spidercms_password_reset_send_email($reset['user']['email'], $reset['url'], $reset['user']['username'] ?? '');
            if (!$sent) {
                $reset_notice .= ' Nie udało się wysłać e-maila przez funkcję mail(). Link awaryjny zapisano w pliku .users/password_reset_last.txt.';
                @file_put_contents(SPIDERCMS_ADMIN_USERS_DIR . '/password_reset_last.txt', $reset['url'], LOCK_EX);
                @chmod(SPIDERCMS_ADMIN_USERS_DIR . '/password_reset_last.txt', 0640);
            }
        } elseif ($reset) {
            $reset_notice .= ' Konto nie ma adresu e-mail, więc link awaryjny zapisano w pliku .users/password_reset_last.txt.';
            @file_put_contents(SPIDERCMS_ADMIN_USERS_DIR . '/password_reset_last.txt', $reset['url'], LOCK_EX);
            @chmod(SPIDERCMS_ADMIN_USERS_DIR . '/password_reset_last.txt', 0640);
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Nie pamiętasz hasła? – SpiderCMS</title>
        <link rel="icon" type="image/png" href="/assets/images/spidercms-icon.png">
        <style>
            body{font-family:system-ui,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}
            .card{background:#1e293b;padding:2.5rem 2.2rem;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,0.4);width:100%;max-width:420px;border:1px solid #334155;color:#f8fafc;}
            h1{text-align:center;color:#a855f7;margin:0 0 1rem;font-size:1.9rem;font-weight:700;}
            p{color:#cbd5e1;line-height:1.55}
            input{width:100%;padding:1rem;margin:.75rem 0;border:1px solid #334155;background:#0f172a;color:#f8fafc;border-radius:8px;font-size:1.05rem;box-sizing:border-box;}
            button,.btn{width:100%;padding:1rem;background:#a855f7;color:white;border:none;border-radius:8px;font-size:1.05rem;font-weight:600;cursor:pointer;text-decoration:none;display:block;text-align:center;box-sizing:border-box;}
            button:hover,.btn:hover{background:#7e22ce;}
            .success{color:#bbf7d0;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);padding:.75rem;border-radius:8px;text-align:center;margin-bottom:1rem;}
            a{color:#c084fc;text-decoration:none}
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Nie pamiętasz hasła?</h1>
            <?php if ($reset_notice): ?>
                <div class="success"><?= e($reset_notice) ?></div>
                <a class="btn" href="admin.php">Wróć do logowania</a>
            <?php else: ?>
                <p>Podaj login lub adres e-mail konta. Jeśli konto istnieje, zostanie utworzony link resetu hasła ważny przez 1 godzinę.</p>
                <form method="post">
                    <input type="text" name="reset_login" placeholder="Login lub e-mail" required autofocus>
                    <button type="submit">Zresetuj hasło</button>
                </form>
                <p style="text-align:center;margin-top:1rem;"><a href="admin.php">Wróć do logowania</a></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}


// ----------------------------------------------------------------------
// Ekran logowania
// ----------------------------------------------------------------------
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && $_SESSION['login_block_until'] <= time()) {
        $spidercms_login_user = spidercms_admin_authenticate_user($_POST['username'] ?? 'admin', $_POST['password'] ?? '', $ADMIN_HASH);
        if ($spidercms_login_user !== false) {
            spidercms_log_action('login_success', 'success');
            spidercms_security_log('login_success');
            $_SESSION['logged_in'] = true;
            $_SESSION['admin_user_id'] = $spidercms_login_user['id'] ?? '';
            $_SESSION['admin_username'] = $spidercms_login_user['username'] ?? 'admin';
            $_SESSION['admin_display_name'] = $spidercms_login_user['display_name'] ?? ($_SESSION['admin_username'] ?? 'admin');
            $_SESSION['admin_user_role'] = $spidercms_login_user['role'] ?? 'admin';
            $_SESSION['last_activity'] = time();
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_block_until'] = 0;
            header('Location: admin.php?tab=dashboard');
            exit;
        } else {
            spidercms_log_action('login_failed', 'error', ['attempts' => (int)($_SESSION['login_attempts'] ?? 0) + 1]);
            spidercms_security_log('login_failed');
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= $MAX_LOGIN_ATTEMPTS) {
                $_SESSION['login_block_until'] = time() + $BLOCK_DURATION;
                $login_error = "Zbyt wiele prób. Blokada na 15 minut.";
            } else {
                $left = $MAX_LOGIN_ATTEMPTS - $_SESSION['login_attempts'];
                $login_error = "Nieprawidłowe hasło. Pozostało $left prób.";
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Logowanie – Panel SpiderCMS</title>
        <link rel="icon" type="image/png" href="/assets/images/spidercms-icon.png">
        <style>
            body{font-family:system-ui,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}
            .card{background:#1e293b;padding:2.5rem 2.2rem;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,0.4);width:100%;max-width:400px;border:1px solid #334155;color:#f8fafc;}
            .login-logo-container{text-align:center;margin-bottom:1.5rem;}
            .login-logo-container img{max-height:90px;width:auto;display:block;margin:0 auto 0.5rem;border-radius:8px;}
            h1{text-align:center;color:#a855f7;margin:0;font-size:1.9rem;font-weight:700;}
            input{width:100%;padding:1rem;margin:1.5rem 0;border:1px solid #334155;background:#0f172a;color:#f8fafc;border-radius:8px;font-size:1.05rem;box-sizing:border-box;}
            input:focus{outline:2px solid #a855f7;}
            button{width:100%;padding:1rem;background:#a855f7;color:white;border:none;border-radius:8px;font-size:1.05rem;font-weight:600;cursor:pointer;}
            button:hover{background:#7e22ce;}
            .error{color:#ef4444;text-align:center;margin-bottom:1.2rem;font-weight:500;}
        </style>
    </head>
    <body>
        <div class="card">
            <div class="login-logo-container">
                <?php if ($logo_url): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="SpiderCMS Logo">
                <?php endif; ?>
                <h1>SpiderCMS</h1>
            </div>
            <?php if ($login_error): ?>
                <div class="error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <?php if ($_SESSION['login_block_until'] <= time()): ?>
                <form method="post">
                    <input type="text" name="username" placeholder="Login" value="admin" required autocomplete="username" autofocus>
                    <input type="password" name="password" placeholder="Hasło" required autocomplete="current-password">
                    <button type="submit">Zaloguj się</button>
                </form>
                    <p style="text-align:center;margin:.9rem 0 0;">
                        <a href="admin.php?forgot=1" style="color:#c084fc;text-decoration:none;font-weight:600;">Nie pamiętasz hasła?</a>
                    </p>
            <?php else: ?>
                <p style="text-align:center; margin-top:1.5rem;">Spróbuj ponownie za chwilę.</p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php exit;
}

// ----------------------------------------------------------------------
// Panel zalogowany
// ----------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'dashboard';

if ($tab === 'ustawienia' && !spidercms_admin_can_access_settings()) {
    spidercms_log_action('permission_denied_settings_tab', 'error', [
        'role' => spidercms_admin_current_role(),
        'username' => spidercms_admin_current_username()
    ]);
    $toast = ['type'=>'error','msg'=>'Brak uprawnień do zakładki Ustawienia.'];
    $tab = 'dashboard';
}
$spidercms_is_page_edit_mode = isset($_GET['edit']) || isset($_GET['edit_page']) || (($_GET['action'] ?? '') === 'edit') || (($_GET['mode'] ?? '') === 'edit');

// ----------------------------------------------------------------------
// Eksport logów akcji administratora
// ----------------------------------------------------------------------
if (isset($_GET['export_logs']) && $_GET['export_logs'] === '1') {
    $export_format = strtolower((string)($_GET['format'] ?? 'csv'));
    $export_action = trim((string)($_GET['log_action'] ?? ''));
    $export_status = trim((string)($_GET['log_status'] ?? ''));
    $export_from = trim((string)($_GET['date_from'] ?? ''));
    $export_to = trim((string)($_GET['date_to'] ?? ''));
    $export_logs = spidercms_read_action_logs_for_export($export_action, $export_status, $export_from, $export_to);
    spidercms_log_action('export_action_logs', 'success', [
        'format'=>$export_format,
        'log_action'=>$export_action,
        'log_status'=>$export_status,
        'date_from'=>$export_from,
        'date_to'=>$export_to,
        'count'=>count($export_logs)
    ]);
    spidercms_export_logs($export_format, $export_logs);
}


// ----------------------------------------------------------------------
// Obsługa POST
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    verify_csrf_or_die();
    spidercms_log_action($action !== '' ? $action : 'unknown_post', 'info', $_POST);

    if (in_array($action, spidercms_admin_settings_actions(), true) && !spidercms_admin_can_access_settings()) {
        spidercms_log_action('permission_denied_settings_action', 'error', [
            'blocked_action' => $action,
            'role' => spidercms_admin_current_role(),
            'username' => spidercms_admin_current_username()
        ]);
        $toast = ['type'=>'error','msg'=>'Brak uprawnień do zmiany ustawień witryny.'];
        $action = '__permission_denied_settings__';
    }


    if (in_array($action, ['add_admin_user','update_admin_user','delete_admin_user'], true)) {
        spidercms_admin_require_role(['admin']);
        $users = spidercms_admin_users_load();

        if ($action === 'add_admin_user') {
            $username = spidercms_admin_user_clean_username($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $exists = false;

            foreach ($users as $u) {
                if (($u['username'] ?? '') === $username) { $exists = true; break; }
            }

            if ($username === '' || strlen($password) < 6) {
                $toast = ['type'=>'error','msg'=>'Podaj login oraz hasło minimum 6 znaków.'];
            } elseif ($exists) {
                $toast = ['type'=>'error','msg'=>'Użytkownik o takim loginie już istnieje.'];
            } else {
                $users[] = [
                    'id' => 'adm_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
                    'username' => $username,
                    'display_name' => trim((string)($_POST['display_name'] ?? $username)),
                    'email' => filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['email']) : '',
                    'role' => spidercms_admin_user_clean_role($_POST['role'] ?? 'editor'),
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'last_login_at' => '',
                    'last_login_ip_hash' => '',
                ];
                spidercms_admin_users_save($users);
                spidercms_log_action('add_admin_user', 'success', ['username' => $username]);
                header('Location: admin.php?tab=uzytkownicy&saved=1');
                exit;
            }
        }

        if ($action === 'update_admin_user') {
            $id = (string)($_POST['user_id'] ?? '');
            $updated = false;

            foreach ($users as &$user) {
                if (($user['id'] ?? '') !== $id) continue;

                $is_self = (($user['username'] ?? '') === spidercms_admin_current_username());
                $user['display_name'] = trim((string)($_POST['display_name'] ?? ($user['display_name'] ?? '')));
                $user['email'] = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['email']) : '';

                if (!$is_self) {
                    $user['role'] = spidercms_admin_user_clean_role($_POST['role'] ?? ($user['role'] ?? 'editor'));
                    $user['active'] = isset($_POST['active']);
                } else {
                    $user['active'] = true;
                }

                $new_password = (string)($_POST['new_password'] ?? '');
                if ($new_password !== '') {
                    if (strlen($new_password) < 6) {
                        $toast = ['type'=>'error','msg'=>'Nowe hasło musi mieć minimum 6 znaków.'];
                        break;
                    }
                    $user['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                }

                $updated = true;
                break;
            }
            unset($user);

            if ($updated && $toast['type'] !== 'error') {
                spidercms_admin_users_save($users);
                spidercms_log_action('update_admin_user', 'success', ['user_id' => $id]);
                header('Location: admin.php?tab=uzytkownicy&saved=1');
                exit;
            } elseif ($toast['type'] !== 'error') {
                $toast = ['type'=>'error','msg'=>'Nie znaleziono użytkownika.'];
            }
        }

        if ($action === 'delete_admin_user') {
            $id = (string)($_POST['user_id'] ?? '');
            $current = spidercms_admin_current_username();
            $new = [];
            $deleted = false;

            foreach ($users as $user) {
                if (($user['id'] ?? '') === $id && (($user['username'] ?? '') !== $current)) {
                    $deleted = true;
                    continue;
                }
                $new[] = $user;
            }

            if ($deleted) {
                spidercms_admin_users_save($new);
                spidercms_log_action('delete_admin_user', 'success', ['user_id' => $id]);
                header('Location: admin.php?tab=uzytkownicy&deleted=1');
                exit;
            } else {
                $toast = ['type'=>'error','msg'=>'Nie można usunąć tego użytkownika.'];
            }
        }
    }


    if ($action === 'clear_action_logs') {
        @file_put_contents(SPIDERCMS_ACTION_LOG_FILE, '');
        spidercms_log_action('clear_action_logs', 'success');
        $toast = ['type'=>'success','msg'=>'Wyczyszczono logi akcji administratora.'];
    }
    if ($action === 'save_stats_settings') {
        $stats_settings['enabled'] = isset($_POST['stats_enabled']) ? '1' : '0';
        $stats_settings['ignore_admin'] = isset($_POST['stats_ignore_admin']) ? '1' : '0';
        $stats_settings['ignore_bots'] = isset($_POST['stats_ignore_bots']) ? '1' : '0';
        $stats_settings['throttle_minutes'] = max(1, min(1440, (int)($_POST['stats_throttle_minutes'] ?? 30)));
        $stats_settings['online_minutes'] = max(1, min(60, (int)($_POST['stats_online_minutes'] ?? 5)));
        stats_json_write('settings.json', $stats_settings);
        stats_write_widget_file();
        $updated_stats_pages = stats_sync_widget_in_pages();
        $toast = ['type'=>'success','msg'=>'Zapisano ustawienia statystyk. Zaktualizowano stron: ' . $updated_stats_pages];
    }
    if ($action === 'reset_stats') {
        foreach (['visits_daily.json','unique_daily.json','page_views.json','devices.json','browsers.json','referrers.json','online.json','recent.json','events.jsonl'] as $sf) {
            $fp = $stats_dir . '/' . $sf;
            if (file_exists($fp)) @unlink($fp);
        }
        $toast = ['type'=>'success','msg'=>'Wyczyszczono statystyki odwiedzin.'];
    }

    if ($action === 'save_slider') {
        $all = slider_load_all();
        $old_id = slider_slug($_POST['old_slider_id'] ?? '');
        $slider = slider_normalize($_POST);
        if (empty($slider['images'])) {
            $toast = ['type'=>'error','msg'=>'Slider musi mieć przynajmniej jedno zdjęcie.'];
        } else {
            if ($old_id !== '' && $old_id !== $slider['id'] && isset($all[$old_id])) {
                unset($all[$old_id]);
            }
            $all[$slider['id']] = $slider;
            slider_save_all($all);
            slider_write_widget_file();
            $updated_slider_pages = slider_sync_widget_in_pages();
            $toast = ['type'=>'success','msg'=>'Zapisano slider. Shortcode: [slider id="' . $slider['id'] . '"]. Zsynchronizowano stron: ' . $updated_slider_pages];
            $_GET['edit_slider'] = $slider['id'];
        }
    }
    if ($action === 'delete_slider') {
        $id = slider_slug($_POST['slider_id'] ?? '');
        $all = slider_load_all();
        if ($id !== '' && isset($all[$id])) {
            unset($all[$id]);
            slider_save_all($all);
            slider_write_widget_file();
            $toast = ['type'=>'success','msg'=>'Usunięto slider.'];
        } else {
            $toast = ['type'=>'error','msg'=>'Nie znaleziono slidera do usunięcia.'];
        }
    }
    // AKCJA: TWORZENIE STRONY
    if ($action === 'create') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', trim(strtolower($_POST['slug'] ?? '')));
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $create_page_folder = spidercms_sanitize_page_folder($_POST['page_folder'] ?? ($GLOBALS['active_page_folder'] ?? 'pages'));
        $create_pages_dir = spidercms_page_folder_dir($create_page_folder);
        $create_pages_url = spidercms_page_folder_url($create_page_folder);
        $create_pages_depth = spidercms_page_folder_depth($create_page_folder);
        if (!is_dir($create_pages_dir)) { @mkdir($create_pages_dir, 0755, true); }
        if ($slug && $title) {
            $file = $create_pages_dir . '/' . $slug . '.php';
            if (file_exists($file)) {
                $toast = ['type'=>'error', 'msg'=>'Taki slug już istnieje'];
            } else {
                $template = <<<'PHP'
<?php
require_once dirname(__DIR__, __ROOT_DEPTH__) . '/config.php';
$spidercms_root_dir = dirname(__DIR__, __ROOT_DEPTH__);
$settings_file = $spidercms_root_dir . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode((string)file_get_contents($settings_file), true) : [];
if (!is_array($settings)) { $settings = []; }
if (!array_key_exists('header_enabled', $settings)) { $settings['header_enabled'] = '1'; }
$logo_url = $settings['logo'] ?? ((defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png');
$menu_enabled = file_exists($spidercms_root_dir . '/.menu_enabled');
$menu_items = json_decode(@file_get_contents($spidercms_root_dir . '/.menu.json') ?: '[]', true);
if (!is_array($menu_items)) { $menu_items = []; }
if ((string)($settings['header_enabled'] ?? '1') === '1') {
    require_once $spidercms_root_dir . '/header.php';
}
$title = '__TITLE__';
$content = <<<HTML
__CONTENT__
HTML;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($title); ?> • <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <?php require_once dirname(__DIR__, __ROOT_DEPTH__) . '/social-meta.php'; ?>
  <style>
    :root {
      --primary: <?php echo $theme['primary'] ?? '#a855f7'; ?>;
      --primary-dark: <?php echo $theme['primary-dark'] ?? '#7e22ce'; ?>;
      --accent: <?php echo $theme['accent'] ?? '#2563eb'; ?>;
      --page-bg: <?php echo $theme['page-bg'] ?? '#f9fafb'; ?>;
      --page-text: <?php echo $theme['page-text'] ?? '#111827'; ?>;
      --header-bg: <?php echo $theme['header-bg'] ?? '#ffffff'; ?>;
      --header-text: <?php echo $theme['header-text'] ?? '#374151'; ?>;
      --footer-bg: <?php echo $theme['footer-bg'] ?? '#1f2937'; ?>;
      --footer-text: <?php echo $theme['footer-text'] ?? '#f3f4f6'; ?>;
      --footer-muted: <?php echo $theme['footer-muted'] ?? '#9ca3af'; ?>;
      --link-color: <?php echo $theme['link-color'] ?? '#a855f7'; ?>;
      --button-bg: <?php echo $theme['button-bg'] ?? '#a855f7'; ?>;
      --button-text: <?php echo $theme['button-text'] ?? '#ffffff'; ?>;
      --font-family: <?php echo $theme['font-family'] ?? 'system-ui, sans-serif'; ?>;
      --header-height: <?php echo preg_replace('/[^0-9.]/', '', $theme['header-height'] ?? '74'); ?>px;
      --logo-height: <?php echo preg_replace('/[^0-9.]/', '', $theme['logo-height'] ?? '100'); ?>px;
      --content-width: <?php echo preg_replace('/[^0-9.]/', '', $theme['content-width'] ?? '1240'); ?>px;
      --radius: <?php echo preg_replace('/[^0-9.]/', '', $theme['border-radius'] ?? '10'); ?>px;
      --header-shadow: <?php echo !empty($theme['shadow-enabled']) ? '0 2px 10px rgba(0,0,0,0.08)' : 'none'; ?>;
      --menu-position: <?php echo $theme['menu-position'] ?? 'right'; ?>;
      --gray50: #f9fafb;
      --gray800: #1f2937;
    }
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:var(--font-family);line-height:1.6;color:var(--page-text);background:var(--page-bg);display:flex;flex-direction:column;min-height:100vh;}
    .cms-hero{padding:3rem 2rem;border-radius:18px;background:rgba(168,85,247,0.10);margin:1.5rem auto;max-width:var(--content-width);}
    .cms-hero h1{font-size:clamp(2rem,4vw,3.4rem);line-height:1.1;margin-bottom:1rem;color:var(--primary);}
    .cms-btn{display:inline-block;padding:.85rem 1.25rem;border-radius:999px;background:var(--button-bg);color:var(--button-text);text-decoration:none;font-weight:700;}
    .cms-columns{display:grid;grid-template-columns:1fr 1fr;gap:2rem;max-width:var(--content-width);margin:1.5rem auto;}
    .cms-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;max-width:var(--content-width);margin:1.5rem auto;}
    .cms-card{padding:1.2rem;border:1px solid rgba(0,0,0,0.08);border-radius:var(--radius);background:#fff;}
    .cms-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;max-width:var(--content-width);margin:1.5rem auto;}
    .cms-gallery img{width:100%;height:auto;border-radius:var(--radius);display:block;}
    .cms-faq,.cms-contact{max-width:var(--content-width);margin:1.5rem auto;}
    .cms-faq details{padding:1rem;border:1px solid rgba(0,0,0,0.08);border-radius:var(--radius);margin:.7rem 0;background:#fff;}
    @media (max-width:768px){.cms-columns{grid-template-columns:1fr;}}
    .site-header{position:fixed;top:0;left:0;right:0;background:var(--header-bg,#ffffff)!important;background-color:var(--header-bg,#ffffff)!important;opacity:1!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;box-shadow:var(--header-shadow);z-index:1000;text-align:left;}
    .header-container{max-width:var(--content-width);margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:var(--header-height);text-align:left;}
    .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;justify-content:flex-start;text-align:left;margin-right:auto;}
    .logo{height:100%;}.logo img{max-height:min(var(--logo-height), calc(var(--header-height) - 14px));height:auto;width:auto;max-width:min(260px,40vw);object-fit:contain;display:block;margin:0;}
    .nav-menu{display:flex;gap:2rem;align-items:center;}
    .nav-menu a{color:var(--header-text);text-decoration:none;font-weight:500;padding:0.5rem 1rem;display:flex;align-items:center;gap:0.5rem;}
    .nav-menu a:hover{color:var(--primary);}
    .nav-menu a img{height:28px;width:auto;vertical-align:middle;}
    .menu-toggle{display:none;font-size:1.9rem;cursor:pointer;color:#374151;}
    @media (max-width:768px){
      .nav-menu{display:none;position:absolute;top:74px;left:0;right:0;background:white;flex-direction:column;padding:1.5rem;box-shadow:0 6px 16px rgba(0,0,0,0.1);}
      .nav-menu.active{display:flex;}
      .menu-toggle{display:block;}
    }
    main{margin-top:calc(var(--header-height) + 16px);padding:2rem 1rem;flex:1;width:100%;max-width:var(--content-width);margin-left:auto;margin-right:auto;}
    .site-footer{background:var(--footer-bg);color:var(--footer-text);padding:3rem 1.5rem;margin-top:5rem;font-size:0.95rem;}
    .footer-container{max-width:var(--content-width);margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:2.5rem;text-align:left;}
    .footer-col h4{color:var(--primary);margin-bottom:1rem;font-size:1.15rem;}
    .footer-col p{text-align:justify;}
    .footer-col a{color:var(--footer-muted);text-decoration:none;}
    .footer-col a:hover{color:white;}
    .footer-bottom{max-width:var(--content-width);margin:2rem auto 0;padding-top:1.5rem;border-top:1px solid #374151;text-align:justify;color:var(--footer-muted);}
  </style>
</head>
<body>
<?php // Nagłówek wczytany z header.php ?>
<main><?php echo $content; ?></main>
<?php
// ZMIANA: Zamiast generować stopkę w każdym pliku osobno, wczytujemy globalny plik footer.php
require_once dirname(__DIR__, __ROOT_DEPTH__) . '/footer.php';
require_once dirname(__DIR__, __ROOT_DEPTH__) . '/chat-widget.php';
require_once dirname(__DIR__, __ROOT_DEPTH__) . '/slider-widget.php';
?>

<script>
(function(){
    const buttons = document.querySelectorAll('.settings-tab-btn');
    const panels = document.querySelectorAll('.settings-panel');
    if (!buttons.length || !panels.length) return;
    const storageKey = 'spidercms_settings_active_tab';
    function activate(tabName) {
        buttons.forEach(btn => btn.classList.toggle('active', btn.dataset.settingsTab === tabName));
        panels.forEach(panel => panel.classList.toggle('active', panel.dataset.settingsPanel === tabName));
        try { localStorage.setItem(storageKey, tabName); } catch(e) {}
    }
    buttons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.settingsTab)));
    const params = new URLSearchParams(window.location.search);
    let saved = params.get('settings') || 'general';
    if (!saved) { try { saved = localStorage.getItem(storageKey) || 'general'; } catch(e) {} }
    if (!document.querySelector('.settings-tab-btn[data-settings-tab="' + saved + '"]')) saved = 'general';
    activate(saved);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const preset = document.getElementById('content_width_preset');
    const input = document.getElementById('content_width');
    if (!preset || !input) return;
    preset.addEventListener('change', function(){
        if (this.value) input.value = this.value;
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const list = document.getElementById('footer-columns-list');
    const addBtn = document.getElementById('add-footer-column');
    const template = document.getElementById('footer-column-template');
    if (!list || !addBtn || !template) return;

    function refreshFooterColumnLabels() {
        list.querySelectorAll('.footer-column-item').forEach(function(item, idx){
            const title = item.querySelector('h4');
            if (title) title.textContent = 'Kolumna ' + (idx + 2);
        });
    }

    function bindRemoveButtons() {
        list.querySelectorAll('.remove-footer-column').forEach(function(btn){
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function(){
                const item = btn.closest('.footer-column-item');
                if (item && confirm('Usunąć tę kolumnę stopki?')) {
                    item.remove();
                    refreshFooterColumnLabels();
                }
            });
        });
    }

    addBtn.addEventListener('click', function(){
        if (list.querySelectorAll('.footer-column-item').length >= 12) {
            alert('Maksymalnie można dodać 12 dodatkowych kolumn stopki.');
            return;
        }
        const node = template.content.cloneNode(true);
        list.appendChild(node);
        bindRemoveButtons();
        refreshFooterColumnLabels();
    });

    bindRemoveButtons();
    refreshFooterColumnLabels();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.addEventListener('click', function(event){
        const addBtn = event.target.closest('.add-submenu-btn');
        if (addBtn) {
            event.preventDefault();
            const parent = addBtn.getAttribute('data-parent');
            const list = document.getElementById('submenu-list-' + parent);
            if (!list) return;

            const row = document.createElement('div');
            row.className = 'submenu-row';
            row.innerHTML = '' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – nazwa</label>' +
                    '<input type="text" name="submenu_label[' + parent + '][]" placeholder="np. Projektowanie stron">' +
                '</div>' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – link</label>' +
                    '<input type="text" name="submenu_url[' + parent + '][]" placeholder="/projektowanie-stron">' +
                '</div>' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – ikona</label>' +
                    '<input type="text" name="submenu_icon[' + parent + '][]" placeholder="fa-solid fa-angle-right">' +
                '</div>' +
                '<button type="button" class="remove-submenu-btn"><i class="fa-solid fa-trash"></i></button>';

            list.appendChild(row);
            const firstInput = row.querySelector('input');
            if (firstInput) firstInput.focus();
            return;
        }

        const removeBtn = event.target.closest('.remove-submenu-btn');
        if (removeBtn) {
            event.preventDefault();
            const row = removeBtn.closest('.submenu-row');
            if (row) row.remove();
        }
    });
});
</script>


<script>
(function(){
  const map = {
    size: document.getElementById('header_title_font_size'),
    weight: document.getElementById('header_title_font_weight'),
    color: document.getElementById('header_title_color'),
    upper: document.querySelector('input[name="header_title_uppercase"]'),
    italic: document.querySelector('input[name="header_title_italic"]'),
    shadow: document.querySelector('input[name="header_title_shadow"]'),
    bg: document.getElementById('header_title_bg'),
    radius: document.getElementById('header_title_radius'),
    preview: document.getElementById('headerTitlePreview')
  };
  if (!map.preview) return;
  function upd(){
    map.preview.style.fontSize = ((map.size && map.size.value) || 22) + 'px';
    map.preview.style.fontWeight = (map.weight && map.weight.value) || 800;
    map.preview.style.color = (map.color && map.color.value) || '#f8fafc';
    map.preview.style.textTransform = (map.upper && map.upper.checked) ? 'uppercase' : 'none';
    map.preview.style.fontStyle = (map.italic && map.italic.checked) ? 'italic' : 'normal';
    map.preview.style.textShadow = (map.shadow && map.shadow.checked) ? '0 2px 10px rgba(0,0,0,.45)' : 'none';
    map.preview.style.background = (map.bg && map.bg.value) || 'transparent';
    map.preview.style.borderRadius = ((map.radius && map.radius.value) || 0) + 'px';
    map.preview.style.padding = (map.bg && map.bg.value) ? '.18em .42em' : '0';
  }
  Object.values(map).forEach(el => { if (el && el.addEventListener) { el.addEventListener('input', upd); el.addEventListener('change', upd); } });
  upd();
})();
</script>


<script>
document.addEventListener('DOMContentLoaded', function(){
    const list = document.getElementById('slider-images-list');
    const add = document.getElementById('add-slider-image');

    if (!list) return;

    function escAttr(value){
        return String(value || '').replace(/[&<>'"]/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
        });
    }

    function rowHtml(url, title, desc){
        return ''
            + '<div><label>Adres zdjęcia</label><input type="text" name="image_url[]" value="' + escAttr(url || '') + '" placeholder="uploads/zdjecie.jpg"></div>'
            + '<div><label>Tytuł</label><input type="text" name="image_title[]" value="' + escAttr(title || '') + '" placeholder="Opcjonalnie"></div>'
            + '<div><label>Opis</label><input type="text" name="image_desc[]" value="' + escAttr(desc || '') + '" placeholder="Opcjonalnie"></div>'
            + '<button type="button" class="remove-slider-image" style="background:#ef4444;color:white;border:0;border-radius:8px;padding:.75rem;cursor:pointer;"><i class="fa-solid fa-trash"></i></button>';
    }

    function createRow(url, title, desc){
        const row = document.createElement('div');
        row.className = 'slider-image-row';
        row.style.cssText = 'display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:.7rem;align-items:end;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1rem;';
        row.innerHTML = rowHtml(url || '', title || '', desc || '');
        return row;
    }

    function isEmptyRow(row){
        const input = row ? row.querySelector('input[name="image_url[]"]') : null;
        return input && input.value.trim() === '';
    }

    function addSliderImage(url, title, desc){
        const rows = list.querySelectorAll('.slider-image-row');
        if (url && rows.length === 1 && isEmptyRow(rows[0])) {
            rows[0].querySelector('input[name="image_url[]"]').value = url;
            rows[0].querySelector('input[name="image_title[]"]').value = title || '';
            rows[0].querySelector('input[name="image_desc[]"]').value = desc || '';
            return;
        }
        list.appendChild(createRow(url || '', title || '', desc || ''));
    }

    if (add) {
        add.addEventListener('click', function(e){
            e.preventDefault();
            addSliderImage('', '', '');
        });
    }

    document.addEventListener('click', function(e){
        const removeBtn = e.target.closest('.remove-slider-image');
        if (removeBtn && list.contains(removeBtn)) {
            e.preventDefault();
            const rows = list.querySelectorAll('.slider-image-row');
            const row = removeBtn.closest('.slider-image-row');
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach(function(i){ i.value = ''; });
            } else if (row) {
                row.remove();
            }
            return;
        }

        const galleryBtn = e.target.closest('.slider-gallery-add');
        if (galleryBtn) {
            e.preventDefault();
            addSliderImage(galleryBtn.dataset.url || '', '', '');
            galleryBtn.style.outline = '2px solid #22c55e';
            galleryBtn.style.outlineOffset = '2px';
            setTimeout(function(){ galleryBtn.style.outline = ''; galleryBtn.style.outlineOffset = ''; }, 500);
        }
    });
});
</script>


<script>
(function(){
    const btn = document.getElementById('spidercmsMobileMenuBtn');
    const backdrop = document.getElementById('spidercmsSidebarBackdrop');
    const body = document.body;
    function closeMenu(){
        body.classList.remove('spidercms-sidebar-open');
        if (btn) btn.setAttribute('aria-expanded','false');
    }
    function toggleMenu(){
        const open = body.classList.toggle('spidercms-sidebar-open');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (btn) btn.addEventListener('click', toggleMenu);
    if (backdrop) backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeMenu(); });
    document.querySelectorAll('#sidebar a').forEach(function(a){
        a.addEventListener('click', function(){ if (window.innerWidth <= 1024) closeMenu(); });
    });
})();
</script>
</body>
</html>
PHP;
                $template = str_replace('__TITLE__', addslashes($title), $template);
                $template = str_replace('__CONTENT__', $content, $template);
                $template = str_replace('__ROOT_DEPTH__', (string)$create_pages_depth, $template);
                file_put_contents($file, $template);
                $toast = ['type'=>'success', 'msg'=>"Utworzono stronę: " . $create_pages_url . $slug . '.php'];
            }
        } else {
            $toast = ['type'=>'error', 'msg'=>'Slug i tytuł są wymagane'];
        }
    }
    // AKCJA: USTAWIENIE STRONY GŁÓWNEJ
    if ($action === 'set_homepage') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '', trim($_POST['slug'] ?? $_POST['homepage_slug'] ?? ''));
        $file = ACTIVE_PAGES_DIR . '/' . $slug . '.php';
        if ($slug === '' || !file_exists($file)) {
            $toast = ['type'=>'error', 'msg'=>'Nie można ustawić strony głównej – wybrana strona nie istnieje'];
        } else {
            file_put_contents(__DIR__ . '/.homepage', $slug);
            if (write_homepage_redirect($slug)) {
                $toast = ['type'=>'success', 'msg'=>'Ustawiono stronę główną: ' . $slug . '.php'];
            } else {
                $toast = ['type'=>'error', 'msg'=>'Zapisano ustawienie, ale nie udało się utworzyć przekierowania index.php'];
            }
        }
        header('Location: admin.php?tab=ustawienia');
        exit;
    }

    // AKCJA: EDYCJA STRONY
    if ($action === 'edit') {
        $old_slug = spidercms_clean_slug($_POST['old_slug'] ?? ($_POST['slug'] ?? ''));
        $new_slug = spidercms_clean_slug($_POST['new_slug'] ?? ($_POST['slug'] ?? ''));
        $new_title = trim((string)($_POST['title'] ?? ''));
        $new_content = $_POST['content'] ?? '';

        if ($new_slug === '') {
            $toast = ['type'=>'error', 'msg'=>'Slug strony nie może być pusty.'];
        } else {
            $old_file = ACTIVE_PAGES_DIR . '/' . $old_slug . '.php';
            $new_file = ACTIVE_PAGES_DIR . '/' . $new_slug . '.php';

            if (!file_exists($old_file)) {
                $toast = ['type'=>'error', 'msg'=>'Strona źródłowa nie istnieje.'];
            } elseif ($new_slug !== $old_slug && file_exists($new_file)) {
                $toast = ['type'=>'error', 'msg'=>'Nie można zmienić sluga. Plik ' . $new_slug . '.php już istnieje.'];
            } else {
                $old = file_get_contents($old_file);
                $updated = spidercms_replace_page_content_source($old, $new_content);

                if ($updated !== false) {
                    if ($new_title !== '') {
                        $updated = spidercms_page_set_title_in_source($updated, $new_title);
                    }

                    if (file_put_contents($new_file, $updated, LOCK_EX) !== false) {
                        if ($new_slug !== $old_slug && file_exists($old_file)) {
                            @unlink($old_file);
                        }
                        $toast = ['type'=>'success', 'msg'=>'Zapisano zmiany strony.'];
                        if ($new_slug !== $old_slug) {
                            header('Location: admin.php?tab=strony&edit=' . urlencode($new_slug) . '&renamed=1');
                            exit;
                        }
                    } else {
                        $toast = ['type'=>'error', 'msg'=>'Nie można zapisać pliku strony. Sprawdź uprawnienia pliku/katalogu.'];
                    }
                } else {
                    $toast = ['type'=>'error', 'msg'=>'Nie znaleziono edytowalnego bloku treści ($content lub <main>).'];
                }
            }
        }
    }
    // AKCJA: USUWANIE STRONY
    if ($action === 'delete') {
        $slug = trim($_POST['slug'] ?? '');
        global $homepage_slug;
        if ($slug === $homepage_slug) {
            $toast = ['type'=>'error', 'msg'=>'Nie można usunąć aktywnej strony głównej. Najpierw ustaw inną stronę jako główną.'];
        } elseif ($slug === 'index') {
            $toast = ['type'=>'error', 'msg'=>'Nie można usunąć podstawowej strony index'];
        } else {
            $file = ACTIVE_PAGES_DIR . '/' . $slug . '.php';
            if (file_exists($file) && unlink($file)) {
                $toast = ['type'=>'success', 'msg'=>'Strona usunięta'];
            } else {
                $toast = ['type'=>'error', 'msg'=>'Błąd usuwania'];
            }
        }
    }

    // AKCJA: DUPLIKOWANIE STRONY
    if ($action === 'duplicate') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', trim((string)($_POST['slug'] ?? '')));
        $source_file = ACTIVE_PAGES_DIR . '/' . $slug . '.php';

        if ($slug === '' || !file_exists($source_file)) {
            $toast = ['type'=>'error', 'msg'=>'Nie można zduplikować strony, ponieważ plik źródłowy nie istnieje.'];
        } else {
            $base_slug = $slug . '-kopia';
            $new_slug = $base_slug;
            $counter = 2;

            while (file_exists(ACTIVE_PAGES_DIR . '/' . $new_slug . '.php')) {
                $new_slug = $base_slug . '-' . $counter;
                $counter++;
            }

            $new_file = ACTIVE_PAGES_DIR . '/' . $new_slug . '.php';
            $content = file_get_contents($source_file);

            // Zmieniamy tytuł strony w kopii, jeżeli w pliku istnieje standardowa zmienna $title.
            $content = preg_replace_callback(
                '/\\$title\\s*=\\s*([\'\"])(.*?)\\1\\s*;/s',
                function ($m) {
                    $quote = $m[1];
                    $title = $m[2];
                    if (stripos($title, 'kopia') === false) {
                        $title .= ' – kopia';
                    }
                    $title = addcslashes($title, "\\" . $quote);
                    return '$title = ' . $quote . $title . $quote . ';';
                },
                $content,
                1
            );

            if (file_put_contents($new_file, $content) !== false) {
                header('Location: admin.php?tab=strony&edit=' . urlencode($new_slug) . '&duplicated=1');
                exit;
            } else {
                $toast = ['type'=>'error', 'msg'=>'Nie udało się zapisać kopii strony.'];
            }
        }
    }
    // AKCJA: ZAPIS MENU
    if ($action === 'save_menu') {
        $enabled = !empty($_POST['menu_enabled']);
        if ($enabled) {
            file_put_contents(__DIR__ . '/.menu_enabled', '1');
        } else {
            @unlink(__DIR__ . '/.menu_enabled');
        }

        $items = [];
        $submenu_labels = $_POST['submenu_label'] ?? [];
        $submenu_urls   = $_POST['submenu_url'] ?? [];
        $submenu_icons  = $_POST['submenu_icon'] ?? [];

        foreach (($_POST['menu_label'] ?? []) as $i => $label) {
            $label = trim((string)$label);
            $url   = trim((string)($_POST['menu_url'][$i] ?? ''));
            $icon  = trim((string)($_POST['menu_icon'][$i] ?? ''));

            if ($label === '' && $url === '') {
                continue;
            }

            $children = [];
            $child_labels = is_array($submenu_labels[$i] ?? null) ? $submenu_labels[$i] : [];
            $child_urls   = is_array($submenu_urls[$i] ?? null) ? $submenu_urls[$i] : [];
            $child_icons  = is_array($submenu_icons[$i] ?? null) ? $submenu_icons[$i] : [];

            foreach ($child_labels as $j => $child_label) {
                $child_label = trim((string)$child_label);
                $child_url   = trim((string)($child_urls[$j] ?? ''));
                $child_icon  = trim((string)($child_icons[$j] ?? ''));

                if ($child_label === '' && $child_url === '') {
                    continue;
                }

                $children[] = [
                    'label' => $child_label,
                    'url'   => $child_url,
                    'icon'  => $child_icon,
                ];
            }

            $item = [
                'label' => $label,
                'url'   => $url,
                'icon'  => $icon,
            ];

            if (!empty($children)) {
                $item['children'] = $children;
            }

            $items[] = $item;
        }

        file_put_contents(__DIR__ . '/.menu.json', json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        spidercms_write_header_with_submenu_support();
        $toast = ['type'=>'success', 'msg'=>'Konfiguracja menu zapisana'];
        header('Location: admin.php?tab=menu');
        exit;
    }
    // AKCJA: ZAPIS STOPKI + GENEROWANIE GLOBALNEGO PLIKU FOOTER.PHP + AKTUALIZACJA STARYCH STRON
    if ($action === 'save_footer') {
        $footer_enabled = !empty($_POST['footer_enabled']);
        if ($footer_enabled) {
            file_put_contents(__DIR__ . '/.footer_enabled', '1');
        } else {
            @unlink(__DIR__ . '/.footer_enabled');
        }

        $footer_columns = [];
        $footer_titles = $_POST['footer_col_title'] ?? [];
        $footer_contents = $_POST['footer_col_content'] ?? [];
        if (is_array($footer_titles) && is_array($footer_contents)) {
            foreach ($footer_titles as $i => $title) {
                $title = trim((string)$title);
                $content = (string)($footer_contents[$i] ?? '');
                if ($title !== '' || trim(strip_tags($content)) !== '') {
                    $footer_columns[] = [
                        'title' => mb_substr($title, 0, 80),
                        'content' => $content,
                    ];
                }
                if (count($footer_columns) >= 12) {
                    break;
                }
            }
        }

        // Kompatybilność: jeżeli przeglądarka wysłała stary formularz, zachowaj stare pola.
        if (!$footer_columns) {
            if (!empty($_POST['footer_col1_title']) || !empty($_POST['footer_col1_content'])) {
                $footer_columns[] = ['title' => trim($_POST['footer_col1_title'] ?? ''), 'content' => $_POST['footer_col1_content'] ?? ''];
            }
            if (!empty($_POST['footer_col2_title']) || !empty($_POST['footer_col2_content'])) {
                $footer_columns[] = ['title' => trim($_POST['footer_col2_title'] ?? ''), 'content' => $_POST['footer_col2_content'] ?? ''];
            }
        }

        $footer_save = [
            'copyright' => trim($_POST['footer_copyright'] ?? ''),
            'about_text' => trim($_POST['footer_about_text'] ?? ''),
            'columns' => $footer_columns,
            // Starsze klucze zostają zapisane dla kompatybilności ze starym footer.php
            'col1_title' => $footer_columns[0]['title'] ?? '',
            'col1_content' => $footer_columns[0]['content'] ?? '',
            'col2_title' => $footer_columns[1]['title'] ?? '',
            'col2_content' => $footer_columns[1]['content'] ?? '',
        ];
        file_put_contents($footer_file, json_encode($footer_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $global_footer_content = <<<'PHP'
<?php
if (!file_exists(__DIR__ . '/.footer_enabled')) {
    return;
}
$f_data = file_exists(__DIR__ . '/.footer.json') ? json_decode(file_get_contents(__DIR__ . '/.footer.json'), true) : [];
if (!is_array($f_data)) {
    $f_data = [];
}
$footer_columns = $f_data['columns'] ?? [];
if (!is_array($footer_columns) || !$footer_columns) {
    $footer_columns = [];
    if (!empty($f_data['col1_title']) || !empty($f_data['col1_content'])) {
        $footer_columns[] = ['title' => $f_data['col1_title'] ?? '', 'content' => $f_data['col1_content'] ?? ''];
    }
    if (!empty($f_data['col2_title']) || !empty($f_data['col2_content'])) {
        $footer_columns[] = ['title' => $f_data['col2_title'] ?? '', 'content' => $f_data['col2_content'] ?? ''];
    }
}
?>
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-col">
      <h4>O nas</h4>
      <p><?php echo htmlspecialchars($f_data['about_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php foreach ($footer_columns as $column): ?>
      <?php
        $col_title = trim((string)($column['title'] ?? ''));
        $col_content = (string)($column['content'] ?? '');
        if ($col_title === '' && trim(strip_tags($col_content)) === '') { continue; }
      ?>
      <div class="footer-col">
        <?php if ($col_title !== ''): ?>
          <h4><?php echo htmlspecialchars($col_title, ENT_QUOTES, 'UTF-8'); ?></h4>
        <?php endif; ?>
        <p><?php echo $col_content; ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="footer-bottom">
    <?php echo htmlspecialchars($f_data['copyright'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
  </div>
</footer>
PHP;
        file_put_contents(__DIR__ . '/footer.php', $global_footer_content);
        $pages_files = glob(ACTIVE_PAGES_DIR . '/*.php');
        foreach ($pages_files as $p_file) {
            $p_content = file_get_contents($p_file);
            if (strpos($p_content, 'require_once __DIR__ . \'/../footer.php\';') === false) {
                $pattern = '/<\?php\s*\/\/ NAPRAWIONO ŚCIEŻKĘ.*?\?>\s*<footer class="site-footer">.*?<\/footer>/s';
                if (preg_match($pattern, $p_content)) {
                    $p_content = preg_replace($pattern, "<?php require_once dirname(__DIR__, " . (int)ACTIVE_PAGES_DEPTH . ") . '/footer.php'; ?>", $p_content);
                    file_put_contents($p_file, $p_content);
                }
            }
        }
        $toast = ['type' => 'success', 'msg' => 'Zapisano stopkę globalną i pomyślnie zsynchronizowano wszystkie podstrony!'];
        header('Location: admin.php?tab=stopka');
        exit;
    }
    // AKCJA: ZASTOSOWANIE GOTOWEGO PRESETU WYGLĄDU STRONY
    if ($action === 'apply_site_preset') {
        $preset_key = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['site_preset'] ?? ''));

        $presets = [
            'minimal' => [
                'name' => 'Minimal jasny',
                'theme' => [
                    'primary' => '#2563eb',
                    'primary-dark' => '#1d4ed8',
                    'accent' => '#0ea5e9',
                    'page-bg' => '#ffffff',
                    'page-text' => '#111827',
                    'header-bg' => '#ffffff',
                    'header-text' => '#111827',
                    'footer-bg' => '#111827',
                    'footer-text' => '#f9fafb',
                    'footer-muted' => '#9ca3af',
                    'link-color' => '#2563eb',
                    'button-bg' => '#2563eb',
                    'button-text' => '#ffffff',
                    'font-family' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                    'header-height' => '74',
                    'logo-height' => '58',
                    'content-width' => '1100',
                    'border-radius' => '10',
                    'shadow-enabled' => '1',
                    'menu-position' => 'right',
                ],
                'settings' => [
                    'header_title_font_size' => '22',
                    'header_title_font_weight' => '800',
                    'header_title_color' => '#111827',
                    'header_title_gap' => '10',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '0',
                    'header_title_bg' => '',
                    'header_title_radius' => '0',
                ],
            ],
            'corporate' => [
                'name' => 'Corporate',
                'theme' => [
                    'primary' => '#1e40af',
                    'primary-dark' => '#172554',
                    'accent' => '#f59e0b',
                    'page-bg' => '#f8fafc',
                    'page-text' => '#0f172a',
                    'header-bg' => '#ffffff',
                    'header-text' => '#1f2937',
                    'footer-bg' => '#0f172a',
                    'footer-text' => '#f8fafc',
                    'footer-muted' => '#cbd5e1',
                    'link-color' => '#1d4ed8',
                    'button-bg' => '#1e40af',
                    'button-text' => '#ffffff',
                    'font-family' => 'Arial, Helvetica, sans-serif',
                    'header-height' => '82',
                    'logo-height' => '64',
                    'content-width' => '1240',
                    'border-radius' => '8',
                    'shadow-enabled' => '1',
                    'menu-position' => 'right',
                ],
                'settings' => [
                    'header_title_font_size' => '24',
                    'header_title_font_weight' => '800',
                    'header_title_color' => '#0f172a',
                    'header_title_gap' => '12',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '0',
                    'header_title_bg' => '',
                    'header_title_radius' => '0',
                ],
            ],
            'dark' => [
                'name' => 'Dark premium',
                'theme' => [
                    'primary' => '#8b5cf6',
                    'primary-dark' => '#4c1d95',
                    'accent' => '#22d3ee',
                    'page-bg' => '#020617',
                    'page-text' => '#e5e7eb',
                    'header-bg' => '#0f172a',
                    'header-text' => '#f8fafc',
                    'footer-bg' => '#020617',
                    'footer-text' => '#f8fafc',
                    'footer-muted' => '#94a3b8',
                    'link-color' => '#a78bfa',
                    'button-bg' => '#8b5cf6',
                    'button-text' => '#ffffff',
                    'font-family' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                    'header-height' => '78',
                    'logo-height' => '58',
                    'content-width' => '1180',
                    'border-radius' => '16',
                    'shadow-enabled' => '1',
                    'menu-position' => 'right',
                ],
                'settings' => [
                    'header_title_font_size' => '23',
                    'header_title_font_weight' => '900',
                    'header_title_color' => '#f8fafc',
                    'header_title_gap' => '12',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '1',
                    'header_title_bg' => 'rgba(255,255,255,.06)',
                    'header_title_radius' => '10',
                ],
            ],
            'glass' => [
                'name' => 'Glass / nowoczesny',
                'theme' => [
                    'primary' => '#7c3aed',
                    'primary-dark' => '#312e81',
                    'accent' => '#06b6d4',
                    'page-bg' => '#eef2ff',
                    'page-text' => '#111827',
                    'header-bg' => '#ffffff',
                    'header-text' => '#111827',
                    'footer-bg' => '#111827',
                    'footer-text' => '#f8fafc',
                    'footer-muted' => '#cbd5e1',
                    'link-color' => '#7c3aed',
                    'button-bg' => '#7c3aed',
                    'button-text' => '#ffffff',
                    'font-family' => 'Inter, system-ui, sans-serif',
                    'header-height' => '84',
                    'logo-height' => '64',
                    'content-width' => '1200',
                    'border-radius' => '18',
                    'shadow-enabled' => '1',
                    'menu-position' => 'center',
                ],
                'settings' => [
                    'header_title_font_size' => '24',
                    'header_title_font_weight' => '900',
                    'header_title_color' => '#312e81',
                    'header_title_gap' => '12',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '0',
                    'header_title_bg' => 'rgba(124,58,237,.08)',
                    'header_title_radius' => '14',
                ],
            ],
            'landing' => [
                'name' => 'Landing page',
                'theme' => [
                    'primary' => '#f97316',
                    'primary-dark' => '#9a3412',
                    'accent' => '#14b8a6',
                    'page-bg' => '#fff7ed',
                    'page-text' => '#1f2937',
                    'header-bg' => '#ffffff',
                    'header-text' => '#1f2937',
                    'footer-bg' => '#1f2937',
                    'footer-text' => '#fff7ed',
                    'footer-muted' => '#fed7aa',
                    'link-color' => '#ea580c',
                    'button-bg' => '#f97316',
                    'button-text' => '#ffffff',
                    'font-family' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                    'header-height' => '76',
                    'logo-height' => '58',
                    'content-width' => '1040',
                    'border-radius' => '22',
                    'shadow-enabled' => '1',
                    'menu-position' => 'right',
                ],
                'settings' => [
                    'header_title_font_size' => '22',
                    'header_title_font_weight' => '900',
                    'header_title_color' => '#9a3412',
                    'header_title_gap' => '12',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '0',
                    'header_title_bg' => '',
                    'header_title_radius' => '0',
                ],
            ],
            'neon' => [
                'name' => 'Neon / SpiderCMS',
                'theme' => [
                    'primary' => '#a855f7',
                    'primary-dark' => '#581c87',
                    'accent' => '#2563eb',
                    'page-bg' => '#0f172a',
                    'page-text' => '#f8fafc',
                    'header-bg' => '#111827',
                    'header-text' => '#f8fafc',
                    'footer-bg' => '#020617',
                    'footer-text' => '#f8fafc',
                    'footer-muted' => '#94a3b8',
                    'link-color' => '#c084fc',
                    'button-bg' => '#a855f7',
                    'button-text' => '#ffffff',
                    'font-family' => 'system-ui, sans-serif',
                    'header-height' => '82',
                    'logo-height' => '68',
                    'content-width' => '1240',
                    'border-radius' => '14',
                    'shadow-enabled' => '1',
                    'menu-position' => 'right',
                ],
                'settings' => [
                    'header_title_font_size' => '24',
                    'header_title_font_weight' => '900',
                    'header_title_color' => '#f8fafc',
                    'header_title_gap' => '12',
                    'header_title_uppercase' => '0',
                    'header_title_italic' => '0',
                    'header_title_shadow' => '1',
                    'header_title_bg' => 'rgba(168,85,247,.12)',
                    'header_title_radius' => '12',
                ],
            ],
        ];

        if (!isset($presets[$preset_key])) {
            $toast = ['type' => 'error', 'msg' => 'Nie wybrano poprawnego presetu strony.'];
        } else {
            $chosen = $presets[$preset_key];
            $theme_data = array_merge($theme_defaults, $theme, $chosen['theme']);
            $theme = $theme_data;
            file_put_contents($theme_file, json_encode($theme_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $settings = array_merge($settings, $chosen['settings']);
            if (!isset($settings['header_enabled'])) $settings['header_enabled'] = '1';
            if (!isset($settings['show_site_name_in_header'])) $settings['show_site_name_in_header'] = '0';
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            spidercms_write_header_with_submenu_support();
            spidercms_sync_header_bootstrap_in_pages();
            update_all_pages_colors();
            spidercms_force_theme_refresh();
            // Wyłączone: nie modyfikujemy stron przy samym wejściu do panelu.
// spidercms_sync_content_width_in_pages();
            // Wyłączone: ta funkcja modyfikowała publiczne strony przy każdym odświeżeniu panelu.
// spidercms_fix_opaque_header_in_pages();

            $toast = ['type' => 'success', 'msg' => 'Zastosowano preset: ' . $chosen['name'] . '. Kolory i ustawienia poniżej zostały odświeżone.'];
            $tab = 'ustawienia';
            $_GET['settings'] = 'appearance';
        }
    }

    // AKCJA: ZAPIS USTAWIEŃ I MOTYWU
    if ($action === 'save_settings') {
        $new_site_name = trim($_POST['site_name'] ?? '');
        $new_primary = trim($_POST['primary'] ?? '');
        $new_primary_d = trim($_POST['primary_dark'] ?? '');
        $new_accent = trim($_POST['accent'] ?? '');
        $new_page_bg = trim($_POST['page_bg'] ?? '');
        $new_page_text = trim($_POST['page_text'] ?? '');
        $new_header_bg = trim($_POST['header_bg'] ?? '');
        $new_header_text = trim($_POST['header_text'] ?? '');
        $new_footer_bg = trim($_POST['footer_bg'] ?? '');
        $new_footer_text = trim($_POST['footer_text'] ?? '');
        $new_footer_muted = trim($_POST['footer_muted'] ?? '');
        $new_link_color = trim($_POST['link_color'] ?? '');
        $new_button_bg = trim($_POST['button_bg'] ?? '');
        $new_button_text = trim($_POST['button_text'] ?? '');
        $new_font_family = trim($_POST['font_family'] ?? '');
        $new_header_height = trim($_POST['header_height'] ?? '');
        $new_logo_height = trim($_POST['logo_height'] ?? '');
        $new_content_width = trim($_POST['content_width'] ?? '');
        $new_border_radius = trim($_POST['border_radius'] ?? '');
        $new_shadow_enabled = !empty($_POST['shadow_enabled']) ? '1' : '0';
        $new_menu_position = $_POST['menu_position'] ?? theme_value('menu-position', 'right');
        if (!in_array($new_menu_position, ['left','center','right'], true)) {
            $new_menu_position = 'right';
        }
        $logo_path = $logo_url;
        $logo_upload = $_FILES['logo'] ?? null;
        if ($logo_upload && $logo_upload['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($logo_upload['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','svg','gif'])) {
                $safe_name = 'logo-' . time() . '.' . $ext;
                $target_file = $upload_dir . $safe_name;
                if (move_uploaded_file($logo_upload['tmp_name'], $target_file)) {
                    $logo_path = ((defined('BASE_URL') && trim((string)BASE_URL) !== '') ? rtrim(BASE_URL, '/') : '') . '/uploads/' . $safe_name;
                } else {
                    $toast = ['type' => 'error', 'msg' => 'Błąd przenoszenia pliku logo'];
                }
            } else {
                $toast = ['type' => 'error', 'msg' => 'Dozwolone formaty logo: png, jpg, jpeg, svg, gif'];
            }
        } elseif (!empty($_POST['logo_url'])) {
            $logo_path = trim($_POST['logo_url']);
        }
        if ($new_site_name === '') {
            $toast = ['type'=>'error', 'msg'=>'Nazwa witryny nie może być pusta'];
        } else {
            $config_path = __DIR__ . '/config.php';
            $config_content = file_get_contents($config_path);
            $config_content = preg_replace(
                "/define\s*\(\s*'SITE_NAME'\s*,\s*'.*?'\s*\)\s*;/",
                "define('SITE_NAME', '$new_site_name');",
                $config_content
            );
            file_put_contents($config_path, $config_content);
            $theme_data = [
                'primary' => $new_primary ?: theme_value('primary', '#a855f7'),
                'primary-dark' => $new_primary_d ?: theme_value('primary-dark', '#7e22ce'),
                'accent' => $new_accent ?: theme_value('accent', '#2563eb'),
                'page-bg' => $new_page_bg ?: theme_value('page-bg', '#f9fafb'),
                'page-text' => $new_page_text ?: theme_value('page-text', '#111827'),
                'header-bg' => $new_header_bg ?: theme_value('header-bg', '#ffffff'),
                'header-text' => $new_header_text ?: theme_value('header-text', '#374151'),
                'footer-bg' => $new_footer_bg ?: theme_value('footer-bg', '#1f2937'),
                'footer-text' => $new_footer_text ?: theme_value('footer-text', '#f3f4f6'),
                'footer-muted' => $new_footer_muted ?: theme_value('footer-muted', '#9ca3af'),
                'link-color' => $new_link_color ?: theme_value('link-color', '#a855f7'),
                'button-bg' => $new_button_bg ?: theme_value('button-bg', '#a855f7'),
                'button-text' => $new_button_text ?: theme_value('button-text', '#ffffff'),
                'font-family' => $new_font_family ?: theme_value('font-family', 'system-ui, sans-serif'),
                'header-height' => preg_replace('/[^0-9.]/', '', $new_header_height ?: theme_value('header-height', '74')),
                'logo-height' => preg_replace('/[^0-9.]/', '', $new_logo_height ?: theme_value('logo-height', '100')),
                'content-width' => preg_replace('/[^0-9.]/', '', $new_content_width ?: theme_value('content-width', '1240')),
                'border-radius' => preg_replace('/[^0-9.]/', '', $new_border_radius ?: theme_value('border-radius', '10')),
                'shadow-enabled' => $new_shadow_enabled,
                'menu-position' => $new_menu_position,
            ];
            file_put_contents(__DIR__ . '/.theme.json', json_encode($theme_data, JSON_PRETTY_PRINT));
            $requested_page_folder = spidercms_sanitize_page_folder($_POST['page_folder'] ?? ($GLOBALS['active_page_folder'] ?? 'pages'));
            $requested_page_dir = spidercms_page_folder_dir($requested_page_folder);
            if (!is_dir($requested_page_dir)) { @mkdir($requested_page_dir, 0755, true); }
            file_put_contents($GLOBALS['page_folder_file'], json_encode(['folder' => $requested_page_folder], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $settings['logo'] = $logo_path;
            $settings['header_enabled'] = !empty($_POST['header_enabled']) ? '1' : '0';
            $settings['show_site_name_in_header'] = !empty($_POST['show_site_name_in_header']) ? '1' : '0';
            $settings['header_title_font_size'] = preg_replace('/[^0-9.]/', '', $_POST['header_title_font_size'] ?? '22') ?: '22';
            $settings['header_title_font_weight'] = preg_replace('/[^0-9]/', '', $_POST['header_title_font_weight'] ?? '800') ?: '800';
            $settings['header_title_color'] = trim((string)($_POST['header_title_color'] ?? ''));
            $settings['header_title_gap'] = preg_replace('/[^0-9.]/', '', $_POST['header_title_gap'] ?? '10') ?: '10';
            $settings['header_title_uppercase'] = !empty($_POST['header_title_uppercase']) ? '1' : '0';
            $settings['header_title_italic'] = !empty($_POST['header_title_italic']) ? '1' : '0';
            $settings['header_title_shadow'] = !empty($_POST['header_title_shadow']) ? '1' : '0';
            $settings['header_title_bg'] = trim((string)($_POST['header_title_bg'] ?? ''));
            $settings['header_title_radius'] = preg_replace('/[^0-9.]/', '', $_POST['header_title_radius'] ?? '0') ?: '0';
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            spidercms_write_header_with_submenu_support();
            spidercms_sync_header_bootstrap_in_pages();
            $updated_pages = update_all_pages_colors();
            spidercms_force_theme_refresh();
            // Wyłączone: nie modyfikujemy stron przy samym wejściu do panelu.
// spidercms_sync_content_width_in_pages();
                    spidercms_write_site_menu_behavior_file();
        spidercms_ensure_site_menu_behavior_in_header();
header('Location: admin.php?tab=ustawienia');
            exit;
        }
    }

    // === NOWA AKCJA: ZMIANA HASŁA ===
    if ($action === 'change_password') {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $toast = ['type' => 'error', 'msg' => 'Wszystkie pola są wymagane'];
        } elseif ($new_password !== $confirm_password) {
            $toast = ['type' => 'error', 'msg' => 'Nowe hasło i potwierdzenie nie są identyczne'];
        } elseif (strlen($new_password) < 6) {
            $toast = ['type' => 'error', 'msg' => 'Nowe hasło musi mieć minimum 6 znaków'];
        } elseif (!password_verify($old_password, $ADMIN_HASH)) {
            $toast = ['type' => 'error', 'msg' => 'Stare hasło jest nieprawidłowe'];
        } else {
            $new_hash = password_hash($new_password, PASSWORD_ARGON2ID);
            if (file_put_contents($hash_file, $new_hash) !== false) {
                chmod($hash_file, 0600);
                $ADMIN_HASH = $new_hash; // aktualizacja w bieżącej sesji
                $toast = ['type' => 'success', 'msg' => 'Hasło zostało pomyślnie zmienione'];
            } else {
                $toast = ['type' => 'error', 'msg' => 'Błąd zapisu nowego hasła'];
            }
        }
    }



    // AKCJA: ZAPIS SOCIAL MEDIA HUB
    if ($action === 'save_social_settings') {
        global $social_file, $social_defaults, $social_settings;
        $social_save = [];
        foreach ($social_defaults as $key => $default) {
            if (in_array($key, ['enabled','show_header','show_footer','show_floating','show_contact_widget','og_enabled'], true)) {
                $social_save[$key] = !empty($_POST['social_' . $key]) ? '1' : '0';
            } elseif ($key === 'floating_side') {
                $side = ($_POST['social_floating_side'] ?? 'right') === 'left' ? 'left' : 'right';
                $social_save[$key] = $side;
            } else {
                $social_save[$key] = social_clean_value($_POST['social_' . $key] ?? '', 600);
            }
        }
        file_put_contents($social_file, json_encode($social_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $social_settings = array_merge($social_defaults, $social_save);
        social_write_widget_file();
        social_write_meta_file();
        $changed = social_sync_in_pages();
        $toast = ['type' => 'success', 'msg' => 'Zapisano Social Media Hub. Zsynchronizowano podstrony: ' . $changed];
        header('Location: admin.php?tab=ustawienia');
        exit;
    }

    // AKCJA: EKSPORT CAŁOŚCI ZIP
    if ($action === 'export_all') {
        $zip_name = 'spider-cms-full-' . date('Y-m-d-H-i-s') . '.zip';
        $zip_file = sys_get_temp_dir() . '/' . $zip_name;
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach (glob(__DIR__ . '/*') as $file) {
                if (is_file($file) && basename($file) !== 'admin.php') {
                    $zip->addFile($file, basename($file));
                }
            }
            foreach (glob(ACTIVE_PAGES_DIR . '/*.php') as $file) {
                $zip->addFile($file, 'pages/' . basename($file));
            }
            $uploads_dir = __DIR__ . '/uploads/';
            if (is_dir($uploads_dir)) {
                foreach (glob($uploads_dir . '*') as $file) {
                    if (is_file($file)) $zip->addFile($file, 'uploads/' . basename($file));
                }
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($zip_file));
            readfile($zip_file);
            unlink($zip_file);
            exit;
        }
    }
    // ====================== MEDIA LIBRARY ======================
    if ($action === 'upload_media') {
        $uploaded = 0;
        $errors = [];
        $allowed_ext = ['jpg','jpeg','png','gif','webp','svg','pdf','doc','docx','mp4','webm'];
        $allowed_mime = [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
            'pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'mp4'=>'video/mp4','webm'=>'video/webm'
        ];
        $max_size = 8 * 1024 * 1024;
        if (isset($_FILES['media_files']['tmp_name'])) {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            foreach ($_FILES['media_files']['tmp_name'] as $i => $tmp) {
                if ($_FILES['media_files']['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = 'Błąd uploadu pliku.'; continue; }
                if (!is_uploaded_file($tmp)) { $errors[] = 'Nieprawidłowy upload.'; continue; }
                if ((int)$_FILES['media_files']['size'][$i] > $max_size) { $errors[] = 'Plik jest za duży: max 8 MB.'; continue; }
                $original_name = $_FILES['media_files']['name'][$i];
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) { $errors[] = 'Niedozwolony typ pliku: .' . $ext; continue; }
                $mime = $finfo ? finfo_file($finfo, $tmp) : ($_FILES['media_files']['type'][$i] ?? '');
                if ($ext !== 'svg' && isset($allowed_mime[$ext]) && $mime !== $allowed_mime[$ext]) { $errors[] = 'Nieprawidłowy MIME dla .' . $ext; continue; }
                if ($ext === 'svg') {
                    $svg = file_get_contents($tmp, false, null, 0, 200000);
                    if (preg_match('/<\s*script|on\w+\s*=|javascript:/i', (string)$svg)) { $errors[] = 'SVG zawiera potencjalnie niebezpieczny kod.'; continue; }
                }
                $safe_name = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($tmp, $uploads_dir . $safe_name)) {
                    chmod($uploads_dir . $safe_name, 0644);
                    $uploaded++;
                }
            }
            if ($finfo) finfo_close($finfo);
        }
        $msg = $uploaded > 0 ? "$uploaded plik(ów) wgrano pomyślnie" : 'Nie wgrano żadnego pliku';
        if ($errors) $msg .= ' | ' . implode(' ', array_slice($errors, 0, 3));
        $toast = ['type' => $uploaded > 0 ? 'success' : 'error', 'msg' => $msg];
    }

    if ($action === 'delete_media') {
        $filename = basename($_POST['file'] ?? '');
        $filepath = $uploads_dir . $filename;
        if ($filename && file_exists($filepath) && unlink($filepath)) {
            $toast = ['type' => 'success', 'msg' => 'Plik został usunięty'];
        } else {
            $toast = ['type' => 'error', 'msg' => 'Nie udało się usunąć pliku'];
        }
    }

    // ====================== CHAT STRONY ======================
    if ($action === 'save_chat_settings') {
        $chat_settings_save = [
            'enabled' => !empty($_POST['chat_enabled']) ? '1' : '0',
            'title' => chat_clean_text($_POST['chat_title'] ?? 'Masz pytanie?', 100),
            'subtitle' => chat_clean_text($_POST['chat_subtitle'] ?? 'Napisz do nas.', 180),
            'welcome' => chat_clean_text($_POST['chat_welcome'] ?? 'Cześć! W czym możemy pomóc?', 250),
            'button_text' => chat_clean_text($_POST['chat_button_text'] ?? 'Chat', 40),
            'admin_name' => chat_clean_text($_POST['chat_admin_name'] ?? 'Administrator', 80),
            'email_notifications' => !empty($_POST['chat_email_notifications']) ? '1' : '0',
            'admin_email' => filter_var(trim((string)($_POST['chat_admin_email'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['chat_admin_email']) : '',
            'from_email' => filter_var(trim((string)($_POST['chat_from_email'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['chat_from_email']) : '',
            'mail_method' => in_array(($_POST['chat_mail_method'] ?? 'smtp'), ['smtp','mail'], true) ? $_POST['chat_mail_method'] : 'smtp',
            'smtp_host' => chat_clean_text($_POST['chat_smtp_host'] ?? '', 180),
            'smtp_port' => preg_replace('/[^0-9]/', '', (string)($_POST['chat_smtp_port'] ?? '465')) ?: '465',
            'smtp_secure' => in_array(($_POST['chat_smtp_secure'] ?? 'ssl'), ['ssl','tls','none'], true) ? $_POST['chat_smtp_secure'] : 'ssl',
            'smtp_username' => chat_clean_text($_POST['chat_smtp_username'] ?? '', 180),
            'smtp_password' => ($_POST['chat_smtp_password'] ?? '') !== '' ? (string)$_POST['chat_smtp_password'] : (string)($chat_settings['smtp_password'] ?? ''),
        ];
        file_put_contents($chat_settings_file, json_encode($chat_settings_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $chat_settings = array_merge($chat_settings_defaults, $chat_settings_save);
        chat_write_widget_file();
        $changed = chat_sync_widget_in_pages();
        $toast = ['type' => 'success', 'msg' => 'Zapisano ustawienia czatu. Zsynchronizowano podstrony: ' . $changed];
        header('Location: admin.php?tab=chat');
        exit;
    }

    if ($action === 'test_chat_email') {
        $test_settings_save = [
            'enabled' => !empty($_POST['chat_enabled']) ? '1' : '0',
            'title' => chat_clean_text($_POST['chat_title'] ?? 'Masz pytanie?', 100),
            'subtitle' => chat_clean_text($_POST['chat_subtitle'] ?? 'Napisz do nas.', 180),
            'welcome' => chat_clean_text($_POST['chat_welcome'] ?? 'Cześć! W czym możemy pomóc?', 250),
            'button_text' => chat_clean_text($_POST['chat_button_text'] ?? 'Chat', 40),
            'admin_name' => chat_clean_text($_POST['chat_admin_name'] ?? 'Administrator', 80),
            'email_notifications' => !empty($_POST['chat_email_notifications']) ? '1' : '0',
            'admin_email' => filter_var(trim((string)($_POST['chat_admin_email'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['chat_admin_email']) : '',
            'from_email' => filter_var(trim((string)($_POST['chat_from_email'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_POST['chat_from_email']) : '',
            'mail_method' => in_array(($_POST['chat_mail_method'] ?? 'smtp'), ['smtp','mail'], true) ? $_POST['chat_mail_method'] : 'smtp',
            'smtp_host' => chat_clean_text($_POST['chat_smtp_host'] ?? '', 180),
            'smtp_port' => preg_replace('/[^0-9]/', '', (string)($_POST['chat_smtp_port'] ?? '465')) ?: '465',
            'smtp_secure' => in_array(($_POST['chat_smtp_secure'] ?? 'ssl'), ['ssl','tls','none'], true) ? $_POST['chat_smtp_secure'] : 'ssl',
            'smtp_username' => chat_clean_text($_POST['chat_smtp_username'] ?? '', 180),
            'smtp_password' => ($_POST['chat_smtp_password'] ?? '') !== '' ? (string)$_POST['chat_smtp_password'] : (string)($chat_settings['smtp_password'] ?? ''),
        ];
        file_put_contents($chat_settings_file, json_encode($test_settings_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $chat_settings = array_merge($chat_settings_defaults, $test_settings_save);
        $ok = chat_notify_admin_by_email('test_' . date('YmdHis'), 'Test SpiderCMS', '', 'To jest test powiadomienia e-mail z panelu SpiderCMS.', true);
        $toast = $ok
            ? ['type' => 'success', 'msg' => 'Wysłano testowe powiadomienie e-mail przez wybraną metodę. Sprawdź skrzynkę oraz SPAM.']
            : ['type' => 'error', 'msg' => 'Nie udało się wysłać testu. Sprawdź SMTP host, port, szyfrowanie, login, hasło oraz log: .chat/email.log'];
    }

    if ($action === 'chat_reply') {
        $conversation_id = $_POST['conversation_id'] ?? '';
        if (!chat_valid_conversation_id($conversation_id)) { $conversation_id = ''; }
        $reply = chat_clean_text($_POST['reply'] ?? '', 2000);
        $data = chat_load_conversations();
        if ($conversation_id && isset($data[$conversation_id]) && $reply !== '') {
            $data[$conversation_id]['messages'][] = [
                'from' => 'admin',
                'body' => $reply,
                'time' => date('Y-m-d H:i:s'),
            ];
            chat_append_archive($conversation_id, 'admin', $reply, [
                'name' => $data[$conversation_id]['name'] ?? 'Gość strony',
                'email' => $data[$conversation_id]['email'] ?? '',
                'ip_hash' => $data[$conversation_id]['ip_hash'] ?? '',
            ]);
            $data[$conversation_id]['updated_at'] = date('Y-m-d H:i:s');
            $data[$conversation_id]['unread_user'] = (int)($data[$conversation_id]['unread_user'] ?? 0) + 1;
            $data[$conversation_id]['unread_admin'] = 0;
            $data[$conversation_id]['status'] = 'open';
            chat_save_conversations($data);
            $toast = ['type' => 'success', 'msg' => 'Wysłano odpowiedź w czacie'];
        } else {
            $toast = ['type' => 'error', 'msg' => 'Nie udało się wysłać odpowiedzi'];
        }
        header('Location: admin.php?tab=chat&conversation=' . urlencode($conversation_id));
        exit;
    }

    if ($action === 'chat_mark_read') {
        $conversation_id = $_POST['conversation_id'] ?? '';
        if (!chat_valid_conversation_id($conversation_id)) { $conversation_id = ''; }
        $data = chat_load_conversations();
        if ($conversation_id && isset($data[$conversation_id])) {
            $data[$conversation_id]['unread_admin'] = 0;
            chat_save_conversations($data);
        }
        header('Location: admin.php?tab=chat&conversation=' . urlencode($conversation_id));
        exit;
    }

    if ($action === 'chat_archive' || $action === 'chat_delete') {
        $conversation_id = $_POST['conversation_id'] ?? '';
        if (!chat_valid_conversation_id($conversation_id)) { $conversation_id = ''; }
        $data = chat_load_conversations();
        if ($conversation_id && isset($data[$conversation_id])) {
            if ($action === 'chat_delete') {
                unset($data[$conversation_id]);
                $toast = ['type' => 'success', 'msg' => 'Usunięto rozmowę'];
                chat_save_conversations($data);
                header('Location: admin.php?tab=chat');
                exit;
            } else {
                $data[$conversation_id]['status'] = 'archived';
                $data[$conversation_id]['unread_admin'] = 0;
                $toast = ['type' => 'success', 'msg' => 'Zarchiwizowano rozmowę'];
                chat_save_conversations($data);
            }
        }
        header('Location: admin.php?tab=chat');
        exit;
    }

    if ($action !== '' && $action !== 'clear_action_logs') {
        $log_status = (!empty($toast['type']) && $toast['type'] === 'error') ? 'error' : 'success';
        spidercms_log_action($action, $log_status, ['toast' => $toast['msg'] ?? '']);
    }
}

// ----------------------------------------------------------------------
// Widok panelu – zbieranie danych
// ----------------------------------------------------------------------
$menu_enabled = file_exists(__DIR__ . '/.menu_enabled');
$menu_items = json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true);
$pages = [];
foreach (glob(ACTIVE_PAGES_DIR . '/*.php') ?: [] as $f) {
    $slug = basename($f, '.php');
    $pages[] = ['slug' => $slug, 'modified' => date('Y-m-d H:i', filemtime($f))];
}
// Media Library - musi być zawsze zdefiniowane
$media_files = get_media_files();
$sliders = slider_load_all();
$edit_slider_id = slider_slug($_GET['edit_slider'] ?? '');
$edit_slider = ($edit_slider_id !== '' && isset($sliders[$edit_slider_id])) ? $sliders[$edit_slider_id] : null;
$chat_conversations = chat_load_conversations();
chat_backfill_archive_from_conversations();
$chat_archive_index = chat_load_archive_index();
$chat_unread = chat_unread_count();
$log_filter_action = trim((string)($_GET['log_action'] ?? ''));
$log_filter_status = trim((string)($_GET['log_status'] ?? ''));
$action_logs = array_slice(spidercms_read_action_logs_for_export($log_filter_action, $log_filter_status, trim((string)($_GET['date_from'] ?? '')), trim((string)($_GET['date_to'] ?? ''))), 0, 300);
$log_actions_available = [];
foreach (spidercms_read_action_logs(1000) as $lr) {
    $a = $lr['action'] ?? '';
    if ($a !== '') $log_actions_available[$a] = spidercms_log_label($a);
}
asort($log_actions_available);
// Jeżeli wskazana strona główna nie istnieje, wracamy do index.php albo pierwszej dostępnej strony.
$page_slugs = array_column($pages, 'slug');
if (!in_array($homepage_slug, $page_slugs, true)) {
    $homepage_slug = in_array('index', $page_slugs, true) ? 'index' : ($page_slugs[0] ?? 'index');
    file_put_contents(__DIR__ . '/.homepage', $homepage_slug);
}

$edit_slug = spidercms_clean_slug($_GET['edit'] ?? '');
$edit_content = '';
$edit_title = '';
if ($edit_slug && file_exists($f = ACTIVE_PAGES_DIR . '/' . $edit_slug . '.php')) {
    $raw = file_get_contents($f);
    $edit_title = spidercms_page_get_title_from_source($raw, $edit_slug);
    if (preg_match('/\$content\s*=\s*<<<HTML\s*(.*?)\s*HTML;/s', $raw, $m)) {
        $edit_content = trim($m[1]);
    }
}

function render_editor_tools() {
    ?>
    <div class="editor-tools">
        <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Szybkie elementy strony</h3>
        <div class="editor-tool-grid">
            <button type="button" class="editor-tool-btn" data-snippet="hero"><i class="fa-solid fa-heading"></i> Sekcja hero</button>
            <button type="button" class="editor-tool-btn" data-snippet="button"><i class="fa-solid fa-square-arrow-up-right"></i> Przycisk CTA</button>
            <button type="button" class="editor-tool-btn" data-snippet="columns"><i class="fa-solid fa-columns-3"></i> Dwie kolumny</button>
            <button type="button" class="editor-tool-btn" data-snippet="cards"><i class="fa-solid fa-table-cells-large"></i> Karty oferty</button>
            <button type="button" class="editor-tool-btn" data-snippet="gallery"><i class="fa-solid fa-images"></i> Galeria zdjęć</button>
            <button type="button" class="editor-tool-btn" data-snippet="faq"><i class="fa-solid fa-circle-question"></i> FAQ</button>
            <button type="button" class="editor-tool-btn" data-snippet="contact"><i class="fa-solid fa-address-card"></i> Blok kontaktowy</button>
            <button type="button" class="editor-tool-btn" data-snippet="separator"><i class="fa-solid fa-minus"></i> Separator</button>
        </div>
        <div class="editor-note">Kliknięcie wstawia gotowy blok w miejscu kursora w edytorze. Bloki możesz później dowolnie edytować w TinyMCE.</div>
    </div>

    <div class="editor-tools editor-page-presets">
        <h3><i class="fa-solid fa-layer-group"></i> Gotowe presety stron</h3>
        <div class="editor-tool-grid">
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="contact"><i class="fa-solid fa-address-book"></i> Kontakt</button>
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="about"><i class="fa-solid fa-circle-info"></i> O nas</button>
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="offer"><i class="fa-solid fa-briefcase"></i> Oferta</button>
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="services"><i class="fa-solid fa-screwdriver-wrench"></i> Usługi</button>
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="landing"><i class="fa-solid fa-bullhorn"></i> Landing Page</button>
            <button type="button" class="editor-tool-btn page-preset-btn" data-page-preset="faqpage"><i class="fa-solid fa-circle-question"></i> FAQ / Pomoc</button>
        </div>
        <div class="editor-note">Preset strony zastępuje aktualną treść edytora gotowym, stylowym układem. Przy tworzeniu nowej strony automatycznie podpowie też tytuł i slug.</div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel administracyjny – SpiderCMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/7.8.0/tinymce.min.js"></script>
    <script>
      tinymce.init({
        selector: 'textarea.editor',
        language: 'pl',
        height: 540,
        promotion: false,
        branding: false,
        plugins: 'advlist autolink lists link image media charmap preview anchor searchreplace visualblocks code fullscreen table help wordcount lists autoresize insertdatetime template pagebreak nonbreaking',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | pagebreak nonbreaking template | code fullscreen preview removeformat',
        menubar: 'file edit view insert format tools table help',
        valid_elements: '*[*]',
        extended_valid_elements: 'style,script[*],iframe[*],section[*],article[*],div[*],a[*],img[*],video[*],source[*]',
        templates: [
          { title: 'Sekcja hero', description: 'Duży nagłówek z przyciskiem', content: '<section class="cms-hero"><h1>Duży nagłówek strony</h1><p>Krótki opis oferty lub treści strony.</p><p><a class="cms-btn" href="#kontakt">Skontaktuj się</a></p></section>' },
          { title: 'Dwie kolumny', description: 'Układ 50/50', content: '<div class="cms-columns"><div><h2>Lewa kolumna</h2><p>Treść pierwszej kolumny.</p></div><div><h2>Prawa kolumna</h2><p>Treść drugiej kolumny.</p></div></div>' },
          { title: 'FAQ', description: 'Pytania i odpowiedzi', content: '<section class="cms-faq"><h2>Najczęstsze pytania</h2><details open><summary>Pytanie numer 1</summary><p>Odpowiedź na pytanie.</p></details><details><summary>Pytanie numer 2</summary><p>Odpowiedź na pytanie.</p></details></section>' }
        ],
        content_style: 'body{font-family:system-ui,sans-serif;font-size:16px;line-height:1.65;} .cms-hero{padding:3rem 2rem;border-radius:18px;background:#f3e8ff;} .cms-btn{display:inline-block;padding:.85rem 1.25rem;border-radius:999px;background:#a855f7;color:#fff;text-decoration:none;font-weight:700;} .cms-columns{display:grid;grid-template-columns:1fr 1fr;gap:2rem;} .cms-card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;} .cms-card{padding:1.2rem;border:1px solid #e5e7eb;border-radius:14px;background:#fff;} details{padding:1rem;border:1px solid #e5e7eb;border-radius:10px;margin:.7rem 0;}'
      });
    </script>
    <style>
        :root {
            --primary: <?= htmlspecialchars($theme['primary'] ?? '#a855f7') ?>;
            --primary-dark: <?= htmlspecialchars($theme['primary-dark'] ?? '#7e22ce') ?>;
            --accent: <?= htmlspecialchars($theme['accent'] ?? '#2563eb') ?>;
            --success: #10b981;
            --danger: #ef4444;
            --gray-50: #0f172a;
            --gray-100: #1e293b;
            --gray-200: #334155;
            --sidebar: 320px;
            --menu-color: #c084fc;
            --footer-color: #fb923c;
            --settings-color: #60a5fa;
            --about-color: #f472b6;
            --chat-color: #22c55e;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:system-ui,sans-serif; background:var(--gray-50); color:#f8fafc; min-height:100vh; display:flex; }
        #sidebar { width:var(--sidebar); background:#0f172a; border-right:1px solid var(--gray-200); height:100vh; position:fixed; overflow-y:auto; }
        #sidebar { scrollbar-width:thin; scrollbar-color:rgba(168,85,247,.55) rgba(15,23,42,.35); }
        #sidebar::-webkit-scrollbar { width:9px; }
        #sidebar::-webkit-scrollbar-track { background:linear-gradient(180deg,rgba(15,23,42,.95),rgba(2,6,23,.95)); border-left:1px solid rgba(148,163,184,.10); }
        #sidebar::-webkit-scrollbar-thumb { background:linear-gradient(180deg,rgba(168,85,247,.85),rgba(37,99,235,.75)); border-radius:999px; border:2px solid rgba(15,23,42,.95); box-shadow:inset 0 0 0 1px rgba(255,255,255,.08); }
        #sidebar::-webkit-scrollbar-thumb:hover { background:linear-gradient(180deg,rgba(192,132,252,.95),rgba(56,189,248,.85)); }
        #sidebar::-webkit-scrollbar-corner { background:#0f172a; }
        #sidebar-header { padding:1.35rem 1.65rem; font-size:1.45rem; font-weight:700; color:var(--primary); border-bottom:1px solid var(--gray-200); display:flex; align-items:center; gap:0.6rem; }
        #sidebar-header img { max-height:80px; width:auto; border-radius:4px; }
        #sidebar a { display:flex; align-items:center; gap:0.95rem; padding:1.02rem 1.65rem; color:#94a3b8; text-decoration:none; transition:0.15s; }

        #sidebar a span, #sidebar a { min-width:0; }
        #sidebar a { line-height:1.25; }
        #sidebar a:hover, #sidebar a.active { background:var(--gray-100); color:var(--primary); }
        #sidebar .menu-tab { color:var(--menu-color); }
        #sidebar .settings-tab { color:var(--settings-color); }
        #sidebar .about-tab { color:var(--about-color); }
        #sidebar .footer-tab { color:var(--footer-color); }
        #sidebar .menu-tab.active, #sidebar .settings-tab.active, #sidebar .about-tab.active, #sidebar .footer-tab.active { background:var(--gray-100); font-weight:600; }
        #main { margin-left:var(--sidebar); flex:1; padding:2rem 2.4rem; min-width:0; }
        header { background:var(--gray-100); padding:1.2rem 2rem; border-bottom:1px solid var(--gray-200); display:flex; justify-content:space-between; align-items:center; border-radius:10px; margin-bottom:1.8rem; box-shadow:0 4px 14px rgba(0,0,0,0.2); }
        header h1 { font-size:1.6rem; margin:0; }
        .card { background:var(--gray-100); border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,0.2); padding:1.7rem 2rem; margin-bottom:1.8rem; border:1px solid var(--gray-200); }
        label { display:block; margin:1.2rem 0 0.4rem; font-weight:500; color:#94a3b8; }
        input[type="text"], input[type="file"], textarea.input-field { width:100%; padding:0.75rem 1rem; border:1px solid var(--gray-200); background:#0f172a; color:#f8fafc; border-radius:6px; box-sizing:border-box; }
        input[type="text"]:focus{ outline:2px solid var(--primary); }
        input[type="color"] { padding:0.4rem; height:2.8rem; width:4rem; border:1px solid var(--gray-200); background:none; border-radius:6px; cursor:pointer; }
        table { width:100%; border-collapse:collapse; margin-top:0.8rem; }
        th, td { padding:0.9rem 1.1rem; text-align:left; border-bottom:1px solid var(--gray-200); }
        th { background:var(--gray-50); color:#94a3b8; font-weight:600; }
        tr:hover { background:rgba(255,255,255,0.02); }
        .btn { padding:0.55rem 1.1rem; border-radius:6px; color:white; text-decoration:none; font-weight:500; display:inline-flex; align-items:center; gap:0.45rem; transition:0.14s; }
        .btn-view { background:#10b981; }
        .btn-view:hover { background:#059669; }
        .btn-edit { background:#3b82f6; }
        .btn-delete { background:#ef4444; border:none; cursor:pointer; color:white; font-weight:500; }
        .btn-export { background:#8b5cf6; }
        button[type="submit"] { background:var(--primary); color:white; border:none; padding:0.9rem 1.6rem; border-radius:6px; font-weight:600; cursor:pointer; transition:0.15s; }
        button[type="submit"]:hover { background:var(--primary-dark); }
        .btn-full-export { background:#059669; color:white; padding:1rem 2rem; border-radius:8px; font-weight:700; font-size:1.1rem; display:inline-flex; align-items:center; gap:0.6rem; margin-top:1rem; border:none; cursor:pointer; box-shadow:0 4px 12px rgba(5,150,105,0.3); transition:all 0.2s; }
        .btn-full-export:hover { background:#047857; transform:translateY(-2px); box-shadow:0 6px 16px rgba(5,150,105,0.4); }
        .menu-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem; align-items:flex-end; }
        .menu-main-block{background:#111827;border:1px solid #334155;border-radius:14px;padding:1rem;margin-bottom:1.2rem;}
        .submenu-list{margin:.6rem 0 0 1.5rem;border-left:2px solid rgba(192,132,252,.35);padding-left:1rem;display:grid;gap:.65rem;}
        .submenu-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.75rem;align-items:flex-end;background:#0f172a;border:1px solid #334155;border-radius:12px;padding:.8rem;}
        .add-submenu-btn{margin-top:.8rem;background:#334155!important;color:#f8fafc!important;padding:.65rem .9rem!important;font-size:.9rem!important;width:auto!important;}
        .remove-submenu-btn{background:#ef4444!important;color:#fff!important;padding:.7rem .85rem!important;width:auto!important;}
        @media(max-width:900px){.submenu-row{grid-template-columns:1fr}.menu-row{grid-template-columns:1fr}}
        .toast { position:fixed; top:1.2rem; right:1.2rem; padding:0.9rem 1.5rem; border-radius:8px; color:white; font-weight:500; z-index:1000; box-shadow:0 5px 18px rgba(0,0,0,0.3); }
        .toast.success { background:var(--success); }
        .toast.error { background:var(--danger); }
        .dashboard-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1.5rem; margin-top:1.5rem; }
        .dash-card { background:var(--gray-100); border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.15); padding:1.5rem; text-align:center; border:1px solid var(--gray-200); }
        .dash-card h3 { margin:0 0 1rem; color:var(--primary); font-size:1.3rem; }
        .dash-number { font-size:2.8rem; font-weight:700; color:var(--accent); margin:0.5rem 0; }
        code { background:#0f172a; padding:0.2rem 0.4rem; border-radius:4px; color:#c084fc; }
        .homepage-badge { display:inline-flex; align-items:center; gap:0.35rem; color:#fbbf24; background:rgba(251,191,36,0.12); border:1px solid rgba(251,191,36,0.35); padding:0.2rem 0.45rem; border-radius:999px; font-size:0.82rem; margin-left:0.6rem; font-weight:700; }
        .btn-homepage { background:#f59e0b; border:none; cursor:pointer; }
        .color-preview { display:flex; align-items:center; gap:1rem; margin-top:0.4rem; }
        .color-preview span { font-family:monospace; font-size:0.95rem; color:#94a3b8; }
        .editor-tools { margin:1rem 0 1.2rem; padding:1rem; border:1px solid var(--gray-200); border-radius:10px; background:#0f172a; }
        .editor-tools h3 { margin:0 0 0.8rem; color:var(--primary); font-size:1.05rem; }
        .editor-tool-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:0.7rem; }
        .editor-tool-btn { border:1px solid var(--gray-200); background:#1e293b; color:#f8fafc; border-radius:8px; padding:0.7rem 0.85rem; text-align:left; cursor:pointer; font-weight:600; transition:0.15s; }
        .editor-tool-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .editor-note { margin-top:0.75rem; color:#94a3b8; font-size:0.92rem; line-height:1.5; }
        .editor-page-presets { border-color:rgba(168,85,247,.42); background:linear-gradient(135deg,rgba(168,85,247,.10),rgba(37,99,235,.08)); }
        .page-preset-btn { background:#111827; border-color:rgba(168,85,247,.35); }
        .page-preset-btn:hover { background:#1e1b4b; }

        .settings-tabs-card { overflow: visible; }
        .settings-header-row { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; margin-bottom:1.2rem; }
        .settings-tabs { position:sticky; top:0; z-index:20; display:flex; gap:0.55rem; flex-wrap:wrap; padding:0.75rem; margin:1.2rem 0 1.4rem; border:1px solid var(--gray-200); border-radius:12px; background:#0f172a; box-shadow:0 8px 18px rgba(0,0,0,0.18); }
        .settings-tab-btn { border:1px solid var(--gray-200); background:#1e293b; color:#cbd5e1; border-radius:10px; padding:0.72rem 0.95rem; cursor:pointer; font-weight:700; display:flex; align-items:center; gap:0.5rem; transition:0.16s; }
        .settings-tab-btn:hover { color:#fff; border-color:var(--settings-color); transform:translateY(-1px); }
        .settings-tab-btn.active { background:linear-gradient(135deg, var(--settings-color), var(--primary)); color:white; border-color:transparent; box-shadow:0 8px 18px rgba(96,165,250,0.22); }
        .settings-panel { display:none; border:1px solid var(--gray-200); border-radius:14px; padding:1.35rem; background:rgba(15,23,42,0.58); min-height:360px; }
        .settings-panel.active { display:block; animation:settingsFade .18s ease-in-out; }
        @keyframes settingsFade { from{opacity:0; transform:translateY(4px);} to{opacity:1; transform:translateY(0);} }
        .settings-panel-title { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; border-bottom:1px solid var(--gray-200); padding-bottom:1rem; margin-bottom:1.2rem; }
        .settings-panel-title h3 { margin:0; color:#f8fafc; font-size:1.15rem; }
        .settings-panel-title p { margin:0; color:#94a3b8; max-width:650px; line-height:1.55; }
        .settings-box { margin:1.2rem 0; padding:1.2rem; border:1px solid var(--gray-200); border-radius:10px; background:#0f172a; }
        .settings-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1.4rem; }
        .settings-mini-title { margin:1.5rem 0 1rem; font-weight:700; color:#94a3b8; letter-spacing:.02em; }
        .settings-actions-bottom { margin-top:2rem; text-align:right; }
        .settings-save-floating { background:#10b981; color:white; border:none; padding:0.8rem 1.1rem; border-radius:10px; cursor:pointer; font-weight:800; display:inline-flex; align-items:center; gap:0.55rem; box-shadow:0 8px 16px rgba(16,185,129,0.22); }
        .settings-save-floating:hover { background:#059669; }
        .settings-select, select { width:100%; padding:0.75rem 1rem; border:1px solid var(--gray-200); background:#0f172a; color:#f8fafc; border-radius:6px; box-sizing:border-box; }
        .social-admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}.social-admin-card{border:1px solid var(--gray-200);border-radius:12px;background:#0f172a;padding:1rem}.social-admin-card h4{margin:0 0 .7rem;color:#f8fafc}.social-toggle-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem}.social-toggle-grid label{margin:0;display:flex;align-items:center;gap:.7rem;color:#e2e8f0;background:#0f172a;border:1px solid var(--gray-200);border-radius:10px;padding:.75rem}.social-toggle-grid input{width:auto;transform:scale(1.15)}
        input[type="password"] { width:100%; padding:0.75rem 1rem; border:1px solid var(--gray-200); background:#0f172a; color:#f8fafc; border-radius:6px; box-sizing:border-box; }
        .settings-security-box { margin:0; padding:1.8rem; border:2px solid #334155; border-radius:12px; background:#0f172a; }
        @media (max-width: 820px) { .settings-header-row, .settings-panel-title { flex-direction:column; } .settings-save-floating { width:100%; justify-content:center; } .settings-responsive-grid { grid-template-columns:1fr !important; } .settings-tab-btn { flex:1 1 45%; justify-content:center; } }
        .chat-layout { display:grid; grid-template-columns:minmax(260px,360px) 1fr; gap:1.5rem; align-items:start; }
        .chat-list { display:flex; flex-direction:column; gap:0.75rem; }
        .chat-thread-link { display:block; padding:0.9rem; border:1px solid var(--gray-200); border-radius:10px; background:#0f172a; color:#f8fafc; text-decoration:none; }
        .chat-thread-link:hover, .chat-thread-link.active { border-color:var(--chat-color); box-shadow:0 0 0 1px rgba(34,197,94,0.25); }
        .chat-thread-meta { display:flex; justify-content:space-between; gap:0.7rem; color:#94a3b8; font-size:0.83rem; margin-top:0.35rem; }
        .chat-unread-badge { display:inline-flex; align-items:center; justify-content:center; min-width:24px; height:24px; padding:0 0.45rem; border-radius:999px; background:#ef4444; color:#fff; font-size:0.78rem; font-weight:800; margin-left:auto; }
        .chat-messages { background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1rem; min-height:360px; max-height:560px; overflow:auto; display:flex; flex-direction:column; gap:0.8rem; }
        .chat-bubble { max-width:78%; padding:0.85rem 1rem; border-radius:14px; line-height:1.45; white-space:pre-wrap; }
        .chat-bubble.user { background:#1e293b; border:1px solid #334155; align-self:flex-start; }
        .chat-bubble.admin { background:var(--chat-color); color:#052e16; align-self:flex-end; font-weight:600; }
        .chat-time { display:block; margin-top:0.35rem; font-size:0.75rem; opacity:0.68; }
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;margin-bottom:1.4rem}.stats-card{background:#111827;border:1px solid #334155;border-radius:14px;padding:1.1rem}.stats-card h3{margin:0 0 .5rem;color:#94a3b8;font-size:.95rem}.stats-number{font-size:2rem;font-weight:800;color:#f8fafc}.stats-chart{display:flex;align-items:end;gap:6px;height:190px;padding:1rem;background:#0f172a;border-radius:14px;border:1px solid #334155}.stats-bar{flex:1;min-width:6px;background:linear-gradient(180deg,#38bdf8,#2563eb);border-radius:7px 7px 0 0;position:relative}.stats-bar span{position:absolute;bottom:-24px;left:50%;transform:translateX(-50%);font-size:10px;color:#64748b;white-space:nowrap}.stats-table-small td,.stats-table-small th{padding:.7rem .8rem}.stats-pill{display:inline-flex;padding:.25rem .55rem;border-radius:999px;background:rgba(56,189,248,.12);color:#bae6fd;font-size:.82rem;font-weight:700}

        .nav-section-title{margin:1.25rem 0 .45rem;padding:.35rem .75rem;color:#64748b;font-size:.72rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;border-top:1px solid rgba(148,163,184,.14);padding-top:.95rem}
        .nav-section-title:first-of-type{border-top:0;margin-top:.4rem;padding-top:.35rem}
        .settings-map{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;margin:1.2rem 0 1.4rem}
        .settings-map-card{border:1px solid var(--gray-200);border-radius:14px;background:#0f172a;padding:1rem;text-decoration:none;color:#e5e7eb;display:block;transition:.16s}
        .settings-map-card:hover{transform:translateY(-2px);border-color:var(--settings-color);box-shadow:0 10px 25px rgba(0,0,0,.18)}
        .settings-map-card strong{display:flex;align-items:center;gap:.55rem;color:#fff;margin-bottom:.35rem}
        .settings-map-card span{display:block;color:#94a3b8;font-size:.88rem;line-height:1.45}
        .settings-tabs{position:sticky;top:0;z-index:5;background:rgba(2,6,23,.92);backdrop-filter:blur(10px);border:1px solid var(--gray-200);border-radius:14px;padding:.7rem;margin-bottom:1.2rem}
        .settings-tab-group-label{width:100%;color:#64748b;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin:.15rem .25rem .2rem}
        .settings-box h4{margin:0 0 .75rem;color:#e2e8f0}

        /* SpiderCMS – czytelniejszy układ ustawień */
        .settings-clean-intro{display:grid;grid-template-columns:1.15fr .85fr;gap:1rem;margin:1.2rem 0 1.5rem;align-items:stretch}
        .settings-clean-card{border:1px solid var(--gray-200);border-radius:16px;background:linear-gradient(135deg,rgba(15,23,42,.92),rgba(30,41,59,.72));padding:1.15rem;box-shadow:0 10px 26px rgba(0,0,0,.14)}
        .settings-clean-card h3{margin:0 0 .45rem;color:#f8fafc;display:flex;gap:.55rem;align-items:center}
        .settings-clean-card p{margin:.2rem 0;color:#94a3b8;line-height:1.55;font-size:.94rem}
        .settings-clean-list{display:grid;gap:.55rem;margin-top:.9rem}
        .settings-clean-list a{display:flex;align-items:center;justify-content:space-between;gap:.75rem;text-decoration:none;color:#e2e8f0;background:#0f172a;border:1px solid #334155;border-radius:12px;padding:.72rem .85rem;font-weight:700}
        .settings-clean-list a:hover{border-color:var(--settings-color);color:#fff}
        .settings-clean-list small{color:#94a3b8;font-weight:600}
        .settings-tabs{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:.55rem;position:relative!important;top:auto!important;background:rgba(15,23,42,.76)!important;backdrop-filter:none!important;padding:1rem!important}
        .settings-tab-group-label{grid-column:1/-1;margin:.6rem 0 .15rem!important;padding:.65rem .2rem .15rem;border-top:1px solid rgba(148,163,184,.15)}
        .settings-tab-group-label:first-child{border-top:0;margin-top:0!important;padding-top:.1rem!important}
        .settings-tab-btn{justify-content:flex-start!important;min-height:48px;text-align:left}
        .settings-panel{padding:1.6rem!important}
        .settings-panel-title{background:#0f172a;border:1px solid #334155;border-radius:14px;padding:1rem 1.1rem!important;margin-bottom:1.35rem!important}
        .settings-panel-title h3{font-size:1.22rem!important}
        .settings-box{border:1px solid #334155!important;background:rgba(15,23,42,.78)!important;border-radius:14px!important;margin-bottom:1.05rem!important}
        .settings-mini-title{margin:1.35rem 0 .75rem!important;padding:.55rem .75rem;border-left:4px solid var(--settings-color);background:rgba(96,165,250,.08);border-radius:10px;color:#dbeafe!important;font-weight:900!important}
        .settings-actions-bottom{position:sticky;bottom:0;background:linear-gradient(180deg,rgba(2,6,23,0),rgba(2,6,23,.94) 25%);padding-top:1rem;margin-top:1.3rem;z-index:4}
        .settings-actions-bottom button{box-shadow:0 12px 30px rgba(0,0,0,.28)}
        @media(max-width:980px){.settings-clean-intro{grid-template-columns:1fr}.settings-tabs{grid-template-columns:1fr!important}.settings-actions-bottom{position:static;background:transparent}}



        /* SpiderCMS – prosty, zwarty układ Ustawień Witryny */
        .settings-clean-intro{display:none!important}
        .settings-tabs-card{background:rgba(15,23,42,.78)!important;border-radius:18px!important;padding:1.1rem!important}
        .settings-header-row{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:1rem!important;margin-bottom:1rem!important;border-bottom:1px solid rgba(148,163,184,.18)!important;padding-bottom:.9rem!important}
        .settings-header-row h2{font-size:1.35rem!important;margin:0!important}
        .settings-header-row p{font-size:.92rem!important;line-height:1.45!important;max-width:760px!important}
        .settings-tabs{display:flex!important;flex-wrap:wrap!important;gap:.5rem!important;background:#0f172a!important;border:1px solid #334155!important;border-radius:14px!important;padding:.65rem!important;margin:0 0 1rem!important;position:static!important;top:auto!important}
        .settings-tab-group-label{display:none!important}
        .settings-tab-btn{width:auto!important;min-height:0!important;padding:.68rem .85rem!important;border-radius:10px!important;background:rgba(30,41,59,.9)!important;border:1px solid #334155!important;font-size:.9rem!important;line-height:1.15!important;justify-content:center!important;gap:.45rem!important;flex:0 0 auto!important}
        .settings-tab-btn.active{background:linear-gradient(135deg,var(--settings-color),#7c3aed)!important;border-color:transparent!important;color:#fff!important}
        .settings-panel{padding:0!important;background:transparent!important;border:0!important}
        .settings-panel-title{padding:.8rem .95rem!important;margin:0 0 .8rem!important;border-radius:12px!important;background:#111827!important;border:1px solid #334155!important}
        .settings-panel-title h3{font-size:1.05rem!important;margin:0 0 .2rem!important}
        .settings-panel-title p{font-size:.88rem!important;margin:0!important;color:#94a3b8!important}
        .settings-box{padding:1rem!important;margin-bottom:.8rem!important;border-radius:12px!important;background:rgba(15,23,42,.72)!important;border:1px solid #334155!important}
        .settings-mini-title{margin:1rem 0 .6rem!important;padding:.55rem .75rem!important;border-radius:10px!important;font-size:.95rem!important}
        .settings-responsive-grid{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))!important}
        .settings-actions-bottom{position:static!important;background:transparent!important;padding-top:.6rem!important;margin-top:.8rem!important}
        .settings-actions-bottom button,.settings-panel button{border-radius:10px!important}

        /* SpiderCMS – uporządkowana zakładka Chat */
        .chat-settings-wrap{display:grid;grid-template-columns:repeat(12,1fr);gap:1rem;margin-top:1rem}
        .chat-settings-card{grid-column:span 6;background:#0f172a;border:1px solid #334155;border-radius:14px;padding:1rem}
        .chat-settings-card.wide{grid-column:span 12}
        .chat-settings-card h3{margin:0 0 .8rem;color:#e2e8f0;font-size:1rem;display:flex;align-items:center;gap:.5rem}
        .chat-settings-card .mini-help{color:#94a3b8;font-size:.86rem;line-height:1.45;margin:.15rem 0 .8rem}
        .chat-fields-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.85rem;align-items:end}
        .chat-check{display:flex!important;align-items:center!important;gap:.65rem!important;margin:0!important;padding:.75rem .8rem;border:1px solid #334155;border-radius:12px;background:rgba(2,6,23,.35)}
        .chat-check input{width:auto!important;transform:scale(1.18)}
        .chat-actions{display:flex;gap:.65rem;flex-wrap:wrap;margin-top:1rem}
        .chat-actions button{border-radius:10px!important}
        .chat-layout{align-items:start!important}
        .chat-layout>.card,.chat-layout+.card,.chat-layout .card{border-radius:16px!important}
        @media(max-width:980px){.chat-settings-card{grid-column:span 12}.settings-header-row{align-items:flex-start!important;flex-direction:column!important}.settings-tab-btn{flex:1 1 calc(50% - .5rem)!important}}
        @media(max-width:560px){.settings-tab-btn{flex:1 1 100%!important}.chat-fields-grid{grid-template-columns:1fr!important}}

        @media (max-width: 980px) { .chat-layout { grid-template-columns:1fr; } }

        /* SpiderCMS – system logów */
        .logs-toolbar{display:flex;gap:.75rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem;background:#0f172a;border:1px solid #334155;border-radius:14px;padding:1rem}
        .logs-toolbar .field{min-width:180px;flex:1}
        .logs-table-wrap{overflow:auto;border:1px solid #334155;border-radius:14px;background:#0f172a}
        .logs-table{width:100%;border-collapse:collapse;min-width:980px}
        .logs-table th,.logs-table td{padding:.85rem;border-bottom:1px solid #1f2937;text-align:left;vertical-align:top;font-size:.92rem}
        .logs-table th{background:#111827;color:#cbd5e1;position:sticky;top:0;z-index:1}
        .logs-table tr:hover td{background:rgba(168,85,247,.06)}
        .log-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.25rem .55rem;font-size:.75rem;font-weight:900;white-space:nowrap}
        .log-badge.success{background:rgba(34,197,94,.15);color:#86efac;border:1px solid rgba(34,197,94,.28)}
        .log-badge.error{background:rgba(239,68,68,.15);color:#fecaca;border:1px solid rgba(239,68,68,.28)}
        .log-badge.warning{background:rgba(245,158,11,.15);color:#fde68a;border:1px solid rgba(245,158,11,.28)}
        .log-badge.info{background:rgba(56,189,248,.12);color:#bae6fd;border:1px solid rgba(56,189,248,.25)}
        .log-context{max-width:360px;color:#94a3b8;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
        .log-small{color:#64748b;font-size:.78rem;line-height:1.35}
    

        /* SpiderCMS – bezpieczna responsywność panelu administracyjnego */
        .spidercms-mobile-topbar{display:none;}
        .spidercms-sidebar-backdrop{display:none;}

        @media (max-width: 1024px){
            :root{ --sidebar: 300px; }
            body{ overflow-x:hidden; }
            .spidercms-mobile-topbar{
                display:flex;
                position:sticky;
                top:0;
                z-index:1200;
                align-items:center;
                justify-content:space-between;
                gap:.8rem;
                padding:.85rem 1rem;
                background:#0f172a;
                border-bottom:1px solid #334155;
                box-shadow:0 10px 24px rgba(0,0,0,.22);
            }
            .spidercms-mobile-brand{display:flex;align-items:center;gap:.65rem;font-weight:900;color:#f8fafc;}
            .spidercms-mobile-brand img{height:34px;width:auto;border-radius:6px;}
            .spidercms-mobile-menu-btn{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                gap:.45rem;
                border:1px solid #334155;
                background:#111827;
                color:#f8fafc;
                border-radius:12px;
                padding:.68rem .9rem;
                font-weight:800;
                cursor:pointer;
            }
            #sidebar{
                position:fixed;
                top:0;
                left:0;
                bottom:0;
                width:min(86vw, 320px);
                transform:translateX(-105%);
                transition:transform .22s ease;
                z-index:1300;
                box-shadow:18px 0 50px rgba(0,0,0,.45);
            }
            body.spidercms-sidebar-open #sidebar{ transform:translateX(0); }
            .spidercms-sidebar-backdrop{
                display:none;
                position:fixed;
                inset:0;
                z-index:1250;
                background:rgba(2,6,23,.68);
                backdrop-filter:blur(2px);
            }
            body.spidercms-sidebar-open .spidercms-sidebar-backdrop{ display:block; }
            #main{ margin-left:0!important; padding:1rem!important; }
            header{ flex-direction:column; align-items:flex-start; gap:.85rem; padding:1rem!important; }
            header h1{ font-size:1.25rem!important; }
            .card{ padding:1rem!important; border-radius:14px!important; }
            .dashboard-grid,.stats-grid,.settings-map,.settings-grid,.editor-tool-grid{ grid-template-columns:1fr!important; }
            .settings-header-row{ flex-direction:column!important; align-items:flex-start!important; }
            .settings-tabs{ overflow-x:auto!important; flex-wrap:nowrap!important; scrollbar-width:thin; }
            .settings-tab-btn{ white-space:nowrap!important; flex:0 0 auto!important; }
            .settings-panel{ min-height:0!important; }
            .chat-layout,.settings-clean-intro{ grid-template-columns:1fr!important; }
            .chat-settings-card{ grid-column:span 12!important; }
            .menu-row,.submenu-row{ grid-template-columns:1fr!important; }
            table{ min-width:760px; }
            .logs-table{ min-width:980px; }
            .table-wrap,.logs-table-wrap{ overflow-x:auto; }
            input[type="text"],input[type="password"],input[type="email"],input[type="number"],input[type="url"],select,textarea,.input-field{ max-width:100%; }
            .btn,button[type="submit"]{ white-space:normal; }
        }

        @media (max-width: 560px){
            .spidercms-mobile-topbar{ padding:.7rem .8rem; }
            .spidercms-mobile-menu-btn span{ display:none; }
            #main{ padding:.75rem!important; }
            .card{ margin-bottom:1rem!important; }
            th,td{ padding:.7rem .75rem!important; }
            .toast{ left:.75rem!important; right:.75rem!important; top:.75rem!important; }
            .dash-number,.stats-number{ font-size:2rem!important; }
            .logs-toolbar{ display:grid!important; grid-template-columns:1fr!important; }
        }


/* SPIDERCMS MOBILE HARD FIX START */
:root{
    --spidercms-mobile-sidebar-width: min(330px, 88vw);
}

.spidercms-mobile-topbar,
.spidercms-mobile-backdrop{
    display:none;
}

@media (max-width: 1024px){
    html,
    body{
        width:100%!important;
        max-width:100%!important;
        overflow-x:hidden!important;
    }

    body{
        min-width:0!important;
    }

    .spidercms-mobile-topbar{
        display:flex!important;
        position:fixed!important;
        top:0!important;
        left:0!important;
        right:0!important;
        height:58px!important;
        z-index:2147483000!important;
        align-items:center!important;
        gap:10px!important;
        padding:8px 12px!important;
        background:linear-gradient(135deg,#0f172a,#1e293b)!important;
        color:#fff!important;
        box-shadow:0 10px 30px rgba(0,0,0,.28)!important;
    }

    .spidercms-mobile-btn{
        width:42px!important;
        height:42px!important;
        min-width:42px!important;
        border:0!important;
        border-radius:13px!important;
        background:linear-gradient(135deg,#a855f7,#2563eb)!important;
        color:#fff!important;
        font-size:24px!important;
        line-height:1!important;
        font-weight:900!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        cursor:pointer!important;
        padding:0!important;
        margin:0!important;
    }

    .spidercms-mobile-title{
        font-size:15px!important;
        font-weight:800!important;
        white-space:nowrap!important;
        overflow:hidden!important;
        text-overflow:ellipsis!important;
    }

    .spidercms-mobile-backdrop{
        position:fixed!important;
        inset:0!important;
        background:rgba(2,6,23,.66)!important;
        z-index:2147482990!important;
    }

    body.spidercms-menu-open .spidercms-mobile-backdrop{
        display:block!important;
    }

    /* Najczęstsze wrappery panelu */
    .layout,
    .admin-layout,
    .panel-layout,
    .dashboard-layout,
    .app-layout,
    body > .layout,
    body > .admin-layout{
        display:block!important;
        grid-template-columns:1fr!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        margin:0!important;
    }

    /* Lewy sidebar */
    .sidebar,
    aside.sidebar,
    .admin-sidebar,
    .side-menu,
    nav.sidebar{
        position:fixed!important;
        top:0!important;
        left:0!important;
        bottom:0!important;
        width:var(--spidercms-mobile-sidebar-width)!important;
        max-width:88vw!important;
        min-width:0!important;
        height:100vh!important;
        max-height:100vh!important;
        z-index:2147482999!important;
        transform:translateX(-110%)!important;
        transition:transform .24s ease!important;
        overflow-y:auto!important;
        overflow-x:hidden!important;
        overscroll-behavior:contain!important;
        padding-top:74px!important;
        box-shadow:24px 0 80px rgba(0,0,0,.42)!important;
    }

    body.spidercms-menu-open .sidebar,
    body.spidercms-menu-open aside.sidebar,
    body.spidercms-menu-open .admin-sidebar,
    body.spidercms-menu-open .side-menu,
    body.spidercms-menu-open nav.sidebar{
        transform:translateX(0)!important;
    }

    .sidebar *,
    aside.sidebar *,
    .admin-sidebar *,
    .side-menu *{
        max-width:100%!important;
    }

    .nav,
    .sidebar nav,
    aside.sidebar nav{
        display:flex!important;
        flex-direction:column!important;
        gap:8px!important;
    }

    .nav a,
    .sidebar a,
    aside.sidebar a{
        white-space:normal!important;
        word-break:break-word!important;
    }

    /* Główna treść */
    .main,
    main.main,
    .admin-main,
    .content,
    .admin-content,
    .panel-content,
    .page-content,
    .workspace,
    section.main{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        margin:0!important;
        margin-left:0!important;
        padding:74px 12px 24px!important;
        overflow-x:hidden!important;
        box-sizing:border-box!important;
    }

    /* Nagłówki/karty */
    .topbar,
    .admin-topbar,
    .page-head,
    .page-header,
    .section-head,
    .toolbar{
        display:flex!important;
        flex-direction:column!important;
        align-items:stretch!important;
        justify-content:flex-start!important;
        gap:12px!important;
        width:100%!important;
        max-width:100%!important;
    }

    h1{
        font-size:clamp(1.35rem, 7vw, 2rem)!important;
        line-height:1.15!important;
        overflow-wrap:anywhere!important;
    }

    h2{
        font-size:clamp(1.15rem, 5.5vw, 1.55rem)!important;
        line-height:1.2!important;
    }

    .grid,
    .settings-grid,
    .cards-grid,
    .dashboard-grid,
    .stats-grid,
    .form-grid,
    .two-col,
    .three-col,
    .columns{
        display:grid!important;
        grid-template-columns:1fr!important;
        gap:14px!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
    }

    .card,
    .box,
    .panel,
    .panel-card,
    .settings-card,
    .module-card,
    .stat-card,
    .card.half,
    .card.third,
    .card.two,
    .card.three{
        grid-column:1 / -1!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        margin-left:0!important;
        margin-right:0!important;
        padding:15px!important;
        border-radius:18px!important;
        box-sizing:border-box!important;
        overflow:hidden!important;
    }

    /* Formularze */
    form,
    fieldset,
    .form-row,
    .form-group,
    .input-group,
    .settings-row{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        box-sizing:border-box!important;
    }

    label{
        max-width:100%!important;
        overflow-wrap:anywhere!important;
    }

    input,
    select,
    textarea{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        box-sizing:border-box!important;
    }

    textarea{
        min-height:150px!important;
    }

    .actions,
    .btn-row,
    .form-actions,
    .button-row,
    .toolbar-actions{
        display:flex!important;
        flex-wrap:wrap!important;
        align-items:stretch!important;
        gap:8px!important;
        width:100%!important;
        max-width:100%!important;
    }

    .btn,
    button,
    input[type="submit"],
    .button{
        max-width:100%!important;
        white-space:normal!important;
        overflow-wrap:anywhere!important;
    }

    /* Tabele jako poziomo przewijane */
    table{
        display:block!important;
        width:100%!important;
        max-width:100%!important;
        overflow-x:auto!important;
        -webkit-overflow-scrolling:touch!important;
        white-space:nowrap!important;
        border-collapse:separate!important;
    }

    thead,
    tbody,
    tr{
        width:100%!important;
    }

    th,
    td{
        white-space:nowrap!important;
    }

    /* Edytor TinyMCE */
    .tox,
    .tox-tinymce,
    .tox-editor-container,
    .tox-sidebar-wrap,
    .tox-edit-area{
        max-width:100%!important;
        width:100%!important;
        min-width:0!important;
        box-sizing:border-box!important;
    }

    .tox-toolbar,
    .tox-toolbar__primary,
    .tox-toolbar__overflow{
        flex-wrap:wrap!important;
    }

    /* Media, menu, slider */
    img,
    video,
    iframe{
        max-width:100%!important;
        height:auto;
    }

    .menu-row,
    .submenu-row,
    .slider-row,
    .media-row,
    .log-row,
    .preset-row{
        display:grid!important;
        grid-template-columns:1fr!important;
        gap:10px!important;
        width:100%!important;
        max-width:100%!important;
    }

    .media-grid,
    .gallery-grid,
    .uploads-grid,
    .preset-grid{
        display:grid!important;
        grid-template-columns:repeat(2, minmax(0, 1fr))!important;
        gap:10px!important;
    }
}

@media (max-width: 560px){
    .main,
    main.main,
    .admin-main,
    .content,
    .admin-content,
    .panel-content,
    .page-content,
    .workspace,
    section.main{
        padding-left:9px!important;
        padding-right:9px!important;
    }

    .card,
    .box,
    .panel,
    .panel-card,
    .settings-card,
    .module-card,
    .stat-card{
        padding:12px!important;
        border-radius:15px!important;
    }

    .actions .btn,
    .actions button,
    .form-actions .btn,
    .form-actions button,
    .btn-row .btn,
    .btn-row button{
        width:100%!important;
        justify-content:center!important;
        text-align:center!important;
    }

    .media-grid,
    .gallery-grid,
    .uploads-grid,
    .preset-grid{
        grid-template-columns:1fr!important;
    }
}
/* SPIDERCMS MOBILE HARD FIX END */



/* SPIDERCMS LIVE BUTTON FIX START */
.spidercms-live-edit-btn{
    background:linear-gradient(135deg,#14b8a6,#06b6d4)!important;
    color:#fff!important;
}
.spidercms-live-edit-btn:hover{
    filter:brightness(1.08);
    transform:translateY(-1px);
}
/* SPIDERCMS LIVE BUTTON FIX END */



/* SPIDERCMS SITE MENU SETTINGS UI START */
.site-menu-settings-panel{
    margin-top:1.2rem;
}
.site-menu-settings-panel .site-menu-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:1rem;
}
.site-menu-settings-panel .site-menu-grid label,
.site-menu-settings-panel .site-menu-cta-grid label{
    display:flex;
    flex-direction:column;
    gap:.45rem;
    min-width:0;
}
.site-menu-settings-panel input,
.site-menu-settings-panel select{
    width:100%;
    max-width:100%;
}
.site-menu-settings-panel .site-menu-checks{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:.75rem 1.2rem;
    margin:1rem 0;
}
.site-menu-settings-panel .site-menu-checks label{
    display:flex;
    align-items:center;
    gap:.55rem;
    color:#cbd5e1;
    min-width:0;
}
.site-menu-settings-panel .site-menu-checks input{
    width:auto;
    min-width:16px;
}
.site-menu-settings-panel .site-menu-cta-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:1rem;
}
@media(max-width:900px){
    .site-menu-settings-panel .site-menu-grid,
    .site-menu-settings-panel .site-menu-checks,
    .site-menu-settings-panel .site-menu-cta-grid{
        grid-template-columns:1fr;
    }
}
/* SPIDERCMS SITE MENU SETTINGS UI END */

</style>

<?php if (!empty($spidercms_is_page_edit_mode)): ?>
<style>
/* SPIDERCMS SERVER HIDE CREATE PAGE IN EDIT MODE */
.spidercms-create-page-only,
form[data-spidercms-purpose="create-page"],
.card[data-spidercms-purpose="create-page"],
section[data-spidercms-purpose="create-page"]{
    display:none!important;
}
</style>
<?php endif; ?>

</head>
<body>
<div class="spidercms-mobile-topbar">
    <button type="button" class="spidercms-mobile-menu-btn" id="spidercmsMobileMenuBtn" aria-label="Otwórz menu" aria-expanded="false">
        <i class="fa-solid fa-bars"></i> <span>Menu</span>
    </button>
    <div class="spidercms-mobile-brand">
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo">
        <?php endif; ?>
        <span>SpiderCMS</span>
    </div>
</div>
<div class="spidercms-sidebar-backdrop" id="spidercmsSidebarBackdrop"></div>
<aside id="sidebar">
    <div id="sidebar-header">
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo">
        <?php endif; ?>
        SpiderCMS
    </div>
    <nav>
        <div class="nav-section-title">Podgląd</div>
        <a href="/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Strona główna</a>

        <div class="nav-section-title">Treść</div>
        <a href="admin.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="admin.php?tab=strony" class="<?= $tab === 'strony' ? 'active' : '' ?>"><i class="fa-solid fa-file-lines"></i> Strony i edytor</a>
        <a href="admin.php?tab=media" class="<?= $tab === 'media' ? 'active' : '' ?>" style="color:#34d399;"><i class="fa-solid fa-images"></i> Biblioteka mediów</a>
        <a href="admin.php?tab=slider" class="<?= $tab === 'slider' ? 'active' : '' ?>" style="color:#fbbf24;"><i class="fa-solid fa-sliders"></i> Slider zdjęć</a>

        <div class="nav-section-title">Wygląd strony</div>
        <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active menu-tab' : 'menu-tab' ?>"><i class="fa-solid fa-bars"></i> Menu i podmenu</a>
        <a href="admin.php?tab=stopka" class="<?= $tab === 'stopka' ? 'active footer-tab' : 'footer-tab' ?>"><i class="fa-solid fa-shoe-prints"></i> Stopka</a>
        <a href="admin.php?tab=ustawienia&settings=general" class="<?= $tab === 'ustawienia' ? 'active settings-tab' : 'settings-tab' ?>"><i class="fa-solid fa-gear"></i> Ustawienia globalne</a>

        <div class="nav-section-title">Komunikacja</div>
        <a href="admin.php?tab=chat" class="<?= $tab === 'chat' ? 'active' : '' ?>" style="color:#22c55e;"><i class="fa-solid fa-comments"></i> Chat <?php if (($chat_unread ?? 0) > 0): ?><span class="chat-unread-badge"><?= (int)$chat_unread ?></span><?php endif; ?></a>
        <a href="admin.php?tab=ustawienia&settings=social" class="<?= ($tab === 'ustawienia' && ($_GET['settings'] ?? '') === 'social') ? 'active settings-tab' : 'settings-tab' ?>"><i class="fa-solid fa-share-nodes"></i> Social Media</a>

        <div class="nav-section-title">Analityka i system</div>
        <a href="admin.php?tab=statystyki" class="<?= $tab === 'statystyki' ? 'active' : '' ?>" style="color:#38bdf8;"><i class="fa-solid fa-chart-line"></i> Statystyki</a>
        <a href="admin.php?tab=logi" class="<?= $tab === 'logi' ? 'active' : '' ?>" style="color:#f97316;"><i class="fa-solid fa-list-check"></i> Logi akcji</a>
        <a href="admin.php?tab=ustawienia&settings=security" class="<?= ($tab === 'ustawienia' && ($_GET['settings'] ?? '') === 'security') ? 'active settings-tab' : 'settings-tab' ?>"><i class="fa-solid fa-shield-halved"></i> Bezpieczeństwo</a>
        <a href="admin.php?tab=o-cms" class="<?= $tab === 'o-cms' ? 'active about-tab' : 'about-tab' ?>"><i class="fa-solid fa-info-circle"></i> O CMS</a>
        <a href="?logout=1"><i class="fa-solid fa-right-from-bracket"></i> Wyloguj</a>
    </nav>
</aside>
<main id="main">
    <header>
        <h1>
            <?php
            switch ($tab) {
                case 'dashboard': echo 'Dashboard'; break;
                case 'menu': echo 'Konfiguracja górnego menu'; break;
                case 'stopka': echo 'Konfiguracja stopki witryny'; break;
                case 'ustawienia': echo 'Ustawienia witryny'; break;
                case 'chat': echo 'Chat z odwiedzającymi'; break;
                case 'slider': echo 'Slider zdjęć i shortcode'; break;
                case 'statystyki': echo 'Statystyki odwiedzin'; break;
                case 'logi': echo 'Logi akcji systemu'; break;
                case 'uzytkownicy': echo 'Użytkownicy i role'; break;
                case 'o-cms': echo 'O tym CMS-ie'; break;
                default: echo 'Zarządzanie stronami';
            }
            ?>
        </h1>
        <?php if ($tab === 'strony' || $tab === 'dashboard'): ?>
        <a href="/" target="_blank" style="background:var(--success);color:white;padding:0.75rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600;display:flex;align-items:center;gap:0.5rem;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Zobacz witrynę
        </a>
        <?php endif; ?>
    </header>
    <?php if ($toast['msg']): ?>
        <div class="toast <?= $toast['type'] ?>"><?= htmlspecialchars($toast['msg']) ?></div>
    <?php endif; ?>
    <?php if ($tab === 'dashboard'): ?>
        <div class="dashboard-grid">
            <div class="dash-card">
                <h3>Liczba stron</h3>
                <div class="dash-number"><?= count($pages) ?></div>
                <p style="color:#94a3b8;">w tym strona główna</p>
            </div>
            <div class="dash-card">
                <h3>Ostatnia modyfikacja</h3>
                <?php
                $last_modified = 'Brak stron';
                $last_date = 0;
                foreach ($pages as $p) {
                    $time = @filemtime(ACTIVE_PAGES_DIR . '/' . $p['slug'] . '.php');
                    if ($time > $last_date) {
                        $last_date = $time;
                        $last_modified = date('d.m.Y H:i', $time) . ' – ' . $p['slug'];
                    }
                }
                ?>
                <div style="font-size:1.3rem; font-weight:600; color:#f8fafc;"><?= $last_modified ?></div>
            </div>
            <div class="dash-card">
                <h3>Menu nawigacyjne</h3>
                <div style="font-size:1.4rem; margin:0.8rem 0;">
                    <?= $menu_enabled ? '<span style="color:var(--success);">WŁĄCZONE</span>' : '<span style="color:var(--danger);">WYŁĄCZONE</span>' ?>
                </div>
                <p style="color:#94a3b8;"><?= count($menu_items) ?> pozycji</p>
            </div>
            <div class="dash-card">
                <h3>Stopka</h3>
                <div style="font-size:1.4rem; margin:0.8rem 0;">
                    <?= $footer_enabled ? '<span style="color:var(--success);">WŁĄCZONA</span>' : '<span style="color:var(--danger);">WYŁĄCZONA</span>' ?>
                </div>
            </div>
            <div class="dash-card">
                <h3>Chat</h3>
                <div style="font-size:1.4rem; margin:0.8rem 0;">
                    <?= !empty($chat_settings['enabled']) ? '<span style="color:var(--success);">WŁĄCZONY</span>' : '<span style="color:var(--danger);">WYŁĄCZONY</span>' ?>
                </div>
                <p style="color:#94a3b8;"><?= count($chat_conversations ?? []) ?> rozmów, <?= (int)($chat_unread ?? 0) ?> nowych</p>
            </div>
            <div class="dash-card spidercms-create-page-only" data-spidercms-purpose="create-page">
                <h3>Szybkie akcje</h3>
                <div style="margin-top:1rem; display:flex; flex-direction:column; gap:0.8rem;">
                    <a href="admin.php?tab=strony" class="btn btn-view" style="text-align:center; justify-content:center;">
                        <i class="fa-solid fa-plus"></i> Dodaj nową stronę
                    </a>
                    <a href="admin.php?tab=ustawienia" class="btn btn-edit" style="text-align:center; justify-content:center;">
                        <i class="fa-solid fa-palette"></i> Zmień kolory / logo
                    </a>
                    <a href="admin.php?tab=uzytkownicy" class="btn btn-edit" style="text-align:center; justify-content:center;">
                        <i class="fa-solid fa-users-gear"></i> Użytkownicy
                    </a>

                </div>
            </div>
        </div>

    <?php elseif ($tab === 'uzytkownicy'): ?>
        <?php if (!spidercms_admin_has_role(['admin'])): ?>
            <div class="toast error">Brak uprawnień. Tylko Administrator może zarządzać użytkownikami.</div>
        <?php else: ?>
            <?= spidercms_admin_users_tab_html() ?>
        <?php endif; ?>
    <?php elseif ($tab === 'stopka'): ?>
        <div class="card">
            <h2 style="color:var(--footer-color); margin-bottom: 1.5rem;"><i class="fa-solid fa-shoe-prints"></i> Konfiguracja stopki (Footer)</h2>
            <p style="color: #94a3b8; font-size: 0.95rem; margin-bottom: 1rem; background: rgba(16,185,129,0.1); border-left: 3px solid var(--success); padding: 0.6rem 1rem; border-radius: 4px;">
                <i class="fa-solid fa-circle-info"></i> Każda zmiana w tym formularzu zostanie natychmiast zastosowana na <strong>wszystkich</strong> podstronach serwisu.
            </p>
            <form method="post">
                <input type="hidden" name="action" value="save_footer">
                <label style="display:flex;align-items:center;gap:0.8rem;font-size:1.1rem;margin:1rem 0 1.5rem;">
                    <input type="checkbox" name="footer_enabled" <?= $footer_enabled ? 'checked' : '' ?> style="width:auto;transform:scale(1.3);">
                    <strong>Włącz stopkę na wszystkich stronach</strong>
                </label>
                <label>Prawa autorskie (Copyright)</label>
                <input type="text" name="footer_copyright" value="<?= htmlspecialchars($footer_data['copyright'] ?? '') ?>" placeholder="np. © 2026 SpiderCMS. Wszystkie prawa zastrzeżone.">
                <label>Opis w pierwszej kolumnie (O nas)</label>
                <textarea name="footer_about_text" class="input-field" rows="3" placeholder="Krótki tekst o Twojej firmie..."><?= htmlspecialchars($footer_data['about_text'] ?? '') ?></textarea>
                <div style="margin-top:1.5rem; padding:1rem; border:1px solid var(--gray-200); border-radius:10px; background:#0f172a;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                        <div>
                            <h3 style="color: var(--primary); font-size: 1.1rem; margin:0;">Dodatkowe kolumny stopki</h3>
                            <p style="color:#94a3b8;margin:0.35rem 0 0;font-size:0.92rem;">Możesz dodać np. Kontakt, Linki, Godziny otwarcia, Social Media, Usługi, Lokalizację itd.</p>
                        </div>
                        <button type="button" id="add-footer-column" class="btn btn-edit" style="border:none;cursor:pointer;"><i class="fa-solid fa-plus"></i> Dodaj kolumnę</button>
                    </div>
                    <div id="footer-columns-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
                        <?php foreach (($footer_data['columns'] ?? []) as $idx => $column): ?>
                            <div class="footer-column-item" style="border:1px solid var(--gray-200);border-radius:10px;padding:1rem;background:#1e293b;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.7rem;">
                                    <h4 style="margin:0;color:#f8fafc;">Kolumna <?= (int)$idx + 2 ?></h4>
                                    <button type="button" class="remove-footer-column" style="background:#ef4444;color:white;border:none;border-radius:6px;padding:0.45rem 0.65rem;cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
                                </div>
                                <label>Tytuł kolumny</label>
                                <input type="text" name="footer_col_title[]" value="<?= htmlspecialchars($column['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="np. Kontakt">
                                <label>Zawartość HTML / Tekst</label>
                                <textarea name="footer_col_content[]" class="input-field" rows="4" placeholder="np. Email: biuro@example.com"><?= htmlspecialchars($column['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <template id="footer-column-template">
                        <div class="footer-column-item" style="border:1px solid var(--gray-200);border-radius:10px;padding:1rem;background:#1e293b;">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.7rem;">
                                <h4 style="margin:0;color:#f8fafc;">Nowa kolumna</h4>
                                <button type="button" class="remove-footer-column" style="background:#ef4444;color:white;border:none;border-radius:6px;padding:0.45rem 0.65rem;cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
                            </div>
                            <label>Tytuł kolumny</label>
                            <input type="text" name="footer_col_title[]" value="" placeholder="np. Kontakt">
                            <label>Zawartość HTML / Tekst</label>
                            <textarea name="footer_col_content[]" class="input-field" rows="4" placeholder="np. Email: biuro@example.com"></textarea>
                        </div>
                    </template>
                </div>
                <div style="margin-top:2rem;"><button type="submit">Zapisz ustawienia stopki</button></div>
            </form>
        </div>

    <?php elseif ($tab === 'slider'): ?>
        <?php
        $slider_form = $edit_slider ?: [
            'id' => '', 'name' => '', 'style' => 'modern', 'fit_mode' => 'contain', 'height' => 420, 'autoplay' => '1', 'interval' => 4500,
            'arrows' => '1', 'dots' => '1', 'overlay' => '1', 'radius' => 18, 'images' => []
        ];
        ?>
        <div class="card">
            <h2 style="color:#fbbf24;"><i class="fa-solid fa-sliders"></i> Generator slidera ze zdjęć</h2>
            <p style="color:#94a3b8;margin-top:.25rem;">Utwórz stylowy slider, zapisz go i wklej shortcode w treści strony, np. <code>[slider id="hero"]</code>.</p>
            <form method="post" id="slider-form" style="margin-top:1.25rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_slider">
                <input type="hidden" name="old_slider_id" value="<?= htmlspecialchars($slider_form['id'] ?? '') ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                    <div>
                        <label>Nazwa slidera</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($slider_form['name'] ?? '') ?>" placeholder="np. Slider główny" required>
                    </div>
                    <div>
                        <label>ID / shortcode</label>
                        <input type="text" name="id" value="<?= htmlspecialchars($slider_form['id'] ?? '') ?>" placeholder="np. hero" required>
                        <small style="color:#94a3b8;">Użycie: <code>[slider id="<?= htmlspecialchars($slider_form['id'] ?: 'hero') ?>"]</code></small>
                    </div>
                    <div>
                        <label>Styl</label>
                        <select name="style">
                            <?php foreach (['modern'=>'Nowoczesny','glass'=>'Glass','minimal'=>'Minimalny','dark'=>'Ciemny','cards'=>'Karty'] as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= (($slider_form['style'] ?? 'modern') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Dopasowanie zdjęć</label>
                        <select name="fit_mode">
                            <?php foreach (['contain'=>'Dopasuj całe zdjęcie bez rozciągania','cover'=>'Wypełnij kadr, przytnij brzegi','auto'=>'Oryginalny rozmiar, maksymalnie do okna'] as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= (($slider_form['fit_mode'] ?? 'contain') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#94a3b8;display:block;margin-top:.25rem;">Tryb „Dopasuj” jest najlepszy dla małych obrazów i różnych proporcji zdjęć.</small>
                    </div>
                    <div>
                        <label>Wysokość slidera [px]</label>
                        <input type="number" name="height" min="180" max="900" value="<?= (int)($slider_form['height'] ?? 420) ?>">
                    </div>
                    <div>
                        <label>Zaokrąglenie [px]</label>
                        <input type="number" name="radius" min="0" max="40" value="<?= (int)($slider_form['radius'] ?? 18) ?>">
                    </div>
                    <div>
                        <label>Czas slajdu [ms]</label>
                        <input type="number" name="interval" min="1500" max="20000" step="500" value="<?= (int)($slider_form['interval'] ?? 4500) ?>">
                    </div>
                </div>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0;">
                    <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="autoplay" value="1" <?= (($slider_form['autoplay'] ?? '1') === '1') ? 'checked' : '' ?>> Autoplay</label>
                    <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="arrows" value="1" <?= (($slider_form['arrows'] ?? '1') === '1') ? 'checked' : '' ?>> Strzałki</label>
                    <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="dots" value="1" <?= (($slider_form['dots'] ?? '1') === '1') ? 'checked' : '' ?>> Kropki</label>
                    <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="overlay" value="1" <?= (($slider_form['overlay'] ?? '1') === '1') ? 'checked' : '' ?>> Ciemny gradient pod tekstem</label>
                </div>
                <h3 style="margin-top:1.5rem;">Zdjęcia w sliderze</h3>
                <?php
                $slider_gallery_images = array_values(array_filter($media_files ?? [], function($f) {
                    return isset($f['ext']) && in_array(strtolower((string)$f['ext']), ['jpg','jpeg','png','gif','webp','svg'], true);
                }));
                ?>
                <div style="margin:.75rem 0 1rem;padding:1rem;border:1px solid #334155;border-radius:12px;background:#0f172a;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:.8rem;">
                        <div>
                            <strong style="color:#f8fafc;">Wybierz zdjęcie z galerii</strong>
                            <div style="color:#94a3b8;font-size:.9rem;margin-top:.2rem;">Kliknij miniaturę albo przycisk „Dodaj”, aby dopisać zdjęcie do slidera.</div>
                        </div>
                        <a href="admin.php?tab=media" style="color:#93c5fd;text-decoration:none;font-weight:700;">Przejdź do biblioteki mediów</a>
                    </div>
                    <?php if (empty($slider_gallery_images)): ?>
                        <p style="color:#94a3b8;margin:0;">Brak obrazów w galerii. Najpierw wgraj zdjęcia w zakładce <strong>Media</strong>.</p>
                    <?php else: ?>
                        <div class="slider-gallery-picker" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.75rem;max-height:330px;overflow:auto;padding-right:.25rem;">
                            <?php foreach ($slider_gallery_images as $gf): ?>
                                <button type="button" class="slider-gallery-add" data-url="<?= htmlspecialchars($gf['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Dodaj <?= htmlspecialchars($gf['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="border:1px solid #334155;background:#1e293b;color:#e5e7eb;border-radius:10px;padding:.45rem;cursor:pointer;text-align:left;overflow:hidden;">
                                    <img src="<?= htmlspecialchars($gf['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($gf['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;height:82px;object-fit:cover;border-radius:7px;display:block;margin-bottom:.45rem;">
                                    <span style="display:block;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($gf['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span style="display:inline-flex;margin-top:.35rem;background:#2563eb;color:white;border-radius:999px;padding:.2rem .5rem;font-size:.75rem;font-weight:800;">+ Dodaj</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div id="slider-images-list" style="display:grid;gap:.9rem;">
                    <?php $imgs = $slider_form['images'] ?? []; if (empty($imgs)) $imgs = [['url'=>'','title'=>'','desc'=>'']]; ?>
                    <?php foreach ($imgs as $img): ?>
                        <div class="slider-image-row" style="display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:.7rem;align-items:end;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1rem;">
                            <div><label>Adres zdjęcia</label><input type="text" name="image_url[]" value="<?= htmlspecialchars($img['url'] ?? '') ?>" placeholder="uploads/zdjecie.jpg"></div>
                            <div><label>Tytuł</label><input type="text" name="image_title[]" value="<?= htmlspecialchars($img['title'] ?? '') ?>" placeholder="Opcjonalnie"></div>
                            <div><label>Opis</label><input type="text" name="image_desc[]" value="<?= htmlspecialchars($img['desc'] ?? '') ?>" placeholder="Opcjonalnie"></div>
                            <button type="button" class="remove-slider-image" style="background:#ef4444;color:white;border:0;border-radius:8px;padding:.75rem;cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;">
                    <button type="button" id="add-slider-image" style="background:#334155;"><i class="fa-solid fa-plus"></i> Dodaj zdjęcie</button>
                    <button type="submit"><i class="fa-solid fa-save"></i> Zapisz slider</button>
                    <?php if (!empty($slider_form['id'])): ?>
                        <button type="button" onclick="copyUrl('[slider id=&quot;<?= htmlspecialchars($slider_form['id']) ?>&quot;]')" style="background:#2563eb;"><i class="fa-solid fa-copy"></i> Kopiuj shortcode</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card" style="margin-top:1.25rem;">
            <h2>Utworzone slidery</h2>
            <?php if (empty($sliders)): ?>
                <p style="color:#94a3b8;">Nie utworzono jeszcze żadnego slidera.</p>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead><tr><th>Nazwa</th><th>Shortcode</th><th>Zdjęcia</th><th>Styl</th><th>Akcje</th></tr></thead>
                        <tbody>
                            <?php foreach ($sliders as $sid => $sl): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sl['name'] ?? $sid) ?></td>
                                    <td><code>[slider id="<?= htmlspecialchars($sid) ?>"]</code></td>
                                    <td><?= count($sl['images'] ?? []) ?></td>
                                    <td><?= htmlspecialchars($sl['style'] ?? 'modern') ?></td>
                                    <td style="display:flex;gap:.45rem;flex-wrap:wrap;">
                                        <a href="admin.php?tab=slider&edit_slider=<?= urlencode($sid) ?>" style="background:#2563eb;color:white;padding:.45rem .7rem;border-radius:6px;text-decoration:none;">Edytuj</a>
                                        <button type="button" onclick="copyUrl('[slider id=&quot;<?= htmlspecialchars($sid) ?>&quot;]')" style="background:#334155;color:white;border:0;padding:.45rem .7rem;border-radius:6px;">Kopiuj</button>
                                        <form method="post" onsubmit="return confirm('Usunąć slider?');" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_slider">
                                            <input type="hidden" name="slider_id" value="<?= htmlspecialchars($sid) ?>">
                                            <button type="submit" style="background:#ef4444;color:white;border:0;padding:.45rem .7rem;border-radius:6px;">Usuń</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

	<?php elseif ($tab === 'media'): ?>
    <div class="card">
        <h2 style="color:#34d399;"><i class="fa-solid fa-images"></i> Biblioteka Mediów (<?= count($media_files) ?> plików)</h2>
        
        <!-- Formularz uploadu -->
        <form method="post" enctype="multipart/form-data" style="margin:20px 0 30px;">
            <input type="hidden" name="action" value="upload_media">
            <input type="file" name="media_files[]" multiple accept="image/*,.pdf,.doc,.docx,video/*" style="margin-bottom:10px;">
            <button type="submit">📤 Wgraj pliki</button>
        </form>

        <input type="text" id="media-search" placeholder="🔍 Szukaj plików..." style="width:100%;padding:12px;margin-bottom:20px;border-radius:6px;">

        <div class="media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 1.2rem;">
            <?php foreach ($media_files as $f): ?>
            <div class="media-item" style="background:#1e293b; border:1px solid #334155; border-radius:10px; overflow:hidden;">
                <div style="height:160px; background:#0f172a; display:flex; align-items:center; justify-content:center;">
                    <?php if (in_array($f['ext'], ['jpg','jpeg','png','gif','webp','svg'])): ?>
                        <img src="<?= htmlspecialchars($f['url']) ?>" style="width:100%; height:100%; object-fit:cover;" alt="<?= htmlspecialchars($f['name']) ?>">
                    <?php else: ?>
                        <i class="fa-solid fa-file fa-4x" style="color:#64748b;"></i>
                    <?php endif; ?>
                </div>
                <div style="padding:12px;">
                    <div style="font-weight:600; word-break:break-all; font-size:0.95rem;"><?= htmlspecialchars($f['name']) ?></div>
                    <small style="color:#94a3b8;"><?= round($f['size']/1024, 1) ?> KB • <?= $f['modified'] ?></small>
                    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                        <button onclick="copyUrl('<?= htmlspecialchars($f['url']) ?>')" style="background:#3b82f6; color:white; border:none; padding:6px 12px; border-radius:5px; cursor:pointer; font-size:0.9rem;">📋 Kopiuj URL</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Na pewno usunąć ten plik?');">
                            <input type="hidden" name="action" value="delete_media">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($f['name']) ?>">
                            <button type="submit" style="background:#ef4444; color:white; border:none; padding:6px 12px; border-radius:5px; cursor:pointer; font-size:0.9rem;">🗑️ Usuń</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
		
    <?php elseif ($tab === 'chat'): ?>
        <?php
        uasort($chat_conversations, function($a, $b) {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });
        $selected_chat_id = $_GET['conversation'] ?? '';
        if (!chat_valid_conversation_id($selected_chat_id)) { $selected_chat_id = ''; }
        if ($selected_chat_id === '' && !empty($chat_conversations)) {
            $keys = array_keys($chat_conversations);
            $selected_chat_id = $keys[0];
        }
        $selected_chat = $selected_chat_id && isset($chat_conversations[$selected_chat_id]) ? $chat_conversations[$selected_chat_id] : null;
        ?>
        <div class="card chat-admin-card">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <h2 style="color:var(--chat-color);margin-top:0;"><i class="fa-solid fa-comments"></i> Chat z odwiedzającymi</h2>
                    <p style="color:#94a3b8;margin:0.4rem 0 0;max-width:860px;line-height:1.55;">Ustawienia czatu zostały podzielone na proste sekcje: widoczność, teksty widżetu, powiadomienia e-mail oraz SMTP dla home.pl.</p>
                </div>
                <span style="background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#bbf7d0;border-radius:999px;padding:.55rem .8rem;font-weight:800;">
                    <?= !empty($chat_settings['enabled']) ? 'Chat włączony' : 'Chat wyłączony' ?>
                </span>
            </div>

            <form method="post" class="chat-settings-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_chat_settings">

                <div class="chat-settings-wrap">
                    <section class="chat-settings-card">
                        <h3><i class="fa-solid fa-toggle-on"></i> Status czatu</h3>
                        <p class="mini-help">Włącz lub wyłącz widżet czatu na publicznych stronach.</p>
                        <label class="chat-check">
                            <input type="checkbox" name="chat_enabled" <?= !empty($chat_settings['enabled']) ? 'checked' : '' ?>>
                            <strong>Włącz chat na stronie</strong>
                        </label>
                    </section>

                    <section class="chat-settings-card">
                        <h3><i class="fa-solid fa-user-shield"></i> Administrator</h3>
                        <p class="mini-help">Nazwa widoczna przy odpowiedziach administratora.</p>
                        <div class="chat-fields-grid">
                            <div>
                                <label style="margin-top:0;">Nazwa administratora</label>
                                <input type="text" name="chat_admin_name" value="<?= htmlspecialchars($chat_settings['admin_name'] ?? '') ?>">
                            </div>
                        </div>
                    </section>

                    <section class="chat-settings-card wide">
                        <h3><i class="fa-solid fa-message"></i> Treść widżetu</h3>
                        <p class="mini-help">Teksty widoczne dla odwiedzającego w oknie czatu.</p>
                        <div class="chat-fields-grid">
                            <div><label style="margin-top:0;">Tytuł</label><input type="text" name="chat_title" value="<?= htmlspecialchars($chat_settings['title'] ?? '') ?>"></div>
                            <div><label style="margin-top:0;">Podtytuł</label><input type="text" name="chat_subtitle" value="<?= htmlspecialchars($chat_settings['subtitle'] ?? '') ?>"></div>
                            <div><label style="margin-top:0;">Tekst przycisku</label><input type="text" name="chat_button_text" value="<?= htmlspecialchars($chat_settings['button_text'] ?? '') ?>"></div>
                            <div><label style="margin-top:0;">Wiadomość powitalna</label><input type="text" name="chat_welcome" value="<?= htmlspecialchars($chat_settings['welcome'] ?? '') ?>"></div>
                        </div>
                    </section>

                    <section class="chat-settings-card wide">
                        <h3><i class="fa-solid fa-envelope-circle-check"></i> Powiadomienia e-mail</h3>
                        <p class="mini-help">Wysyłaj powiadomienie, gdy użytkownik napisze nową wiadomość. Na home.pl zalecany jest SMTP.</p>
                        <div class="chat-fields-grid">
                            <label class="chat-check">
                                <input type="checkbox" name="chat_email_notifications" <?= (($chat_settings['email_notifications'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <strong>Powiadamiaj e-mailem</strong>
                            </label>
                            <div><label style="margin-top:0;">E-mail administratora</label><input type="email" name="chat_admin_email" value="<?= htmlspecialchars($chat_settings['admin_email'] ?? '') ?>" placeholder="np. kontakt@twojadomena.pl"></div>
                            <div><label style="margin-top:0;">E-mail nadawcy / From</label><input type="email" name="chat_from_email" value="<?= htmlspecialchars($chat_settings['from_email'] ?? '') ?>" placeholder="np. kontakt@twojadomena.pl"></div>
                            <div>
                                <label style="margin-top:0;">Sposób wysyłki</label>
                                <select name="chat_mail_method">
                                    <option value="smtp" <?= (($chat_settings['mail_method'] ?? 'smtp') === 'smtp') ? 'selected' : '' ?>>SMTP, zalecane dla home.pl</option>
                                    <option value="mail" <?= (($chat_settings['mail_method'] ?? 'smtp') === 'mail') ? 'selected' : '' ?>>PHP mail(), awaryjnie</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="chat-settings-card wide">
                        <h3><i class="fa-solid fa-server"></i> SMTP home.pl / serwer pocztowy</h3>
                        <p class="mini-help">Login powinien być pełnym adresem skrzynki. Hasło zostaw puste, jeśli nie chcesz go zmieniać.</p>
                        <div class="chat-fields-grid">
                            <div><label style="margin-top:0;">SMTP host</label><input type="text" name="chat_smtp_host" value="<?= htmlspecialchars($chat_settings['smtp_host'] ?? '') ?>" placeholder="serwer SMTP z panelu home.pl"></div>
                            <div><label style="margin-top:0;">SMTP port</label><input type="text" name="chat_smtp_port" value="<?= htmlspecialchars($chat_settings['smtp_port'] ?? '465') ?>" placeholder="465"></div>
                            <div>
                                <label style="margin-top:0;">Szyfrowanie SMTP</label>
                                <select name="chat_smtp_secure">
                                    <option value="ssl" <?= (($chat_settings['smtp_secure'] ?? 'ssl') === 'ssl') ? 'selected' : '' ?>>SSL, zwykle port 465</option>
                                    <option value="tls" <?= (($chat_settings['smtp_secure'] ?? 'ssl') === 'tls') ? 'selected' : '' ?>>TLS/STARTTLS, zwykle port 587</option>
                                    <option value="none" <?= (($chat_settings['smtp_secure'] ?? 'ssl') === 'none') ? 'selected' : '' ?>>Brak szyfrowania</option>
                                </select>
                            </div>
                            <div><label style="margin-top:0;">SMTP login</label><input type="text" name="chat_smtp_username" value="<?= htmlspecialchars($chat_settings['smtp_username'] ?? '') ?>" placeholder="pełny adres e-mail"></div>
                            <div><label style="margin-top:0;">SMTP hasło</label><input type="password" name="chat_smtp_password" value="" placeholder="zostaw puste, aby nie zmieniać"></div>
                        </div>
                        <p class="mini-help" style="margin-top:.8rem;color:#fbbf24;">Wyniki testów i błędy wysyłki zapisują się w <code>.chat/email.log</code>.</p>
                    </section>
                </div>

                <div class="chat-actions">
                    <button type="submit" onclick="this.form.action.value='save_chat_settings'"><i class="fa-solid fa-save"></i> Zapisz ustawienia czatu</button>
                    <button type="submit" onclick="this.form.action.value='test_chat_email'" style="background:#2563eb;"><i class="fa-solid fa-paper-plane"></i> Wyślij test e-mail</button>
                </div>
            </form>
        </div>
        <div class="chat-layout">
            <div class="card">
                <h3 style="margin-top:0;color:var(--chat-color);">Rozmowy</h3>
                <div class="chat-list">
                    <?php if (empty($chat_conversations)): ?>
                        <p style="color:#94a3b8;">Brak rozmów. Po wysłaniu wiadomości z publicznej strony pojawi się tutaj nowy wątek.</p>
                    <?php endif; ?>
                    <?php foreach ($chat_conversations as $cid => $conversation): ?>
                        <?php if (($conversation['status'] ?? 'open') === 'archived') continue; ?>
                        <a class="chat-thread-link <?= $cid === $selected_chat_id ? 'active' : '' ?>" href="admin.php?tab=chat&conversation=<?= urlencode($cid) ?>">
                            <strong><?= htmlspecialchars($conversation['name'] ?? 'Gość strony') ?></strong>
                            <?php if (!empty($conversation['unread_admin'])): ?><span class="chat-unread-badge"><?= (int)$conversation['unread_admin'] ?></span><?php endif; ?>
                            <div class="chat-thread-meta"><span><?= htmlspecialchars($conversation['email'] ?? '') ?></span><span><?= htmlspecialchars($conversation['updated_at'] ?? '') ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <?php if ($selected_chat): ?>
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem;">
                        <div>
                            <h3 style="margin:0;color:#f8fafc;">Rozmowa: <?= htmlspecialchars($selected_chat['name'] ?? 'Gość strony') ?></h3>
                            <p style="color:#94a3b8;margin:0.35rem 0 0;">Email: <?= htmlspecialchars($selected_chat['email'] ?? 'brak') ?> | Start: <?= htmlspecialchars($selected_chat['created_at'] ?? '') ?></p>
                        </div>
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end;">
                            <form method="post"><input type="hidden" name="action" value="chat_mark_read"><input type="hidden" name="conversation_id" value="<?= htmlspecialchars($selected_chat_id) ?>"><button type="submit" class="btn btn-edit">Oznacz jako przeczytane</button></form>
                            <form method="post"><input type="hidden" name="action" value="chat_archive"><input type="hidden" name="conversation_id" value="<?= htmlspecialchars($selected_chat_id) ?>"><button type="submit" class="btn btn-export">Archiwizuj</button></form>
                            <form method="post" onsubmit="return confirm('Usunąć rozmowę bezpowrotnie?');"><input type="hidden" name="action" value="chat_delete"><input type="hidden" name="conversation_id" value="<?= htmlspecialchars($selected_chat_id) ?>"><button type="submit" class="btn btn-delete">Usuń</button></form>
                        </div>
                    </div>
                    <div class="chat-messages">
                        <?php foreach (($selected_chat['messages'] ?? []) as $msg): ?>
                            <div class="chat-bubble <?= ($msg['from'] ?? '') === 'admin' ? 'admin' : 'user' ?>">
                                <?= nl2br(htmlspecialchars($msg['body'] ?? '')) ?>
                                <span class="chat-time"><?= htmlspecialchars($msg['time'] ?? '') ?> • <?= ($msg['from'] ?? '') === 'admin' ? htmlspecialchars($chat_settings['admin_name'] ?? 'Administrator') : 'Użytkownik' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" style="margin-top:1rem;">
                        <input type="hidden" name="action" value="chat_reply">
                        <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($selected_chat_id) ?>">
                        <label>Odpowiedź administratora</label>
                        <textarea name="reply" class="input-field" rows="4" placeholder="Napisz odpowiedź..." required></textarea>
                        <div style="margin-top:1rem;"><button type="submit"><i class="fa-solid fa-paper-plane"></i> Wyślij odpowiedź</button></div>
                    </form>
                <?php else: ?>
                    <h3 style="margin-top:0;color:#f8fafc;">Nie wybrano rozmowy</h3>
                    <p style="color:#94a3b8;">Po otrzymaniu pierwszej wiadomości od odwiedzającego będzie można tutaj odpisać.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $selected_archive_id = $_GET['archive_conversation'] ?? '';
        if (!chat_valid_conversation_id($selected_archive_id)) { $selected_archive_id = ''; }
        $archive_messages = $selected_archive_id !== '' ? chat_load_archive_messages($selected_archive_id, 1000) : [];
        uasort($chat_archive_index, function($a, $b) { return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''); });
        ?>
        <div class="card">
            <h3 style="margin-top:0;color:var(--chat-color);"><i class="fa-solid fa-box-archive"></i> Archiwum rozmów</h3>
            <p style="color:#94a3b8;margin:0.4rem 0 1rem;">To jest trwałe archiwum. Wiadomości są dopisywane linia po linii do pliku <code>.chat/archive.jsonl</code>, więc nie znikają po zarchiwizowaniu rozmowy aktywnej.</p>
            <div style="display:grid;grid-template-columns:minmax(260px,360px) 1fr;gap:1rem;align-items:start;">
                <div class="chat-list" style="max-height:420px;overflow:auto;">
                    <?php if (empty($chat_archive_index)): ?>
                        <p style="color:#94a3b8;">Archiwum jest jeszcze puste.</p>
                    <?php endif; ?>
                    <?php foreach ($chat_archive_index as $acid => $ainfo): ?>
                        <a class="chat-thread-link <?= $acid === $selected_archive_id ? 'active' : '' ?>" href="admin.php?tab=chat&archive_conversation=<?= urlencode($acid) ?>">
                            <strong><?= htmlspecialchars($ainfo['name'] ?? 'Gość strony') ?></strong>
                            <div class="chat-thread-meta"><span><?= htmlspecialchars($ainfo['email'] ?? '') ?></span><span><?= (int)($ainfo['messages_count'] ?? 0) ?> wiadomości</span></div>
                            <div class="chat-thread-meta"><span><?= htmlspecialchars($ainfo['created_at'] ?? '') ?></span><span><?= htmlspecialchars($ainfo['updated_at'] ?? '') ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="chat-messages" style="max-height:420px;">
                    <?php if ($selected_archive_id === ''): ?>
                        <p style="color:#94a3b8;">Wybierz rozmowę z archiwum, aby zobaczyć pełną historię wiadomości.</p>
                    <?php elseif (empty($archive_messages)): ?>
                        <p style="color:#94a3b8;">Brak wiadomości w wybranym archiwum.</p>
                    <?php else: ?>
                        <?php foreach ($archive_messages as $msg): ?>
                            <div class="chat-bubble <?= ($msg['from'] ?? '') === 'admin' ? 'admin' : 'user' ?>">
                                <?= nl2br(htmlspecialchars($msg['body'] ?? '')) ?>
                                <span class="chat-time"><?= htmlspecialchars($msg['time'] ?? '') ?> • <?= ($msg['from'] ?? '') === 'admin' ? htmlspecialchars($chat_settings['admin_name'] ?? 'Administrator') : 'Użytkownik' ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>


    <?php elseif ($tab === 'statystyki'): ?>
        <?php $stats = stats_get_summary(); ?>
        <div class="stats-grid">
            <div class="stats-card"><h3>Dzisiaj</h3><div class="stats-number"><?= (int)($stats['daily'][$stats['today']] ?? 0) ?></div><span class="stats-pill"><?= (int)$stats['unique_today'] ?> unikalnych</span></div>
            <div class="stats-card"><h3>Wczoraj</h3><div class="stats-number"><?= (int)($stats['daily'][$stats['yesterday']] ?? 0) ?></div></div>
            <div class="stats-card"><h3>Ostatnie 7 dni</h3><div class="stats-number"><?= (int)$stats['last7'] ?></div></div>
            <div class="stats-card"><h3>Ten miesiąc</h3><div class="stats-number"><?= (int)$stats['month'] ?></div></div>
            <div class="stats-card"><h3>Łącznie</h3><div class="stats-number"><?= (int)$stats['total'] ?></div></div>
            <div class="stats-card"><h3>Online</h3><div class="stats-number"><?= count($stats['online']) ?></div><span class="stats-pill">ostatnie <?= (int)($stats_settings['online_minutes'] ?? 5) ?> min</span></div>
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <h2><i class="fa-solid fa-chart-simple"></i> Ostatnie 30 dni</h2>
            <?php $maxv = max(1, max($stats['last30'] ?: [1])); ?>
            <div class="stats-chart">
                <?php foreach ($stats['last30'] as $day => $val): ?>
                    <div class="stats-bar" title="<?= e($day) ?>: <?= (int)$val ?>" style="height:<?= max(3, round(((int)$val / $maxv) * 100)) ?>%;"><span><?= date('d.m', strtotime($day)) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <h2><i class="fa-solid fa-sliders"></i> Ustawienia statystyk</h2>
            <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;align-items:end;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_stats_settings">
                <label style="display:flex;gap:.6rem;align-items:center;"><input type="checkbox" name="stats_enabled" <?= ($stats_settings['enabled'] ?? '1') === '1' ? 'checked' : '' ?> style="width:auto;"> Włącz statystyki</label>
                <label style="display:flex;gap:.6rem;align-items:center;"><input type="checkbox" name="stats_ignore_admin" <?= ($stats_settings['ignore_admin'] ?? '1') === '1' ? 'checked' : '' ?> style="width:auto;"> Nie licz administratora</label>
                <label style="display:flex;gap:.6rem;align-items:center;"><input type="checkbox" name="stats_ignore_bots" <?= ($stats_settings['ignore_bots'] ?? '1') === '1' ? 'checked' : '' ?> style="width:auto;"> Ignoruj boty</label>
                <div><label>Limit nabijania / IP + strona (min)</label><input type="number" name="stats_throttle_minutes" value="<?= e($stats_settings['throttle_minutes'] ?? 30) ?>" min="1" max="1440"></div>
                <div><label>Czas online (min)</label><input type="number" name="stats_online_minutes" value="<?= e($stats_settings['online_minutes'] ?? 5) ?>" min="1" max="60"></div>
                <button class="btn btn-save" type="submit"><i class="fa-solid fa-save"></i> Zapisz</button>
            </form>
            <form method="post" onsubmit="return confirm('Na pewno wyczyścić wszystkie statystyki?');" style="margin-top:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_stats">
                <button class="btn btn-delete" type="submit"><i class="fa-solid fa-trash"></i> Wyczyść statystyki</button>
            </form>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h2>Najpopularniejsze strony</h2>
                <table class="stats-table-small"><thead><tr><th>Strona</th><th>Wyświetlenia</th><th>Ostatnio</th></tr></thead><tbody>
                <?php foreach (array_slice($stats['pages'], 0, 20, true) as $path => $row): ?>
                    <tr><td><strong><?= e($row['title'] ?? $path) ?></strong><br><small style="color:#94a3b8;"><?= e($path) ?></small></td><td><?= (int)($row['views'] ?? 0) ?></td><td><?= e($row['last'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($stats['pages'])): ?><tr><td colspan="3">Brak danych.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
            <div class="card">
                <h2>Urządzenia</h2>
                <table class="stats-table-small"><tbody><?php foreach ($stats['devices'] as $k=>$v): ?><tr><td><?= e($k) ?></td><td><?= (int)$v ?></td></tr><?php endforeach; ?></tbody></table>
                <h2 style="margin-top:1.5rem;">Przeglądarki</h2>
                <table class="stats-table-small"><tbody><?php foreach ($stats['browsers'] as $k=>$v): ?><tr><td><?= e($k) ?></td><td><?= (int)$v ?></td></tr><?php endforeach; ?></tbody></table>
            </div>
            <div class="card">
                <h2>Źródła wejść</h2>
                <table class="stats-table-small"><tbody><?php foreach (array_slice($stats['refs'],0,20,true) as $k=>$v): ?><tr><td><?= e($k) ?></td><td><?= (int)$v ?></td></tr><?php endforeach; ?><?php if(empty($stats['refs'])): ?><tr><td>Brak danych.</td><td>0</td></tr><?php endif; ?></tbody></table>
            </div>
            <div class="card">
                <h2>Aktywni użytkownicy online</h2>
                <table class="stats-table-small"><thead><tr><th>Strona</th><th>Aktywność</th></tr></thead><tbody>
                <?php foreach ($stats['online'] as $row): ?><tr><td><?= e($row['title'] ?? $row['path'] ?? '') ?><br><small style="color:#94a3b8;"><?= e($row['path'] ?? '') ?></small></td><td><?= date('H:i:s', (int)($row['time'] ?? time())) ?></td></tr><?php endforeach; ?>
                <?php if(empty($stats['online'])): ?><tr><td colspan="2">Brak aktywnych użytkowników.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
        </div>

    <?php elseif ($tab === 'ustawienia'): ?>
        <div class="card card-settings settings-tabs-card">
            <div class="settings-header-row">
                <div>
                    <h2 style="margin-top:0; color:var(--settings-color);"><i class="fa-solid fa-gear" style="margin-right:0.6rem;"></i> Ustawienia witryny</h2>
                    <p style="color:#94a3b8;margin:0.4rem 0 0;">Ustawienia są pogrupowane według zastosowania: podstawowe, wygląd, nagłówek/treść, integracje i system.</p>
                </div>
                <span class="settings-save-floating" style="cursor:default;background:#0f172a;border:1px solid #334155;"><i class="fa-solid fa-circle-info"></i> Zapisuj w aktywnej sekcji</span>
            </div>

            <div class="settings-clean-intro">
                <div class="settings-clean-card">
                    <h3><i class="fa-solid fa-layer-group"></i> Ustawienia uporządkowane według zastosowania</h3>
                    <p>Najpierw ustaw podstawy witryny, potem wygląd, nagłówek, treść, a na końcu integracje i bezpieczeństwo. Duże moduły, takie jak Menu, Stopka, Chat i Statystyki, mają własne zakładki w lewym menu.</p>
                    <div class="settings-clean-list">
                        <a href="admin.php?tab=menu"><span><i class="fa-solid fa-bars"></i> Menu i podmenu</span><small>osobna zakładka</small></a>
                        <a href="admin.php?tab=stopka"><span><i class="fa-solid fa-shoe-prints"></i> Stopka</span><small>osobna zakładka</small></a>
                        <a href="admin.php?tab=chat"><span><i class="fa-solid fa-comments"></i> Chat i SMTP</span><small>osobna zakładka</small></a>
                        <a href="admin.php?tab=statystyki"><span><i class="fa-solid fa-chart-line"></i> Statystyki</span><small>osobna zakładka</small></a>
                    </div>
                </div>
                <div class="settings-clean-card">
                    <h3><i class="fa-solid fa-circle-info"></i> Co jest w tej zakładce?</h3>
                    <p>Ta sekcja zawiera tylko ustawienia globalne: nazwa strony, strona główna, folder stron, kolory, logo, szerokość treści, nagłówek, social media, eksport oraz hasło administratora.</p>
                    <p style="color:#fbbf24;"><strong>Uwaga:</strong> przyciski zapisu są na dole każdej sekcji, żeby nie mieszać ustawień z różnych modułów.</p>
                </div>
            </div>

            <div class="settings-tabs" role="tablist" aria-label="Podzakładki ustawień SpiderCMS">
                <div class="settings-tab-group-label">1. Podstawowe</div>
                <button type="button" class="settings-tab-btn active" data-settings-tab="general"><i class="fa-solid fa-sliders"></i> Ogólne i strona główna</button>

                <div class="settings-tab-group-label">2. Wygląd witryny</div>
                <button type="button" class="settings-tab-btn" data-settings-tab="appearance"><i class="fa-solid fa-palette"></i> Kolory i motyw</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="media-logo"><i class="fa-solid fa-image"></i> Logo / ikona</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="layout"><i class="fa-solid fa-ruler-combined"></i> Nagłówek i szerokość treści</button>

                <div class="settings-tab-group-label">3. Integracje</div>
                <button type="button" class="settings-tab-btn" data-settings-tab="social"><i class="fa-solid fa-share-nodes"></i> Social Media</button>

                <div class="settings-tab-group-label">4. System</div>
                <button type="button" class="settings-tab-btn" data-settings-tab="security"><i class="fa-solid fa-shield-halved"></i> Bezpieczeństwo / hasło</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="advanced"><i class="fa-solid fa-screwdriver-wrench"></i> Eksport i zaawansowane</button>
            </div>

            <form id="settings-main-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">

                <section class="settings-panel active" data-settings-panel="general">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-sliders"></i> Podstawowe ustawienia witryny</h3>
                        <p>Nazwa witryny, strona główna i folder dla nowych podstron.</p>
                    </div>

                    <div class="settings-box">
                        <label for="site_name">Nazwa witryny</label>
                        <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>" required>
                    </div>

                    <div class="settings-box">
                        <h3 style="margin:0 0 0.8rem; color:#fbbf24;"><i class="fa-solid fa-house-chimney"></i> Strona główna witryny</h3>
                        <p style="color:#94a3b8; margin:0 0 1rem;">Aktualnie jako strona główna ustawiona jest: <strong style="color:#f8fafc;"><?= htmlspecialchars($homepage_slug) ?>.php</strong></p>
                        <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                            <div style="flex:1; min-width:240px;">
                                <label for="homepage_slug" style="margin-top:0;">Wybierz stronę główną</label>
                                <select id="homepage_slug" name="homepage_slug" class="settings-select">
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?= htmlspecialchars($page['slug']) ?>" <?= $page['slug'] === $homepage_slug ? 'selected' : '' ?>><?= htmlspecialchars($page['slug']) ?>.php</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="action" value="set_homepage" style="background:#f59e0b;"><i class="fa-solid fa-star"></i> Ustaw jako główną</button>
                        </div>
                    </div>

                    <div class="settings-box">
                        <h3 style="margin:0 0 0.8rem; color:#34d399;"><i class="fa-solid fa-folder-tree"></i> Folder nowych podstron</h3>
                        <p style="color:#94a3b8; margin:0 0 1rem;">Wybierz katalog w obrębie CMS, do którego będą zapisywane nowo tworzone strony. Aktualny folder: <strong style="color:#f8fafc;"><?= htmlspecialchars($active_page_folder) ?>/</strong></p>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:end;" class="settings-responsive-grid">
                            <div>
                                <label for="page_folder" style="margin-top:0;">Folder docelowy</label>
                                <input list="page-folder-list" type="text" id="page_folder" name="page_folder" value="<?= htmlspecialchars($active_page_folder) ?>" placeholder="np. pages, strony, oferta/podstrony">
                                <datalist id="page-folder-list">
                                    <?php foreach (spidercms_available_page_folders() as $folder_option): ?>
                                        <option value="<?= htmlspecialchars($folder_option) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div style="color:#94a3b8; font-size:0.95rem; line-height:1.55;">
                                Adres publiczny nowych stron będzie zaczynał się od:<br>
                                <code><?= htmlspecialchars($active_pages_url) ?></code>
                            </div>
                        </div>
                        <p style="color:#fbbf24; margin:1rem 0 0; font-size:0.92rem;">Dla bezpieczeństwa nie można podać ścieżki typu <code>../</code> ani ścieżki absolutnej. CMS sam utworzy folder, jeśli go nie ma.</p>
                    </div>

                    <div class="settings-actions-bottom">
                        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz ustawienia ogólne</button>
                    </div>
                </section>

                <section class="settings-panel" data-settings-panel="appearance">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-palette"></i> Kolory i motyw strony</h3>
                        <p>Kolory globalne, tło strony, linki, przyciski, nagłówek i stopka.</p>
                    </div>

                    <div class="settings-box" style="border-color:rgba(168,85,247,.35);background:linear-gradient(135deg,rgba(168,85,247,.10),rgba(37,99,235,.08));">
                        <h3 style="margin:0 0 .6rem;color:#f8fafc;"><i class="fa-solid fa-wand-magic-sparkles"></i> Gotowe presety strony</h3>
                        <p style="color:#cbd5e1;margin:0 0 1rem;line-height:1.55;">Wybierz gotowy styl, aby jednym kliknięciem ustawić kolory, szerokość treści, wysokość nagłówka, logo, zaokrąglenia i podstawowy styl nazwy witryny. Nie usuwa to stron ani treści.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.8rem;">
                            <button type="submit" name="site_preset" value="minimal" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:#fff;color:#111827;border:1px solid #e5e7eb;text-align:left;padding:1rem;border-radius:14px;"><strong>Minimal jasny</strong><br><small>czysty, prosty, czytelny</small></button>
                            <button type="submit" name="site_preset" value="corporate" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:#1e40af;color:#fff;text-align:left;padding:1rem;border-radius:14px;"><strong>Corporate</strong><br><small>firma, usługi, oferta</small></button>
                            <button type="submit" name="site_preset" value="dark" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:#020617;color:#e5e7eb;border:1px solid #334155;text-align:left;padding:1rem;border-radius:14px;"><strong>Dark premium</strong><br><small>ciemny i elegancki</small></button>
                            <button type="submit" name="site_preset" value="glass" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:linear-gradient(135deg,#eef2ff,#fff);color:#312e81;text-align:left;padding:1rem;border-radius:14px;"><strong>Glass</strong><br><small>nowoczesny, lekki</small></button>
                            <button type="submit" name="site_preset" value="landing" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:#fff7ed;color:#9a3412;text-align:left;padding:1rem;border-radius:14px;"><strong>Landing</strong><br><small>sprzedaż i CTA</small></button>
                            <button type="submit" name="site_preset" value="neon" onclick="this.form.querySelector('input[name=\'action\']').value='apply_site_preset'" style="background:linear-gradient(135deg,#111827,#581c87);color:#fff;text-align:left;padding:1rem;border-radius:14px;"><strong>Neon</strong><br><small>styl SpiderCMS</small></button>
                        </div>
                    </div>

                    <div class="settings-mini-title">Główne kolory</div>
                    <div class="settings-grid">
                        <div>
                            <label for="primary">Kolor główny (--primary)</label>
                            <div class="color-preview"><input type="color" id="primary" name="primary" value="<?= htmlspecialchars($theme['primary'] ?? '#a855f7') ?>"><span><?= htmlspecialchars($theme['primary'] ?? '#a855f7') ?></span></div>
                        </div>
                        <div>
                            <label for="primary_dark">Kolor główny ciemny (--primary-dark)</label>
                            <div class="color-preview"><input type="color" id="primary_dark" name="primary_dark" value="<?= htmlspecialchars($theme['primary-dark'] ?? '#7e22ce') ?>"><span><?= htmlspecialchars($theme['primary-dark'] ?? '#7e22ce') ?></span></div>
                        </div>
                        <div>
                            <label for="accent">Kolor akcentujący (--accent)</label>
                            <div class="color-preview"><input type="color" id="accent" name="accent" value="<?= htmlspecialchars($theme['accent'] ?? '#2563eb') ?>"><span><?= htmlspecialchars($theme['accent'] ?? '#2563eb') ?></span></div>
                        </div>
                    </div>

                    <div class="settings-mini-title">Kolory strony, nagłówka i stopki</div>
                    <div class="settings-grid">
                        <div><label for="page_bg">Tło strony</label><div class="color-preview"><input type="color" id="page_bg" name="page_bg" value="<?= htmlspecialchars(theme_value('page-bg', '#f9fafb')) ?>"><span><?= htmlspecialchars(theme_value('page-bg', '#f9fafb')) ?></span></div></div>
                        <div><label for="page_text">Tekst strony</label><div class="color-preview"><input type="color" id="page_text" name="page_text" value="<?= htmlspecialchars(theme_value('page-text', '#111827')) ?>"><span><?= htmlspecialchars(theme_value('page-text', '#111827')) ?></span></div></div>
                        <div><label for="header_bg">Tło nagłówka</label><div class="color-preview"><input type="color" id="header_bg" name="header_bg" value="<?= htmlspecialchars(theme_value('header-bg', '#ffffff')) ?>"><span><?= htmlspecialchars(theme_value('header-bg', '#ffffff')) ?></span></div></div>
                        <div><label for="header_text">Tekst menu</label><div class="color-preview"><input type="color" id="header_text" name="header_text" value="<?= htmlspecialchars(theme_value('header-text', '#374151')) ?>"><span><?= htmlspecialchars(theme_value('header-text', '#374151')) ?></span></div></div>
                        <div><label for="footer_bg">Tło stopki</label><div class="color-preview"><input type="color" id="footer_bg" name="footer_bg" value="<?= htmlspecialchars(theme_value('footer-bg', '#1f2937')) ?>"><span><?= htmlspecialchars(theme_value('footer-bg', '#1f2937')) ?></span></div></div>
                        <div><label for="footer_text">Tekst stopki</label><div class="color-preview"><input type="color" id="footer_text" name="footer_text" value="<?= htmlspecialchars(theme_value('footer-text', '#f3f4f6')) ?>"><span><?= htmlspecialchars(theme_value('footer-text', '#f3f4f6')) ?></span></div></div>
                        <div><label for="footer_muted">Tekst pomocniczy stopki</label><div class="color-preview"><input type="color" id="footer_muted" name="footer_muted" value="<?= htmlspecialchars(theme_value('footer-muted', '#9ca3af')) ?>"><span><?= htmlspecialchars(theme_value('footer-muted', '#9ca3af')) ?></span></div></div>
                        <div><label for="link_color">Linki</label><div class="color-preview"><input type="color" id="link_color" name="link_color" value="<?= htmlspecialchars(theme_value('link-color', '#a855f7')) ?>"><span><?= htmlspecialchars(theme_value('link-color', '#a855f7')) ?></span></div></div>
                        <div><label for="button_bg">Tło przycisków</label><div class="color-preview"><input type="color" id="button_bg" name="button_bg" value="<?= htmlspecialchars(theme_value('button-bg', '#a855f7')) ?>"><span><?= htmlspecialchars(theme_value('button-bg', '#a855f7')) ?></span></div></div>
                        <div><label for="button_text">Tekst przycisków</label><div class="color-preview"><input type="color" id="button_text" name="button_text" value="<?= htmlspecialchars(theme_value('button-text', '#ffffff')) ?>"><span><?= htmlspecialchars(theme_value('button-text', '#ffffff')) ?></span></div></div>
                    </div>

                    <div class="settings-actions-bottom"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz wygląd</button></div>
                </section>

                <section class="settings-panel" data-settings-panel="layout">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-ruler-combined"></i> Nagłówek, menu i szerokość treści</h3>
                        <p>Czcionka, wysokość nagłówka, rozmiar logo, szerokość treści i zaokrąglenia.</p>
                    </div>
                    <div class="settings-grid">
                        <div><label for="font_family">Font CSS</label><input type="text" id="font_family" name="font_family" value="<?= htmlspecialchars(theme_value('font-family', 'system-ui, sans-serif')) ?>"></div>
                        <div><label for="header_height">Wysokość nagłówka [px]</label><input type="text" id="header_height" name="header_height" value="<?= htmlspecialchars(theme_value('header-height', '74')) ?>"></div>
                        <div><label for="logo_height">Maks. wysokość logo [px]</label><input type="text" id="logo_height" name="logo_height" value="<?= htmlspecialchars(theme_value('logo-height', '100')) ?>"></div>
                        <div><label for="menu_position">Pozycja menu</label><select id="menu_position" name="menu_position"><option value="right" <?= theme_value('menu-position', 'right') === 'right' ? 'selected' : '' ?>>Po prawej</option><option value="center" <?= theme_value('menu-position', 'right') === 'center' ? 'selected' : '' ?>>Na środku</option><option value="left" <?= theme_value('menu-position', 'right') === 'left' ? 'selected' : '' ?>>Po lewej</option></select></div>
                        <div style="grid-column:span 2;">
                            <label for="content_width">Szerokość treści strony [px]</label>
                            <input type="text" id="content_width" name="content_width" value="<?= htmlspecialchars(theme_value('content-width', '1240')) ?>" placeholder="np. 960, 1100, 1240, 1440">
                            <p style="color:#94a3b8;font-size:0.9rem;margin-top:0.5rem;line-height:1.55;">
                                Ta wartość ustawia maksymalną szerokość głównej treści strony. Na telefonach treść nadal dopasuje się do ekranu.
                            </p>
                        </div>
                        <div>
                            <label for="content_width_preset">Szybki wybór szerokości</label>
                            <select id="content_width_preset">
                                <option value="">Wybierz preset...</option>
                                <option value="960">Wąska – 960 px</option>
                                <option value="1100">Czytelna – 1100 px</option>
                                <option value="1240">Standardowa – 1240 px</option>
                                <option value="1440">Szeroka – 1440 px</option>
                                <option value="1600">Bardzo szeroka – 1600 px</option>
                            </select>
                        </div>
                        <div><label for="border_radius">Zaokrąglenia [px]</label><input type="text" id="border_radius" name="border_radius" value="<?= htmlspecialchars(theme_value('border-radius', '10')) ?>"></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="shadow_enabled" <?= theme_value('shadow-enabled', '1') === '1' ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Cień nagłówka</label></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="header_enabled" value="1" <?= (($settings['header_enabled'] ?? '1') === '1') ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Pokaż systemowy nagłówek</label><p style="color:#94a3b8;font-size:0.9rem;margin-top:0.5rem;">Po odznaczeniu nie będzie ładowany obecny header.php, czyli zniknie cały domyślny nagłówek z logo i menu. Gdy opcja jest włączona, nagłówek zostaje dokładnie w poprzednim stylu.</p></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="show_site_name_in_header" value="1" <?= (($settings['show_site_name_in_header'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Pokaż nazwę witryny obok logo</label><p style="color:#94a3b8;font-size:0.9rem;margin-top:0.5rem;">Nazwa jest pobierana z pola „Nazwa witryny” i wyświetlana zaraz obok ikony/logo w nagłówku.</p></div>
                    </div>

                    <div class="settings-panel-title" style="margin-top:1.25rem;">
                        <h3><i class="fa-solid fa-font"></i> Styl nazwy witryny w nagłówku</h3>
                        <p>Te opcje działają, gdy włączysz wyświetlanie nazwy witryny obok logo.</p>
                    </div>
                    <div class="settings-grid">
                        <div><label for="header_title_font_size">Rozmiar tekstu [px]</label><input type="text" id="header_title_font_size" name="header_title_font_size" value="<?= htmlspecialchars($settings['header_title_font_size'] ?? '22') ?>" placeholder="np. 22"></div>
                        <div><label for="header_title_font_weight">Grubość tekstu</label><select id="header_title_font_weight" name="header_title_font_weight"><option value="400" <?= (($settings['header_title_font_weight'] ?? '800') === '400') ? 'selected' : '' ?>>Normalna 400</option><option value="500" <?= (($settings['header_title_font_weight'] ?? '800') === '500') ? 'selected' : '' ?>>Średnia 500</option><option value="600" <?= (($settings['header_title_font_weight'] ?? '800') === '600') ? 'selected' : '' ?>>Półgruba 600</option><option value="700" <?= (($settings['header_title_font_weight'] ?? '800') === '700') ? 'selected' : '' ?>>Gruba 700</option><option value="800" <?= (($settings['header_title_font_weight'] ?? '800') === '800') ? 'selected' : '' ?>>Bardzo gruba 800</option><option value="900" <?= (($settings['header_title_font_weight'] ?? '800') === '900') ? 'selected' : '' ?>>Maksymalna 900</option></select></div>
                        <div><label for="header_title_color">Kolor tekstu</label><input type="text" id="header_title_color" name="header_title_color" value="<?= htmlspecialchars($settings['header_title_color'] ?? '') ?>" placeholder="puste = kolor nagłówka, np. #111827"></div>
                        <div><label for="header_title_gap">Odstęp od logo [px]</label><input type="text" id="header_title_gap" name="header_title_gap" value="<?= htmlspecialchars($settings['header_title_gap'] ?? '10') ?>" placeholder="np. 10"></div>
                        <div><label for="header_title_bg">Tło napisu</label><input type="text" id="header_title_bg" name="header_title_bg" value="<?= htmlspecialchars($settings['header_title_bg'] ?? '') ?>" placeholder="puste = bez tła, np. rgba(0,0,0,.05)"></div>
                        <div><label for="header_title_radius">Zaokrąglenie tła [px]</label><input type="text" id="header_title_radius" name="header_title_radius" value="<?= htmlspecialchars($settings['header_title_radius'] ?? '0') ?>" placeholder="np. 8"></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="header_title_uppercase" value="1" <?= (($settings['header_title_uppercase'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Wielkie litery</label></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="header_title_italic" value="1" <?= (($settings['header_title_italic'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Kursywa</label></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="header_title_shadow" value="1" <?= (($settings['header_title_shadow'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Cień tekstu</label></div>
                        <div style="display:flex;align-items:center;gap:.8rem;padding:1rem;border:1px solid #334155;border-radius:12px;background:#0f172a;"><span style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#a855f7,#2563eb);display:inline-block;"></span><strong id="headerTitlePreview" style="font-size:<?= htmlspecialchars($settings['header_title_font_size'] ?? '22') ?>px;font-weight:<?= htmlspecialchars($settings['header_title_font_weight'] ?? '800') ?>;color:<?= htmlspecialchars(($settings['header_title_color'] ?? '') ?: '#f8fafc') ?>;">Podgląd nazwy</strong></div>
                    </div>
                    <div class="settings-actions-bottom"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz układ</button></div>
                </section>

                <section class="settings-panel" data-settings-panel="media-logo">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-image"></i> Logo i ikona nagłówka</h3>
                        <p>Prześlij nowe logo albo podaj bezpośredni adres URL do grafiki.</p>
                    </div>
                    <div class="settings-box">
                        <label for="logo_upload">Logo witryny</label>
                        <input type="file" id="logo_upload" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/gif">
                        <p style="margin:1rem 0 0.5rem; color:#94a3b8; font-size:0.95rem;">lub wklej bezpośredni URL:</p>
                        <input type="text" name="logo_url" value="<?= htmlspecialchars($logo_url) ?>">
                        <?php if ($logo_url): ?>
                            <div style="margin-top:1.5rem;">
                                <strong>Aktualne logo:</strong><br>
                                <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" style="max-height:120px; margin-top:0.5rem; border:1px solid var(--gray-200); border-radius:8px;">
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="settings-actions-bottom"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz logo</button></div>
                </section>
            </form>



            <section class="settings-panel" data-settings-panel="social">
                <div class="settings-panel-title">
                    <h3><i class="fa-solid fa-share-nodes"></i> Social Media Hub</h3>
                    <p>Linki społecznościowe, ikony w nagłówku i stopce, pływające przyciski, widget kontaktowy oraz podstawowe OpenGraph.</p>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="save_social_settings">

                    <div class="settings-box">
                        <h3 style="margin:0 0 1rem;color:#34d399;"><i class="fa-solid fa-toggle-on"></i> Widoczność modułu</h3>
                        <div class="social-toggle-grid">
                            <label><input type="checkbox" name="social_enabled" <?= ($social_settings['enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Włącz Social Media Hub</label>
                            <label><input type="checkbox" name="social_show_header" <?= ($social_settings['show_header'] ?? '0') === '1' ? 'checked' : '' ?>> Ikony w nagłówku</label>
                            <label><input type="checkbox" name="social_show_footer" <?= ($social_settings['show_footer'] ?? '1') === '1' ? 'checked' : '' ?>> Ikony w stopce</label>
                            <label><input type="checkbox" name="social_show_floating" <?= ($social_settings['show_floating'] ?? '1') === '1' ? 'checked' : '' ?>> Pływające przyciski</label>
                            <label><input type="checkbox" name="social_show_contact_widget" <?= ($social_settings['show_contact_widget'] ?? '0') === '1' ? 'checked' : '' ?>> Widget szybkiego kontaktu</label>
                            <label><input type="checkbox" name="social_og_enabled" <?= ($social_settings['og_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> OpenGraph dla udostępniania</label>
                        </div>
                        <label for="social_floating_side">Strona pływających ikon</label>
                        <select id="social_floating_side" name="social_floating_side" class="settings-select">
                            <option value="right" <?= ($social_settings['floating_side'] ?? 'right') !== 'left' ? 'selected' : '' ?>>Prawa strona</option>
                            <option value="left" <?= ($social_settings['floating_side'] ?? 'right') === 'left' ? 'selected' : '' ?>>Lewa strona</option>
                        </select>
                    </div>

                    <div class="settings-box">
                        <h3 style="margin:0 0 1rem;color:#60a5fa;"><i class="fa-solid fa-link"></i> Linki i dane kontaktowe</h3>
                        <div class="social-admin-grid">
                            <?php foreach (social_platforms() as $social_key => $social_meta): ?>
                            <div class="social-admin-card">
                                <h4><i class="<?= htmlspecialchars($social_meta['icon']) ?>"></i> <?= htmlspecialchars($social_meta['label']) ?></h4>
                                <input type="text" name="social_<?= htmlspecialchars($social_key) ?>" value="<?= htmlspecialchars($social_settings[$social_key] ?? '') ?>" placeholder="<?= $social_key === 'email' ? 'kontakt@example.com' : ($social_key === 'phone' ? '+48 000 000 000' : 'https://...') ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="settings-box">
                        <h3 style="margin:0 0 1rem;color:#fbbf24;"><i class="fa-solid fa-share-from-square"></i> OpenGraph</h3>
                        <label>Tytuł udostępniania</label>
                        <input type="text" name="social_og_title" value="<?= htmlspecialchars($social_settings['og_title'] ?? '') ?>" placeholder="Domyślnie nazwa witryny">
                        <label>Opis udostępniania</label>
                        <textarea name="social_og_description" class="input-field" rows="3" placeholder="Krótki opis strony widoczny np. na Facebooku"><?= htmlspecialchars($social_settings['og_description'] ?? '') ?></textarea>
                        <label>Obraz OpenGraph URL</label>
                        <input type="text" name="social_og_image" value="<?= htmlspecialchars($social_settings['og_image'] ?? '') ?>" placeholder="https://example.com/uploads/og-image.jpg">
                    </div>

                    <div class="settings-actions-bottom"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz Social Media Hub</button></div>
                </form>
            </section>

            <section class="settings-panel" data-settings-panel="security">
                <div class="settings-panel-title">
                    <h3><i class="fa-solid fa-shield-halved"></i> Bezpieczeństwo</h3>
                    <p>Zmiana hasła administratora i podstawowe informacje o zabezpieczeniach panelu.</p>
                </div>
                <div class="settings-security-box spidercms-create-page-only" data-spidercms-purpose="create-page">
                    <h3 style="margin-top:0; color: #f87171;"><i class="fa-solid fa-key"></i> Zmiana hasła administratora</h3>
                    <p style="color:#94a3b8; margin-bottom:1.5rem;">Zalecane co 3–6 miesięcy. Wymagane stare hasło.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="change_password">
                        <label for="old_password">Stare hasło</label>
                        <input type="password" id="old_password" name="old_password" required autocomplete="current-password">
                        <label for="new_password">Nowe hasło (min. 6 znaków)</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
                        <label for="confirm_password">Powtórz nowe hasło</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
                        <div style="margin-top: 1.8rem;"><button type="submit" style="background:#ef4444;">Zmień hasło</button></div>
                    </form>
                </div>
            </section>

            <section class="settings-panel" data-settings-panel="advanced">
                <div class="settings-panel-title">
                    <h3><i class="fa-solid fa-screwdriver-wrench"></i> Zaawansowane</h3>
                    <p>Eksport całej witryny do pliku ZIP.</p>
                </div>
                <form method="post" style="margin-top:2rem; text-align:center; border-top:1px solid var(--gray-200); padding-top:1.5rem;">
                    <input type="hidden" name="action" value="export_all">
                    <button type="submit" class="btn-full-export"><i class="fa-solid fa-download"></i> Eksport całej witryny (ZIP)</button>
                </form>
            </section>
        </div>
    <?php elseif ($tab === 'menu'): ?>
        <div class="card">
            <h2 style="margin-top:0; color:var(--menu-color);"><i class="fa-solid fa-bars" style="margin-right:0.6rem;"></i> Górne menu nawigacyjne</h2>
            <form method="post">
                <input type="hidden" name="action" value="save_menu">
                <label style="display:flex; align-items:center; gap:0.8rem; font-size:1.1rem; margin:1.8rem 0 1.2rem; color:#f8fafc;">
                    <input type="checkbox" name="menu_enabled" <?= $menu_enabled ? 'checked' : '' ?> style="width:auto; transform:scale(1.3);">
                    <strong>Włącz górne menu na wszystkich stronach</strong>
                </label>
                <div id="menu-items">
                    <?php for ($i = 0; $i < 8; $i++):
                        $item = $menu_items[$i] ?? ['label' => '', 'url' => '', 'icon' => '', 'children' => []];
                        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    ?>
                    <div class="menu-main-block" data-menu-index="<?= (int)$i ?>">
                        <div class="menu-row">
                            <div>
                                <label style="font-size:0.9rem; margin-bottom:0.3rem;">Nazwa / tekst</label>
                                <input type="text" name="menu_label[]" value="<?= htmlspecialchars($item['label'] ?? '') ?>" placeholder="np. O nas">
                            </div>
                            <div>
                                <label style="font-size:0.9rem; margin-bottom:0.3rem;">Link (URL)</label>
                                <input type="text" name="menu_url[]" value="<?= htmlspecialchars($item['url'] ?? '') ?>" placeholder="/o-nas">
                            </div>
                            <div>
                                <label style="font-size:0.9rem; margin-bottom:0.3rem;">Ikona (Font Awesome / URL)</label>
                                <input type="text" name="menu_icon[]" value="<?= htmlspecialchars($item['icon'] ?? '') ?>" placeholder="fa-solid fa-home">
                            </div>
                        </div>

                        <div class="submenu-list" id="submenu-list-<?= (int)$i ?>">
                            <?php foreach ($children as $child): ?>
                            <div class="submenu-row">
                                <div>
                                    <label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – nazwa</label>
                                    <input type="text" name="submenu_label[<?= (int)$i ?>][]" value="<?= htmlspecialchars($child['label'] ?? '') ?>" placeholder="np. Projektowanie stron">
                                </div>
                                <div>
                                    <label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – link</label>
                                    <input type="text" name="submenu_url[<?= (int)$i ?>][]" value="<?= htmlspecialchars($child['url'] ?? '') ?>" placeholder="/projektowanie-stron">
                                </div>
                                <div>
                                    <label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – ikona</label>
                                    <input type="text" name="submenu_icon[<?= (int)$i ?>][]" value="<?= htmlspecialchars($child['icon'] ?? '') ?>" placeholder="fa-solid fa-angle-right">
                                </div>
                                <button type="button" class="remove-submenu-btn" onclick="this.closest('.submenu-row').remove();"><i class="fa-solid fa-trash"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="add-submenu-btn" data-parent="<?= (int)$i ?>">
                            <i class="fa-solid fa-plus"></i> Dodaj podmenu
                        </button>
                    </div>
                    <?php endfor; ?>
                </div>
                <div style="margin-top:2.5rem;"><button type="submit"><i class="fa-solid fa-save"></i> Zapisz menu</button></div>
            </form>
        </div>
    <?php elseif ($tab === 'logi'): ?>
        <div class="card">
            <h2><i class="fa-solid fa-list-check"></i> Logi akcji administratora</h2>
            <p style="color:#94a3b8;margin-top:-.4rem;">Rejestruje logowanie, edycję stron, menu, ustawień, czatu, mediów, presetów, statystyk i inne akcje panelu.</p>

            <form method="get" class="logs-toolbar">
                <input type="hidden" name="tab" value="logi">
                <div class="field">
                    <label>Akcja</label>
                    <select name="log_action">
                        <option value="">Wszystkie akcje</option>
                        <?php foreach ($log_actions_available as $la => $ll): ?>
                            <option value="<?= e($la) ?>" <?= $log_filter_action === $la ? 'selected' : '' ?>><?= e($ll) ?> (<?= e($la) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="log_status">
                        <option value="">Wszystkie statusy</option>
                        <option value="success" <?= $log_filter_status === 'success' ? 'selected' : '' ?>>OK</option>
                        <option value="error" <?= $log_filter_status === 'error' ? 'selected' : '' ?>>Błąd</option>
                        <option value="warning" <?= $log_filter_status === 'warning' ? 'selected' : '' ?>>Uwaga</option>
                        <option value="info" <?= $log_filter_status === 'info' ? 'selected' : '' ?>>Info</option>
                    </select>
                </div>
                <div class="field">
                    <label>Od daty</label>
                    <input type="date" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Do daty</label>
                    <input type="date" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>">
                </div>
                <button class="btn btn-edit" type="submit"><i class="fa-solid fa-filter"></i> Filtruj</button>
                <a class="btn btn-view" href="admin.php?tab=logi"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </form>

            <div class="logs-toolbar" style="align-items:center;">
                <strong style="margin-right:auto;"><i class="fa-solid fa-file-export"></i> Eksport logów</strong>
                <?php
                    $export_query = [
                        'tab'=>'logi',
                        'export_logs'=>'1',
                        'log_action'=>$log_filter_action,
                        'log_status'=>$log_filter_status,
                        'date_from'=>$_GET['date_from'] ?? '',
                        'date_to'=>$_GET['date_to'] ?? ''
                    ];
                ?>
                <a class="btn btn-export" href="admin.php?<?= e(http_build_query(array_merge($export_query, ['format'=>'csv']))) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
                <a class="btn btn-export" href="admin.php?<?= e(http_build_query(array_merge($export_query, ['format'=>'json']))) ?>"><i class="fa-solid fa-code"></i> JSON</a>
                <a class="btn btn-export" href="admin.php?<?= e(http_build_query(array_merge($export_query, ['format'=>'txt']))) ?>"><i class="fa-solid fa-file-lines"></i> TXT</a>
                <a class="btn btn-export" href="admin.php?<?= e(http_build_query(array_merge($export_query, ['format'=>'zip']))) ?>"><i class="fa-solid fa-file-zipper"></i> ZIP</a>
            </div>

            <form method="post" onsubmit="return confirm('Wyczyścić wszystkie logi?');" style="margin-bottom:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_action_logs">
                <button class="btn btn-delete" type="submit"><i class="fa-solid fa-trash"></i> Wyczyść logi</button>
            </form>

            <div class="logs-table-wrap">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Czas</th>
                            <th>Status</th>
                            <th>Akcja</th>
                            <th>IP / URL</th>
                            <th>Szczegóły</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($action_logs)): ?>
                            <tr><td colspan="5" style="color:#94a3b8;text-align:center;padding:2rem;">Brak logów do wyświetlenia.</td></tr>
                        <?php else: ?>
                            <?php foreach ($action_logs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($log['time'] ?? '') ?></strong>
                                        <div class="log-small"><?= e($log['method'] ?? '') ?></div>
                                    </td>
                                    <td><?= spidercms_log_status_badge($log['status'] ?? 'info') ?></td>
                                    <td>
                                        <strong><?= e($log['label'] ?? spidercms_log_label($log['action'] ?? '')) ?></strong>
                                        <div class="log-small"><?= e($log['action'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($log['ip'] ?? '') ?></div>
                                        <div class="log-small"><?= e($log['url'] ?? '') ?></div>
                                    </td>
                                    <td><div class="log-context"><?= e(json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($tab === 'o-cms'): ?>
        <?php
            $cms_version = '1.4 Social & Chat Edition';
            $php_version = PHP_VERSION;
            $server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Nieznany';
            $security_checks = [
                ['label' => 'Plik hasła administratora', 'ok' => file_exists(__DIR__ . '/.admin_hash'), 'hint' => '.admin_hash istnieje'],
                ['label' => 'Katalog uploadów', 'ok' => is_dir(__DIR__ . '/uploads'), 'hint' => 'uploads/ gotowy'],
                ['label' => 'Blokada PHP w uploads', 'ok' => file_exists(__DIR__ . '/uploads/.htaccess'), 'hint' => 'warto blokować wykonywanie PHP'],
                ['label' => 'Katalog chatu', 'ok' => is_dir(__DIR__ . '/.chat'), 'hint' => '.chat/ gotowy'],
                ['label' => 'Ochrona katalogu chatu', 'ok' => file_exists(__DIR__ . '/.chat/.htaccess'), 'hint' => 'warto zablokować publiczny dostęp'],
                ['label' => 'Konfiguracja motywu', 'ok' => file_exists(__DIR__ . '/.theme.json'), 'hint' => '.theme.json zapisany'],
                ['label' => 'Konfiguracja social media', 'ok' => file_exists(__DIR__ . '/.social.json'), 'hint' => '.social.json zapisany'],
            ];
            $enabled_modules = [
                ['icon' => 'fa-file-lines', 'name' => 'Strony flat-file', 'desc' => 'Tworzenie i edycja podstron bez bazy danych.'],
                ['icon' => 'fa-bars', 'name' => 'Menu', 'desc' => 'Globalne menu z ikonami i linkami.'],
                ['icon' => 'fa-shoe-prints', 'name' => 'Stopka wielokolumnowa', 'desc' => 'Dowolna liczba kolumn stopki.'],
                ['icon' => 'fa-comments', 'name' => 'Chat', 'desc' => 'Komunikacja użytkownika strony z administratorem.'],
                ['icon' => 'fa-box-archive', 'name' => 'Archiwum chatu', 'desc' => 'Historia rozmów zapisywana lokalnie.'],
                ['icon' => 'fa-images', 'name' => 'Media', 'desc' => 'Upload i zarządzanie plikami.'],
                ['icon' => 'fa-share-nodes', 'name' => 'Social Hub', 'desc' => 'Ikony social media, floating bar i OpenGraph.'],
                ['icon' => 'fa-palette', 'name' => 'Motyw', 'desc' => 'Kolory, logo, układ i globalne style.'],
            ];
        ?>
        <div class="card card-about">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:1.5rem; flex-wrap:wrap;">
                <div>
                    <h2 style="margin-top:0; color:var(--about-color);"><i class="fa-solid fa-info-circle" style="margin-right:0.6rem;"></i> O SpiderCMS</h2>
                    <p style="margin:1rem 0 0; line-height:1.7; max-width:850px; color:#cbd5e1;">
                        <strong>SpiderCMS</strong> to lekki, plikowy system zarządzania treścią dla osób tworzących małe strony firmowe, landing page, portfolio i proste serwisy bez bazy danych. Panel łączy edycję treści, wygląd, media, social media, stopkę, menu oraz chat z administratorem.
                    </p>
                </div>
                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1rem 1.2rem; min-width:230px;">
                    <div style="color:#94a3b8; font-size:0.9rem;">Wersja systemu</div>
                    <div style="font-size:1.25rem; font-weight:800; color:var(--primary); margin-top:0.25rem;"><?= htmlspecialchars($cms_version) ?></div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:1rem; margin-top:1.8rem;">
                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.1rem;">
                    <div style="color:#94a3b8;"><i class="fa-solid fa-file"></i> Strony</div>
                    <div style="font-size:2rem; font-weight:800; color:var(--accent);"><?= count($pages) ?></div>
                </div>
                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.1rem;">
                    <div style="color:#94a3b8;"><i class="fa-solid fa-image"></i> Media</div>
                    <div style="font-size:2rem; font-weight:800; color:#34d399;"><?= count($media_files) ?></div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1.15fr 0.85fr; gap:1.4rem; margin-top:1.8rem;">
                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.2rem;">
                    <h3 style="margin:0 0 1rem; color:var(--primary);"><i class="fa-solid fa-puzzle-piece"></i> Aktywne moduły</h3>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:0.8rem;">
                        <?php foreach ($enabled_modules as $module): ?>
                            <div style="border:1px solid var(--gray-200); border-radius:10px; padding:0.9rem; background:#111827;">
                                <div style="font-weight:800; color:#f8fafc;"><i class="fa-solid <?= htmlspecialchars($module['icon']) ?>" style="color:var(--about-color); margin-right:0.45rem;"></i><?= htmlspecialchars($module['name']) ?></div>
                                <div style="color:#94a3b8; font-size:0.92rem; margin-top:0.35rem; line-height:1.45;"><?= htmlspecialchars($module['desc']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.2rem;">
                    <h3 style="margin:0 0 1rem; color:#fbbf24;"><i class="fa-solid fa-shield-halved"></i> Checklista bezpieczeństwa</h3>
                    <div style="display:flex; flex-direction:column; gap:0.65rem;">
                        <?php foreach ($security_checks as $check): ?>
                            <div style="display:flex; align-items:flex-start; gap:0.65rem; padding:0.7rem; border-radius:9px; background:#111827; border:1px solid var(--gray-200);">
                                <i class="fa-solid <?= $check['ok'] ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" style="color:<?= $check['ok'] ? '#10b981' : '#f59e0b' ?>; margin-top:0.15rem;"></i>
                                <div>
                                    <div style="font-weight:700;"><?= htmlspecialchars($check['label']) ?></div>
                                    <div style="color:#94a3b8; font-size:0.88rem;"><?= htmlspecialchars($check['hint']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1.4rem; margin-top:1.8rem;">
                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.2rem;">
                    <h3 style="margin:0 0 1rem; color:#60a5fa;"><i class="fa-solid fa-server"></i> Informacje techniczne</h3>
                    <table style="margin:0;">
                        <tr><th>PHP</th><td><?= htmlspecialchars($php_version) ?></td></tr>
                        <tr><th>Serwer</th><td><?= htmlspecialchars($server_software) ?></td></tr>
                        <tr><th>Folder stron</th><td><code><?= htmlspecialchars(defined('PAGES_DIR') ? PAGES_DIR : 'brak') ?></code></td></tr>
                        <tr><th>URL stron</th><td><code><?= htmlspecialchars(defined('ACTIVE_PAGES_URL') ? ACTIVE_PAGES_URL : (defined('PAGES_URL') ? PAGES_URL : 'brak')) ?></code></td></tr>
                        <tr><th>Strona główna</th><td><code><?= htmlspecialchars($homepage_slug) ?>.php</code></td></tr>
                    </table>
                </div>

                <div style="background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.2rem;">
                    <h3 style="margin:0 0 1rem; color:#34d399;"><i class="fa-solid fa-route"></i> Przydatne skróty</h3>
                    <div style="display:flex; flex-direction:column; gap:0.75rem;">
                        <a class="btn btn-view" href="/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otwórz stronę publiczną</a>
                        <a class="btn btn-edit" href="admin.php?tab=strony"><i class="fa-solid fa-file-circle-plus"></i> Zarządzaj stronami</a>
                        <a class="btn btn-export" href="admin.php?tab=ustawienia"><i class="fa-solid fa-gear"></i> Ustawienia systemu</a>
                        <a class="btn btn-view" href="admin.php?tab=media"><i class="fa-solid fa-images"></i> Biblioteka mediów</a>
                    </div>
                </div>
            </div>

            <div style="margin-top:1.8rem; background:#0f172a; border:1px solid var(--gray-200); border-radius:12px; padding:1.2rem;">
                <h3 style="margin:0 0 1rem; color:var(--about-color);"><i class="fa-solid fa-list-check"></i> Szybka instrukcja pracy</h3>
                <ol style="padding-left:1.2rem; color:#cbd5e1; line-height:1.8;">
                    <li>Utwórz nową stronę w zakładce <strong>Strony</strong> i wybierz folder zapisu.</li>
                    <li>Wstaw gotowe sekcje z narzędzi edytora, a następnie dopasuj treść w TinyMCE.</li>
                    <li>Dodaj logo, kolory i style w <strong>Ustawieniach</strong>.</li>
                    <li>Ustaw menu, stopkę wielokolumnową i linki social media.</li>
                    <li>Przed publikacją sprawdź checklistę bezpieczeństwa oraz wykonaj eksport ZIP.</li>
                </ol>
            </div>

            <div style="margin-top:2rem; text-align:center; border-top:1px solid var(--gray-200); padding-top:1.5rem;">
                <p style="color:#94a3b8;">Wersja: <?= htmlspecialchars($cms_version) ?> | Autor: Kamil Paprota</p>
                <p style="color:#6b7280;">© <?= date('Y') ?> SpiderCMS – panel administracyjny</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Twoje strony (<?= count($pages) ?>)</h2>
            <p style="color:#94a3b8;margin:0.4rem 0 1rem;">Aktywna strona główna: <strong style="color:#fbbf24;"><?= htmlspecialchars($homepage_slug) ?>.php</strong></p>
            <table>
                <thead>
                    <tr><th>Slug / Plik</th><th>Modyfikacja</th><th>Podgląd</th><th>Akcje</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pages as $page): ?>
                <tr>
                    <td>
                        <code><?= htmlspecialchars($page['slug']) ?>.php</code>
                        <?php if ($page['slug'] === $homepage_slug): ?>
                            <span class="homepage-badge"><i class="fa-solid fa-star"></i> strona główna</span>
                        <?php elseif ($page['slug'] === 'index'): ?>
                            <span style="color:var(--success);font-size:0.9rem;margin-left:0.6rem;">(index)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $page['modified'] ?></td>
                    <td><a href="<?= htmlspecialchars(ACTIVE_PAGES_URL . $page['slug'] . '.php') ?>" target="_blank" class="btn btn-view"><i class="fa-solid fa-eye"></i> Podgląd</a></td>
                    <td>
                        <a href="admin.php?tab=strony&edit=<?= urlencode($page['slug']) ?>" class="btn btn-edit"><i class="fa-solid fa-pen-to-square"></i> Edytuj</a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="duplicate">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($page['slug']) ?>">
                            <button type="submit" class="btn btn-export"><i class="fa-solid fa-copy"></i> Duplikuj</button>
                        </form>
                        <?php if ($page['slug'] !== $homepage_slug): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="set_homepage">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($page['slug']) ?>">
                            <button type="submit" class="btn btn-homepage"><i class="fa-solid fa-star"></i> Główna</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($page['slug'] !== 'index' && $page['slug'] !== $homepage_slug): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Na pewno usunąć?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($page['slug']) ?>">
                            <button type="submit" class="btn btn-delete"><i class="fa-solid fa-trash-can"></i> Usuń</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($edit_slug): ?>
        <div class="card">
            <h2>Edycja: <?= htmlspecialchars($edit_slug) ?><?php if ($edit_slug === 'index') echo ' <small>(strona główna)</small>'; ?></h2>
            <?php if (isset($_GET['duplicated'])): ?>
                <div style="margin:0 0 1rem;padding:0.9rem 1rem;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.28);color:#bbf7d0;">
                    Utworzono kopię strony. Możesz teraz zmienić jej tytuł, slug oraz treść.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['renamed'])): ?>
                <div style="margin:0 0 1rem;padding:0.9rem 1rem;border-radius:10px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.28);color:#bfdbfe;">
                    Zmieniono nazwę / slug strony.
                </div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="old_slug" value="<?= htmlspecialchars($edit_slug) ?>">

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-bottom:1rem;">
                    <div>
                        <label>Tytuł strony</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($edit_title) ?>" placeholder="np. Oferta">
                    </div>
                    <div>
                        <label>Slug / nazwa pliku</label>
                        <input type="text" name="new_slug" value="<?= htmlspecialchars($edit_slug) ?>" required pattern="[a-z0-9\-_]+" placeholder="np. oferta">
                        <p style="color:#94a3b8;margin:0.45rem 0 0;font-size:0.88rem;">Po zapisaniu CMS zmieni nazwę pliku, np. <code>oferta.php</code>.</p>
                    </div>
                </div>

                <?php render_editor_tools(); ?>
                <textarea name="content" class="editor"><?= htmlspecialchars($edit_content) ?></textarea>
                <div style="margin-top:1.6rem;">
                    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz zmiany</button>
                    <a href="admin.php?tab=strony" style="margin-left:1.2rem;color:#94a3b8;text-decoration:none;">Anuluj</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <div class="card">
            <h2>Nowa strona</h2>
            <form data-spidercms-purpose="create-page" method="post">
                <input type="hidden" name="action" value="create">
                <label>Slug (adres URL)</label>
                <input type="text" name="slug" required pattern="[a-z0-9\-_]+" placeholder="np. kontakt">
                <label>Tytuł strony</label>
                <input type="text" name="title" required placeholder="np. Kontakt">
                <label>Folder zapisu strony</label>
                <input list="page-folder-list-create" type="text" name="page_folder" value="<?= htmlspecialchars($active_page_folder) ?>" placeholder="np. pages, strony, oferta/podstrony">
                <datalist id="page-folder-list-create">
                    <?php foreach (spidercms_available_page_folders() as $folder_option): ?>
                        <option value="<?= htmlspecialchars($folder_option) ?>">
                    <?php endforeach; ?>
                </datalist>
                <p style="color:#94a3b8;margin:0.6rem 0 1rem;font-size:0.92rem;">CMS zapisze plik w katalogu wybranym powyżej. Katalog zostanie utworzony automatycznie, jeśli nie istnieje.</p>
                <label>Treść strony</label>
                <?php render_editor_tools(); ?>
                <textarea name="content" class="editor"><p>Wpisz zawartość...</p></textarea>
                <div style="margin-top:1.6rem;"><button type="submit"><i class="fa-solid fa-plus"></i> Utwórz stronę</button></div>
            </form>
        </div>
    <?php endif; ?>


<?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && (($tab ?? '') === 'dashboard') && function_exists('spidercms_booking_stats_summary')): ?>
<?php $spidercms_booking_dash = spidercms_booking_stats_summary(); ?>
<div class="card spidercms-booking-dashboard-card">
    <h2>📅 Rezerwacje</h2>
    <p class="muted">Szybkie podsumowanie systemu rezerwacji.</p>
    <div class="spidercms-booking-dashboard-grid">
        <div><strong><?= (int)$spidercms_booking_dash['all'] ?></strong><span>Wszystkie</span></div>
        <div><strong><?= (int)$spidercms_booking_dash['new'] ?></strong><span>Nowe</span></div>
        <div><strong><?= (int)$spidercms_booking_dash['today'] ?></strong><span>Dzisiaj</span></div>
        <div><strong><?= (int)$spidercms_booking_dash['upcoming'] ?></strong><span>Nadchodzące</span></div>
    </div>
    <a class="btn" href="admin.php?tab=bookings">Przejdź do rezerwacji</a>
</div>
<style>
.spidercms-booking-dashboard-card{margin-top:1rem}
.spidercms-booking-dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem;margin:1rem 0}
.spidercms-booking-dashboard-grid div{padding:1rem;border-radius:16px;background:rgba(168,85,247,.12);border:1px solid rgba(168,85,247,.22)}
.spidercms-booking-dashboard-grid strong{display:block;font-size:1.9rem;color:#fff}
.spidercms-booking-dashboard-grid span{display:block;color:#cbd5e1;font-weight:700}
@media(max-width:800px){.spidercms-booking-dashboard-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.spidercms-booking-dashboard-grid{grid-template-columns:1fr}}


/* SPIDERCMS BOOKING MENU ITEM */
.nav a[href*="tab=bookings"]{
    position:relative;
}



/* SPIDERCMS EDITOR FOCUS HIGHLIGHT START */
.spidercms-edit-focus-pulse{
    animation: spidercmsEditPulse 1.5s ease 0s 2;
    box-shadow:0 0 0 4px rgba(168,85,247,.35), 0 18px 50px rgba(168,85,247,.18)!important;
}
@keyframes spidercmsEditPulse{
    0%{box-shadow:0 0 0 0 rgba(168,85,247,.55)}
    70%{box-shadow:0 0 0 12px rgba(168,85,247,0)}
    100%{box-shadow:0 0 0 0 rgba(168,85,247,0)}
}
/* SPIDERCMS EDITOR FOCUS HIGHLIGHT END */







/* SPIDERCMS HIDE NEW PAGE WHILE EDITING CSS START */
.spidercms-hidden-new-page-during-edit{
    display:none!important;
}
.spidercms-edit-mode-notice{
    margin:0 0 14px;
    padding:12px 14px;
    border-radius:14px;
    background:rgba(34,197,94,.12);
    border:1px solid rgba(34,197,94,.28);
    color:#bbf7d0;
    font-weight:800;
}
/* SPIDERCMS HIDE NEW PAGE WHILE EDITING CSS END */







/* SPIDERCMS MOBILE LAYOUT WIDTH FIX START */
@media (max-width: 768px){

    html,
    body{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        overflow-x:hidden!important;
    }

    /* TO JEST GŁÓWNA NAPRAWA:
       na telefonie panel nie może mieć układu sidebar + treść */
    .layout,
    .admin-layout,
    .panel-layout,
    .dashboard-layout,
    .app-layout{
        display:block!important;
        grid-template-columns:none!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        margin:0!important;
        overflow-x:hidden!important;
    }

    /* Sidebar jako overlay, ale bez usuwania istniejącego mechanizmu menu */
    .sidebar,
    aside.sidebar,
    .admin-sidebar,
    .side-menu{
        position:fixed!important;
        top:0!important;
        left:0!important;
        bottom:0!important;
        width:min(320px,86vw)!important;
        max-width:86vw!important;
        min-width:0!important;
        height:100vh!important;
        max-height:100vh!important;
        z-index:99998!important;
        overflow-y:auto!important;
        overflow-x:hidden!important;
        -webkit-overflow-scrolling:touch!important;
        box-sizing:border-box!important;
    }

    /* Jeżeli istniejący JS chowa/otwiera sidebar klasą, obsłuż różne nazwy */
    body:not(.sidebar-open):not(.spidercms-menu-open):not(.spider-menu-open):not(.spidercms-mobile-sidebar-open) .sidebar,
    body:not(.sidebar-open):not(.spidercms-menu-open):not(.spider-menu-open):not(.spidercms-mobile-sidebar-open) aside.sidebar,
    body:not(.sidebar-open):not(.spidercms-menu-open):not(.spider-menu-open):not(.spidercms-mobile-sidebar-open) .admin-sidebar,
    body:not(.sidebar-open):not(.spidercms-menu-open):not(.spider-menu-open):not(.spidercms-mobile-sidebar-open) .side-menu{
        transform:translateX(-110%)!important;
    }

    body.sidebar-open .sidebar,
    body.sidebar-open aside.sidebar,
    body.sidebar-open .admin-sidebar,
    body.sidebar-open .side-menu,
    body.spidercms-menu-open .sidebar,
    body.spidercms-menu-open aside.sidebar,
    body.spidercms-menu-open .admin-sidebar,
    body.spidercms-menu-open .side-menu,
    body.spider-menu-open .sidebar,
    body.spider-menu-open aside.sidebar,
    body.spider-menu-open .admin-sidebar,
    body.spider-menu-open .side-menu,
    body.spidercms-mobile-sidebar-open .sidebar,
    body.spidercms-mobile-sidebar-open aside.sidebar,
    body.spidercms-mobile-sidebar-open .admin-sidebar,
    body.spidercms-mobile-sidebar-open .side-menu{
        transform:translateX(0)!important;
    }

    /* Treść ma zawsze pełną szerokość ekranu */
    .main,
    main.main,
    .admin-main,
    .content,
    .admin-content,
    .panel-content,
    .page-content,
    .workspace,
    section.main,
    #main,
    #content{
        display:block!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        margin:0!important;
        margin-left:0!important;
        margin-right:0!important;
        box-sizing:border-box!important;
        overflow-x:hidden!important;
    }

    /* Uporządkowanie zawartości tylko tam, gdzie wcześniej robiły się pionowe litery */
    .card,
    .box,
    .panel-card,
    .settings-card,
    section{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        box-sizing:border-box!important;
        overflow:hidden!important;
    }

    h1,h2,h3,p,label,span,div{
        word-break:normal!important;
        overflow-wrap:break-word!important;
    }

    table{
        display:block!important;
        width:100%!important;
        max-width:100%!important;
        overflow-x:auto!important;
        -webkit-overflow-scrolling:touch!important;
        white-space:nowrap!important;
    }

    input,
    select,
    textarea,
    button,
    .btn{
        max-width:100%!important;
        box-sizing:border-box!important;
    }

    .tox,
    .tox-tinymce,
    .tox-editor-container,
    .tox-sidebar-wrap,
    .tox-edit-area{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        box-sizing:border-box!important;
    }
}
/* SPIDERCMS MOBILE LAYOUT WIDTH FIX END */

</style>
<?php endif; ?>

</main>
<script>
document.querySelectorAll('input[type="color"]').forEach(function(input){
    const span = input.parentElement ? input.parentElement.querySelector('span') : null;
    input.addEventListener('input', function(){ if(span) span.textContent = input.value; });
});
</script>
<script>
(function(){
    const snippets = {
        hero: '<section class="cms-hero"><h1>Duży nagłówek strony</h1><p>Krótki opis, hasło reklamowe lub wprowadzenie do podstrony.</p><p><a class="cms-btn" href="#kontakt">Skontaktuj się</a></p></section>',
        button: '<p><a class="cms-btn" href="/kontakt">Przycisk / wezwanie do działania</a></p>',
        columns: '<div class="cms-columns"><div><h2>Lewa kolumna</h2><p>Treść pierwszej kolumny.</p></div><div><h2>Prawa kolumna</h2><p>Treść drugiej kolumny.</p></div></div>',
        cards: '<div class="cms-card-grid"><article class="cms-card"><h3>Usługa 1</h3><p>Opis usługi.</p></article><article class="cms-card"><h3>Usługa 2</h3><p>Opis usługi.</p></article><article class="cms-card"><h3>Usługa 3</h3><p>Opis usługi.</p></article></div>',
        gallery: '<div class="cms-gallery"><img src="/uploads/zdjecie-1.jpg" alt="Opis zdjęcia"><img src="/uploads/zdjecie-2.jpg" alt="Opis zdjęcia"><img src="/uploads/zdjecie-3.jpg" alt="Opis zdjęcia"></div>',
        faq: '<section class="cms-faq"><h2>Najczęstsze pytania</h2><details open><summary>Pytanie numer 1</summary><p>Odpowiedź na pytanie.</p></details><details><summary>Pytanie numer 2</summary><p>Odpowiedź na pytanie.</p></details></section>',
        contact: '<section id="kontakt" class="cms-contact"><h2>Kontakt</h2><p><strong>Telefon:</strong> 000 000 000</p><p><strong>Email:</strong> kontakt@example.com</p><p><strong>Adres:</strong> wpisz adres firmy</p></section>',
        separator: '<hr style="margin:2.5rem 0;border:0;border-top:1px solid #e5e7eb;">'
    };

    const pagePresets = {
        contact: {
            title: 'Kontakt',
            slug: 'kontakt',
            html: '<section class="cms-hero" style="background:linear-gradient(135deg,#f3e8ff,#e0f2fe);"><h1>Kontakt</h1><p>Masz pytania? Skontaktuj się z nami — odpowiemy możliwie szybko.</p><p><a class="cms-btn" href="mailto:kontakt@example.com">Napisz e-mail</a></p></section><section class="cms-columns"><div><h2>Dane kontaktowe</h2><p><strong>Telefon:</strong> 000 000 000</p><p><strong>E-mail:</strong> kontakt@example.com</p><p><strong>Adres:</strong> ul. Przykładowa 1, 00-000 Miasto</p><p><strong>Godziny pracy:</strong> pon.–pt. 8:00–16:00</p></div><div><h2>Napisz do nas</h2><p>Opisz krótko, czego potrzebujesz. Przygotujemy odpowiedź lub ofertę.</p><div class="cms-card"><p><strong>Formularz kontaktowy</strong></p><p>W tym miejscu możesz wkleić własny formularz albo dane kontaktowe.</p></div></div></section><section class="cms-contact"><h2>Jak dojechać?</h2><p>Tutaj możesz dodać mapę Google lub opis lokalizacji.</p></section>'
        },
        about: {
            title: 'O nas',
            slug: 'o-nas',
            html: '<section class="cms-hero"><h1>O nas</h1><p>Poznaj naszą firmę, doświadczenie i sposób działania.</p></section><section class="cms-columns"><div><h2>Kim jesteśmy?</h2><p>Jesteśmy zespołem, który stawia na jakość, rzetelność i partnerską współpracę z klientem.</p><p>Opisz tutaj historię firmy, doświadczenie oraz najważniejsze wartości.</p></div><div class="cms-card"><h2>Dlaczego my?</h2><ul><li>indywidualne podejście,</li><li>terminowa realizacja,</li><li>przejrzysta komunikacja,</li><li>sprawdzone rozwiązania.</li></ul></div></section><section class="cms-card-grid"><article class="cms-card"><h3>Misja</h3><p>Tworzymy rozwiązania, które realnie pomagają klientom.</p></article><article class="cms-card"><h3>Wartości</h3><p>Jakość, zaufanie i odpowiedzialność.</p></article><article class="cms-card"><h3>Doświadczenie</h3><p>Wpisz tutaj liczbę lat pracy, projekty lub branże.</p></article></section>'
        },
        offer: {
            title: 'Oferta',
            slug: 'oferta',
            html: '<section class="cms-hero" style="background:linear-gradient(135deg,#eef2ff,#fdf2f8);"><h1>Oferta</h1><p>Sprawdź, co możemy dla Ciebie zrobić.</p><p><a class="cms-btn" href="/kontakt">Zapytaj o ofertę</a></p></section><section class="cms-card-grid"><article class="cms-card"><h3>Pakiet podstawowy</h3><p>Krótki opis usługi lub produktu.</p><p><strong>Od 000 zł</strong></p></article><article class="cms-card"><h3>Pakiet standard</h3><p>Najczęściej wybierany zakres współpracy.</p><p><strong>Od 000 zł</strong></p></article><article class="cms-card"><h3>Pakiet premium</h3><p>Rozszerzona obsługa i pełne wsparcie.</p><p><strong>Wycena indywidualna</strong></p></article></section><section class="cms-columns"><div><h2>Jak pracujemy?</h2><ol><li>Analiza potrzeb</li><li>Przygotowanie oferty</li><li>Realizacja</li><li>Odbiór i wsparcie</li></ol></div><div><h2>Co otrzymujesz?</h2><p>Wpisz tutaj najważniejsze korzyści dla klienta.</p></div></section>'
        },
        services: {
            title: 'Usługi',
            slug: 'uslugi',
            html: '<section class="cms-hero"><h1>Nasze usługi</h1><p>Kompleksowa obsługa dopasowana do Twoich potrzeb.</p></section><section class="cms-card-grid"><article class="cms-card"><h3>Usługa 1</h3><p>Opis pierwszej usługi, zakresu i efektu dla klienta.</p></article><article class="cms-card"><h3>Usługa 2</h3><p>Opis drugiej usługi oraz głównej korzyści.</p></article><article class="cms-card"><h3>Usługa 3</h3><p>Opis trzeciej usługi i przykładowych zastosowań.</p></article><article class="cms-card"><h3>Usługa 4</h3><p>Dodatkowy zakres lub wsparcie.</p></article></section><section class="cms-faq"><h2>Najczęstsze pytania</h2><details open><summary>Ile trwa realizacja?</summary><p>Termin zależy od zakresu prac. Skontaktuj się z nami, aby otrzymać konkretną informację.</p></details><details><summary>Czy przygotowujecie indywidualną wycenę?</summary><p>Tak, każdą ofertę dopasowujemy do potrzeb klienta.</p></details></section>'
        },
        landing: {
            title: 'Landing Page',
            slug: 'landing-page',
            html: '<section class="cms-hero" style="text-align:center;background:linear-gradient(135deg,#111827,#7e22ce);color:#fff;"><h1>Przyciągający nagłówek kampanii</h1><p>Krótko opisz największą korzyść dla odbiorcy i zachęć do działania.</p><p><a class="cms-btn" href="#kontakt" style="background:#fff;color:#7e22ce;">Zamów / Skontaktuj się</a></p></section><section class="cms-card-grid"><article class="cms-card"><h3>Korzyść 1</h3><p>Najważniejszy argument.</p></article><article class="cms-card"><h3>Korzyść 2</h3><p>Co klient zyska?</p></article><article class="cms-card"><h3>Korzyść 3</h3><p>Dlaczego warto teraz?</p></article></section><section class="cms-columns"><div><h2>Dla kogo?</h2><p>Opisz grupę odbiorców i problem, który rozwiązujesz.</p></div><div><h2>Co zawiera oferta?</h2><ul><li>element pierwszy,</li><li>element drugi,</li><li>element trzeci.</li></ul></div></section><section id="kontakt" class="cms-contact"><h2>Gotowy do działania?</h2><p><a class="cms-btn" href="mailto:kontakt@example.com">Napisz do nas</a></p></section>'
        },
        faqpage: {
            title: 'FAQ',
            slug: 'faq',
            html: '<section class="cms-hero"><h1>Centrum pomocy / FAQ</h1><p>Najważniejsze pytania i odpowiedzi zebrane w jednym miejscu.</p></section><section class="cms-faq"><h2>Pytania ogólne</h2><details open><summary>Jak mogę skorzystać z oferty?</summary><p>Skontaktuj się z nami telefonicznie lub mailowo. Ustalimy szczegóły i przygotujemy propozycję.</p></details><details><summary>Ile trwa realizacja?</summary><p>Termin zależy od zakresu prac. Standardowo podajemy go po analizie potrzeb.</p></details><details><summary>Czy wystawiacie fakturę?</summary><p>Tak, po realizacji możemy wystawić fakturę zgodnie z ustaleniami.</p></details><details><summary>Czy można zamówić usługę indywidualną?</summary><p>Tak, przygotowujemy również rozwiązania dopasowane do konkretnego przypadku.</p></details></section><section class="cms-contact"><h2>Nie znalazłeś odpowiedzi?</h2><p>Napisz do nas, a odpowiemy możliwie szybko.</p><p><a class="cms-btn" href="/kontakt">Kontakt</a></p></section>'
        }
    };

    document.querySelectorAll('[data-page-preset]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const key = btn.getAttribute('data-page-preset');
            const preset = pagePresets[key];
            if (!preset) return;
            const editor = tinymce.activeEditor || (tinymce.editors && tinymce.editors[0]);
            if (!editor) return;
            const current = (editor.getContent({format:'text'}) || '').trim();
            if (current && current !== 'Wpisz zawartość...' && !confirm('Zastosowanie presetu zastąpi aktualną treść edytora. Kontynuować?')) return;
            editor.setContent(preset.html);
            editor.focus();
            const form = btn.closest('form');
            if (form) {
                const title = form.querySelector('input[name="title"]');
                const slug = form.querySelector('input[name="slug"], input[name="new_slug"]');
                if (title && (!title.value || title.value === 'Nowa strona')) title.value = preset.title;
                if (slug && !slug.value) slug.value = preset.slug;
            }
        });
    });

    document.querySelectorAll('[data-snippet]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const key = btn.getAttribute('data-snippet');
            const html = snippets[key] || '';
            const editor = tinymce.activeEditor || (tinymce.editors && tinymce.editors[0]);
            if (editor && html) {
                editor.execCommand('mceInsertContent', false, html);
                editor.focus();
            }
        });
    });
})();
</script>
<script>
function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert('✅ URL skopiowany do schowka!');
    });
}

// Wyszukiwanie
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('media-search');
    if (search) {
        search.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.media-item').forEach(item => {
                const name = item.textContent.toLowerCase();
                item.style.display = name.includes(term) ? '' : 'none';
            });
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const token = <?php echo json_encode(csrf_token()); ?>;
  document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(form){
    if (!form.querySelector('input[name="csrf_token"]')) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'csrf_token';
      input.value = token;
      form.appendChild(input);
    }
  });
});
</script>

<script>
(function(){
    const buttons = document.querySelectorAll('.settings-tab-btn');
    const panels = document.querySelectorAll('.settings-panel');
    if (!buttons.length || !panels.length) return;
    const storageKey = 'spidercms_settings_active_tab';
    function activate(tabName) {
        buttons.forEach(btn => btn.classList.toggle('active', btn.dataset.settingsTab === tabName));
        panels.forEach(panel => panel.classList.toggle('active', panel.dataset.settingsPanel === tabName));
        try { localStorage.setItem(storageKey, tabName); } catch(e) {}
    }
    buttons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.settingsTab)));
    const params = new URLSearchParams(window.location.search);
    let saved = params.get('settings') || 'general';
    if (!saved) { try { saved = localStorage.getItem(storageKey) || 'general'; } catch(e) {} }
    if (!document.querySelector('.settings-tab-btn[data-settings-tab="' + saved + '"]')) saved = 'general';
    activate(saved);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const list = document.getElementById('footer-columns-list');
    const addBtn = document.getElementById('add-footer-column');
    const template = document.getElementById('footer-column-template');
    if (!list || !addBtn || !template) return;

    function refreshFooterColumnLabels() {
        list.querySelectorAll('.footer-column-item').forEach(function(item, idx){
            const title = item.querySelector('h4');
            if (title) title.textContent = 'Kolumna ' + (idx + 2);
        });
    }

    function bindRemoveButtons() {
        list.querySelectorAll('.remove-footer-column').forEach(function(btn){
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function(){
                const item = btn.closest('.footer-column-item');
                if (item && confirm('Usunąć tę kolumnę stopki?')) {
                    item.remove();
                    refreshFooterColumnLabels();
                }
            });
        });
    }

    addBtn.addEventListener('click', function(){
        if (list.querySelectorAll('.footer-column-item').length >= 12) {
            alert('Maksymalnie można dodać 12 dodatkowych kolumn stopki.');
            return;
        }
        const node = template.content.cloneNode(true);
        list.appendChild(node);
        bindRemoveButtons();
        refreshFooterColumnLabels();
    });

    bindRemoveButtons();
    refreshFooterColumnLabels();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.addEventListener('click', function(event){
        const addBtn = event.target.closest('.add-submenu-btn');
        if (addBtn) {
            event.preventDefault();
            const parent = addBtn.getAttribute('data-parent');
            const list = document.getElementById('submenu-list-' + parent);
            if (!list) return;

            const row = document.createElement('div');
            row.className = 'submenu-row';
            row.innerHTML = '' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – nazwa</label>' +
                    '<input type="text" name="submenu_label[' + parent + '][]" placeholder="np. Projektowanie stron">' +
                '</div>' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – link</label>' +
                    '<input type="text" name="submenu_url[' + parent + '][]" placeholder="/projektowanie-stron">' +
                '</div>' +
                '<div>' +
                    '<label style="font-size:0.85rem; margin-bottom:0.25rem;">Podmenu – ikona</label>' +
                    '<input type="text" name="submenu_icon[' + parent + '][]" placeholder="fa-solid fa-angle-right">' +
                '</div>' +
                '<button type="button" class="remove-submenu-btn"><i class="fa-solid fa-trash"></i></button>';

            list.appendChild(row);
            const firstInput = row.querySelector('input');
            if (firstInput) firstInput.focus();
            return;
        }

        const removeBtn = event.target.closest('.remove-submenu-btn');
        if (removeBtn) {
            event.preventDefault();
            const row = removeBtn.closest('.submenu-row');
            if (row) row.remove();
        }
    });
});
</script>



<script id="spidercms-slider-admin-fixed-js">
document.addEventListener('DOMContentLoaded', function(){
    const list = document.getElementById('slider-images-list');
    const add = document.getElementById('add-slider-image');
    if (!list) return;

    function escAttr(value){
        return String(value || '').replace(/[&<>'"]/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
        });
    }

    function rowHtml(url, title, desc){
        return ''
            + '<div><label>Adres zdjęcia</label><input type="text" name="image_url[]" value="' + escAttr(url || '') + '" placeholder="uploads/zdjecie.jpg"></div>'
            + '<div><label>Tytuł</label><input type="text" name="image_title[]" value="' + escAttr(title || '') + '" placeholder="Opcjonalnie"></div>'
            + '<div><label>Opis</label><input type="text" name="image_desc[]" value="' + escAttr(desc || '') + '" placeholder="Opcjonalnie"></div>'
            + '<button type="button" class="remove-slider-image" style="background:#ef4444;color:white;border:0;border-radius:8px;padding:.75rem;cursor:pointer;"><i class="fa-solid fa-trash"></i></button>';
    }

    function createRow(url, title, desc){
        const row = document.createElement('div');
        row.className = 'slider-image-row';
        row.style.cssText = 'display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:.7rem;align-items:end;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1rem;';
        row.innerHTML = rowHtml(url || '', title || '', desc || '');
        return row;
    }

    function getRows(){ return Array.from(list.querySelectorAll('.slider-image-row')); }

    function rowUrl(row){
        const input = row ? row.querySelector('input[name="image_url[]"]') : null;
        return input ? input.value.trim() : '';
    }

    function addSliderImage(url, title, desc){
        url = String(url || '').trim();
        const rows = getRows();

        // Jeżeli jest tylko jeden pusty wiersz startowy, pierwsze zdjęcie wstawiamy w niego.
        if (url && rows.length === 1 && rowUrl(rows[0]) === '') {
            const u = rows[0].querySelector('input[name="image_url[]"]');
            const t = rows[0].querySelector('input[name="image_title[]"]');
            const d = rows[0].querySelector('input[name="image_desc[]"]');
            if (u) u.value = url;
            if (t) t.value = title || '';
            if (d) d.value = desc || '';
            return rows[0];
        }

        const row = createRow(url, title || '', desc || '');
        list.appendChild(row);
        const first = row.querySelector('input[name="image_url[]"]');
        if (first && !url) first.focus();
        return row;
    }

    if (add && add.dataset.sliderBound !== '1') {
        add.dataset.sliderBound = '1';
        add.addEventListener('click', function(e){
            e.preventDefault();
            addSliderImage('', '', '');
        });
    }

    document.addEventListener('click', function(e){
        const galleryBtn = e.target.closest('.slider-gallery-add');
        if (galleryBtn) {
            e.preventDefault();
            const row = addSliderImage(galleryBtn.dataset.url || '', '', '');
            if (row) {
                row.style.outline = '2px solid #22c55e';
                row.style.outlineOffset = '2px';
                setTimeout(function(){ row.style.outline = ''; row.style.outlineOffset = ''; }, 650);
            }
            galleryBtn.style.outline = '2px solid #22c55e';
            galleryBtn.style.outlineOffset = '2px';
            setTimeout(function(){ galleryBtn.style.outline = ''; galleryBtn.style.outlineOffset = ''; }, 650);
            return;
        }

        const removeBtn = e.target.closest('.remove-slider-image');
        if (removeBtn && list.contains(removeBtn)) {
            e.preventDefault();
            const row = removeBtn.closest('.slider-image-row');
            const rows = getRows();
            if (!row) return;
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach(function(input){ input.value = ''; });
            } else {
                row.remove();
            }
        }
    });
});
</script>

<script>
(function(){
    const btn = document.getElementById('spidercmsMobileMenuBtn');
    const backdrop = document.getElementById('spidercmsSidebarBackdrop');
    const body = document.body;
    function closeMenu(){
        body.classList.remove('spidercms-sidebar-open');
        if (btn) btn.setAttribute('aria-expanded','false');
    }
    function toggleMenu(){
        const open = body.classList.toggle('spidercms-sidebar-open');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (btn) btn.addEventListener('click', toggleMenu);
    if (backdrop) backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeMenu(); });
    document.querySelectorAll('#sidebar a').forEach(function(a){
        a.addEventListener('click', function(){ if (window.innerWidth <= 1024) closeMenu(); });
    });
})();
</script>


<script>
/* SPIDERCMS MOBILE HARD JS START */
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function(){
        var meta = document.querySelector('meta[name="viewport"]');
        if(!meta){
            meta = document.createElement('meta');
            meta.name = 'viewport';
            document.head.appendChild(meta);
        }
        meta.setAttribute('content','width=device-width, initial-scale=1, maximum-scale=1');

        var sidebar = document.querySelector('.sidebar, aside.sidebar, .admin-sidebar, .side-menu, nav.sidebar');
        if(!sidebar) return;

        if(!document.querySelector('.spidercms-mobile-topbar')){
            var topbar = document.createElement('div');
            topbar.className = 'spidercms-mobile-topbar';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'spidercms-mobile-btn';
            btn.setAttribute('aria-label','Otwórz menu');
            btn.textContent = '☰';

            var title = document.createElement('div');
            title.className = 'spidercms-mobile-title';
            title.textContent = 'SpiderCMS';

            topbar.appendChild(btn);
            topbar.appendChild(title);
            document.body.appendChild(topbar);

            var backdrop = document.createElement('div');
            backdrop.className = 'spidercms-mobile-backdrop';
            document.body.appendChild(backdrop);

            function closeMenu(){
                document.body.classList.remove('spidercms-menu-open');
                btn.textContent = '☰';
                btn.setAttribute('aria-label','Otwórz menu');
            }

            function toggleMenu(){
                var open = document.body.classList.toggle('spidercms-menu-open');
                btn.textContent = open ? '×' : '☰';
                btn.setAttribute('aria-label', open ? 'Zamknij menu' : 'Otwórz menu');
            }

            btn.addEventListener('click', toggleMenu);
            backdrop.addEventListener('click', closeMenu);

            sidebar.querySelectorAll('a').forEach(function(link){
                link.addEventListener('click', function(){
                    if(window.innerWidth <= 1024) closeMenu();
                });
            });

            window.addEventListener('keydown', function(e){
                if(e.key === 'Escape') closeMenu();
            });

            window.addEventListener('resize', function(){
                if(window.innerWidth > 1024) closeMenu();
            });
        }
    });
})();
/* SPIDERCMS MOBILE HARD JS END */
</script>







<script>
/* SPIDERCMS LIVE EDITOR FALLBACK BUTTON START */
(function(){
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function(){
        if(document.querySelector('.spidercms-live-edit-btn')) return;

        document.querySelectorAll('tr').forEach(function(row){
            const slugCell = row.querySelector('td code, td .slug, td:first-child code, td:first-child');
            const actionsCell = row.querySelector('td:last-child');
            if(!slugCell || !actionsCell) return;

            const text = slugCell.textContent || '';
            const m = text.match(/[a-zA-Z0-9_-]+\.php/);
            if(!m) return;

            const file = m[0];
            if(actionsCell.querySelector('.spidercms-live-edit-btn')) return;

            const edit = Array.from(actionsCell.querySelectorAll('a,button')).find(el => (el.textContent || '').includes('Edytuj'));
            const a = document.createElement('a');
            a.className = 'btn secondary spidercms-live-edit-btn';
            a.href = 'admin.php?action=live_editor&file=' + encodeURIComponent(file);
            a.textContent = '✨ Edytuj LIVE';

            if(edit) edit.insertAdjacentElement('afterend', a);
            else actionsCell.insertBefore(a, actionsCell.firstChild);
        });
    });
})();
/* SPIDERCMS LIVE EDITOR FALLBACK BUTTON END */
</script>








<script>
/* SPIDERCMS AUTO SCROLL TO EDITOR START */
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function hasEditIntent(){
        const q = new URLSearchParams(window.location.search);
        return q.has('edit') || q.has('page') || q.get('action') === 'edit' || q.get('mode') === 'edit';
    }

    function findEditorTarget(){
        return document.querySelector('#page_content') ||
               document.querySelector('textarea[name="content"]') ||
               document.querySelector('textarea[name="page_content"]') ||
               document.querySelector('.tox-tinymce') ||
               document.querySelector('.tox') ||
               document.querySelector('[data-editor]') ||
               document.querySelector('form textarea');
    }

    function findEditorCard(target){
        if(!target) return null;
        return target.closest('.card,.panel-card,.settings-card,.box,form') || target;
    }

    ready(function(){
        if(!hasEditIntent()) return;

        setTimeout(function(){
            const target = findEditorTarget();
            if(!target) return;

            const card = findEditorCard(target);
            const y = Math.max(0, card.getBoundingClientRect().top + window.scrollY - 20);

            window.scrollTo({
                top: y,
                behavior: 'smooth'
            });

            card.classList.add('spidercms-edit-focus-pulse');
            setTimeout(function(){
                card.classList.remove('spidercms-edit-focus-pulse');
            }, 3400);

            // Jeśli TinyMCE jest już aktywny, ustaw fokus w edytorze.
            setTimeout(function(){
                try{
                    if(window.tinymce && tinymce.activeEditor){
                        tinymce.activeEditor.focus();
                    }else if(target.focus){
                        target.focus();
                    }
                }catch(e){}
            }, 700);
        }, 500);
    });

    // Dodatkowo: po kliknięciu "Edytuj" zapamiętaj, że następna strona ma przewinąć do edytora.
    ready(function(){
        document.querySelectorAll('a,button').forEach(function(el){
            const text = (el.textContent || '').trim().toLowerCase();
            const href = el.getAttribute && (el.getAttribute('href') || '');
            if(text.includes('edytuj') && !text.includes('live')){
                el.addEventListener('click', function(){
                    try{ sessionStorage.setItem('spidercms_scroll_editor_once','1'); }catch(e){}
                });
            }
        });

        let shouldScroll = false;
        try{
            shouldScroll = sessionStorage.getItem('spidercms_scroll_editor_once') === '1';
            if(shouldScroll) sessionStorage.removeItem('spidercms_scroll_editor_once');
        }catch(e){}

        if(!shouldScroll || hasEditIntent()) return;

        setTimeout(function(){
            const target = findEditorTarget();
            if(!target) return;
            const card = findEditorCard(target);
            const y = Math.max(0, card.getBoundingClientRect().top + window.scrollY - 20);
            window.scrollTo({top:y,behavior:'smooth'});
            card.classList.add('spidercms-edit-focus-pulse');
            setTimeout(function(){card.classList.remove('spidercms-edit-focus-pulse');},3400);
        }, 500);
    });
})();
/* SPIDERCMS AUTO SCROLL TO EDITOR END */
</script>





<script>
/* SPIDERCMS HIDE NEW PAGE WHILE EDITING START */
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function isEditingPage(){
        const q = new URLSearchParams(window.location.search);
        return q.has('edit') ||
               q.has('edit_page') ||
               q.get('action') === 'edit' ||
               q.get('action') === 'edit_page' ||
               q.get('mode') === 'edit';
    }

    function lowerText(el){
        return (el && el.textContent ? el.textContent : '').toLowerCase();
    }

    function hideBlock(el){
        if(!el) return;
        const block = el.closest('.card,.panel-card,.settings-card,.box,.section,section,.form-card,.widget-card') || el.closest('form') || el;
        block.classList.add('spidercms-hidden-new-page-during-edit');
    }

    ready(function(){
        if(!isEditingPage()) return;

        // 1. Ukryj formularze, które są jednoznacznie formularzami tworzenia strony.
        document.querySelectorAll('form').forEach(function(form){
            const txt = lowerText(form);
            const action = form.querySelector('input[name="action"]');
            const actionValue = action ? String(action.value || '').toLowerCase() : '';

            const hasCreateAction =
                ['create','add','add_page','new_page','create_page','save_new_page'].includes(actionValue);

            const hasCreateLabels =
                txt.includes('dodaj nową stronę') ||
                txt.includes('dodaj nowa strone') ||
                txt.includes('dodaj stronę') ||
                txt.includes('dodaj strone') ||
                txt.includes('nowa strona') ||
                txt.includes('utwórz stronę') ||
                txt.includes('utworz strone') ||
                txt.includes('stwórz stronę') ||
                txt.includes('stworz strone');

            const hasCreateButton = Array.from(form.querySelectorAll('button,input[type="submit"],.btn')).some(function(btn){
                const b = lowerText(btn);
                const v = (btn.value || '').toLowerCase();
                return b.includes('dodaj') ||
                       b.includes('utwórz') ||
                       b.includes('utworz') ||
                       b.includes('stwórz') ||
                       b.includes('stworz') ||
                       v.includes('dodaj') ||
                       v.includes('utwórz') ||
                       v.includes('utworz');
            });

            const looksLikeEdit =
                txt.includes('edytuj stronę') ||
                txt.includes('edytuj strone') ||
                txt.includes('zapisz zmiany') ||
                txt.includes('aktualizuj stronę') ||
                txt.includes('aktualizuj strone');

            // Ukryj tylko tworzenie, nie formularz edycji.
            if ((hasCreateAction || hasCreateLabels || hasCreateButton) && !looksLikeEdit) {
                hideBlock(form);
            }
        });

        // 2. Ukryj całe karty z nagłówkiem "Dodaj nową stronę", nawet jeżeli formularz ma nietypową akcję.
        document.querySelectorAll('.card,.panel-card,.settings-card,.box,section').forEach(function(card){
            const txt = lowerText(card);
            const hasNewTitle =
                txt.includes('dodaj nową stronę') ||
                txt.includes('dodaj nowa strone') ||
                txt.includes('nowa strona') ||
                txt.includes('utwórz stronę') ||
                txt.includes('utworz strone');

            const hasEditor =
                card.querySelector('#page_content') ||
                card.querySelector('textarea[name="content"]') ||
                card.querySelector('textarea[name="page_content"]') ||
                card.querySelector('.tox-tinymce');

            const looksLikeEdit =
                txt.includes('edytuj stronę') ||
                txt.includes('edytuj strone') ||
                txt.includes('zapisz zmiany');

            if(hasNewTitle && !hasEditor && !looksLikeEdit){
                card.classList.add('spidercms-hidden-new-page-during-edit');
            }
        });

        // 3. Informacja nad edytorem.
        const editor = document.querySelector('#page_content, textarea[name="content"], textarea[name="page_content"], .tox-tinymce, .tox');
        if(editor && !document.querySelector('.spidercms-edit-mode-notice')){
            const container = editor.closest('.card,.panel-card,.settings-card,.box,form') || editor.parentElement;
            if(container){
                const notice = document.createElement('div');
                notice.className = 'spidercms-edit-mode-notice';
                notice.textContent = 'Tryb edycji istniejącej strony — formularz dodawania nowej strony jest ukryty.';
                container.insertBefore(notice, container.firstChild);
            }
        }
    });
})();
/* SPIDERCMS HIDE NEW PAGE WHILE EDITING END */
</script>



<script>
/* SPIDERCMS FINAL HIDE DUPLICATE PAGE FORMS START */
(function(){
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function(){
        const q = new URLSearchParams(location.search);
        const editMode = q.has('edit') || q.has('edit_page') || q.get('action') === 'edit' || q.get('mode') === 'edit';
        if(!editMode) return;

        // Ukryj wszystko oznaczone po stronie PHP.
        document.querySelectorAll('[data-spidercms-purpose="create-page"], .spidercms-create-page-only').forEach(el => {
            const box = el.closest('.card,.box,.panel-card,.settings-card,section') || el;
            box.style.setProperty('display','none','important');
        });

        // Jeżeli nadal są dwa formularze z polami tytuł/slug/content, zostaw pierwszy z edytorem jako formularz edycji.
        const pageForms = Array.from(document.querySelectorAll('form')).filter(form => {
            const hasContent = form.querySelector('textarea[name="content"], textarea[name="page_content"], #page_content');
            const hasTitle = form.querySelector('input[name="title"], input[name="page_title"]');
            const hasSlug = form.querySelector('input[name="slug"], input[name="page_slug"]');
            return hasContent || (hasTitle && hasSlug);
        });

        if(pageForms.length > 1){
            pageForms.slice(1).forEach(form => {
                const box = form.closest('.card,.box,.panel-card,.settings-card,section') || form;
                box.style.setProperty('display','none','important');
            });
        }

        // Ukryj karty z nagłówkiem dodawania po samym tytule.
        document.querySelectorAll('.card,.box,.panel-card,.settings-card,section').forEach(box => {
            const t = (box.textContent || '').toLowerCase();
            const isAdd = t.includes('dodaj nową stronę') || t.includes('dodaj nowa strone') || t.includes('nowa strona') || t.includes('utwórz stronę') || t.includes('utworz strone');
            const isEdit = t.includes('edytuj stronę') || t.includes('edytuj strone');
            if(isAdd && !isEdit){
                box.style.setProperty('display','none','important');
            }
        });
    });
})();
/* SPIDERCMS FINAL HIDE DUPLICATE PAGE FORMS END */
</script>



<script>
/* SPIDERCMS LIVE REMOVE WRONG BOOKING LINKS START */
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }
    ready(function(){
        document.querySelectorAll('.live-topbar a[href*="tab=bookings"], .live-actions a[href*="tab=bookings"]').forEach(function(a){
            a.remove();
        });
    });
})();
/* SPIDERCMS LIVE REMOVE WRONG BOOKING LINKS END */
</script>



<script>

</script>



<script>
/* SPIDERCMS MOBILE LAYOUT WIDTH FIX START */
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function(){
        if(document.querySelector('.live-shell')) return;

        // Jeżeli istnieje już hamburger z wcześniejszego panelu, używamy go.
        var btn = document.querySelector('.mobile-menu-toggle, .spidercms-mobile-toggle, .spider-mobile-menu-btn, #spidercmsMobileSidebarBtn, #spidercmsMobileBtn');
        var sidebar = document.querySelector('.sidebar, aside.sidebar, .admin-sidebar, .side-menu');
        if(!sidebar) return;

        // Jeśli nie ma hamburgera, tworzymy minimalny.
        if(!btn){
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mobile-menu-toggle';
            btn.setAttribute('aria-label','Otwórz menu');
            btn.textContent = '☰';
            btn.style.cssText = 'display:none;position:fixed;top:14px;left:14px;width:44px;height:44px;border:0;border-radius:14px;background:linear-gradient(135deg,#a855f7,#2563eb);color:#fff;font-size:24px;font-weight:900;z-index:100000;align-items:center;justify-content:center;';
            document.body.appendChild(btn);

            var st = document.createElement('style');
            st.textContent = '@media(max-width:768px){.mobile-menu-toggle{display:flex!important}}';
            document.head.appendChild(st);
        }

        var backdrop = document.querySelector('.mobile-menu-backdrop, .spidercms-mobile-backdrop, .spider-mobile-backdrop');
        if(!backdrop){
            backdrop = document.createElement('div');
            backdrop.className = 'mobile-menu-backdrop';
            backdrop.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(2,6,23,.65);z-index:99990;';
            document.body.appendChild(backdrop);

            var bst = document.createElement('style');
            bst.textContent = '@media(max-width:768px){body.sidebar-open .mobile-menu-backdrop{display:block!important}}';
            document.head.appendChild(bst);
        }

        function isOpen(){
            return document.body.classList.contains('sidebar-open') ||
                   document.body.classList.contains('spidercms-menu-open') ||
                   document.body.classList.contains('spider-menu-open') ||
                   document.body.classList.contains('spidercms-mobile-sidebar-open');
        }

        function openMenu(){
            document.body.classList.add('sidebar-open');
            btn.textContent = '×';
        }

        function closeMenu(){
            document.body.classList.remove('sidebar-open','spidercms-menu-open','spider-menu-open','spidercms-mobile-sidebar-open');
            btn.textContent = '☰';
        }

        function toggle(e){
            if(e){
                e.preventDefault();
                e.stopPropagation();
            }
            if(isOpen()) closeMenu();
            else openMenu();
        }

        btn.addEventListener('click', toggle);
        btn.addEventListener('touchstart', toggle, {passive:false});

        backdrop.addEventListener('click', closeMenu);
        backdrop.addEventListener('touchstart', function(e){
            e.preventDefault();
            closeMenu();
        }, {passive:false});

        sidebar.querySelectorAll('a').forEach(function(a){
            a.addEventListener('click', function(){
                if(window.innerWidth <= 768) closeMenu();
            });
        });

        window.addEventListener('resize', function(){
            if(window.innerWidth > 768) closeMenu();
        });
    });
})();
/* SPIDERCMS MOBILE LAYOUT WIDTH FIX END */
</script>

<!-- Emergency users sidebar injection -->

<script>
document.addEventListener('DOMContentLoaded', function(){
    var nav = document.querySelector('#sidebar nav') || document.querySelector('aside nav') || document.querySelector('nav');
    if (!nav || nav.querySelector('a[href*="tab=uzytkownicy"]')) return;

    var a = document.createElement('a');
    a.href = 'admin.php?tab=uzytkownicy';
    a.innerHTML = '<i class="fa-solid fa-users-gear"></i> Użytkownicy';
    a.style.color = '#c084fc';

    try {
        if (new URLSearchParams(location.search).get('tab') === 'uzytkownicy') {
            a.classList.add('active');
        }
    } catch(e) {}

    var logi = nav.querySelector('a[href*="tab=logi"]');
    if (logi) nav.insertBefore(a, logi);
    else nav.appendChild(a);
});
</script>



<script>
document.addEventListener('DOMContentLoaded', function(){
    var role = <?= json_encode(spidercms_admin_current_role(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    if (role === 'admin') return;

    document.querySelectorAll('aside nav a, #sidebar nav a, nav a').forEach(function(a){
        var href = a.getAttribute('href') || '';
        if (href.indexOf('tab=ustawienia') !== -1 || href.indexOf('settings=security') !== -1 || href.indexOf('settings=social') !== -1) {
            a.remove();
        }
    });
});
</script>

</body>
</html>