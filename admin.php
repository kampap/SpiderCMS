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
// Obsługa wylogowania
// ----------------------------------------------------------------------
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

require_once __DIR__ . '/config.php';

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
// Publiczne endpointy czatu – działają bez logowania do panelu
// ----------------------------------------------------------------------
$public_action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($public_action === 'chat_public_send') {
    chat_send_json(chat_public_add_message($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['message'] ?? '', $_POST['website'] ?? ''));
}
if ($public_action === 'chat_public_get') {
    chat_send_json(chat_public_get_messages());
}

// ----------------------------------------------------------------------
// Ekran logowania
// ----------------------------------------------------------------------
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && $_SESSION['login_block_until'] <= time()) {
        if (password_verify($_POST['password'], $ADMIN_HASH)) {
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_block_until'] = 0;
            header('Location: admin.php?tab=dashboard');
            exit;
        } else {
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
                    <input type="password" name="password" placeholder="Hasło" required autofocus>
                    <button type="submit">Zaloguj się</button>
                </form>
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

// ----------------------------------------------------------------------
// Obsługa POST
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    verify_csrf_or_die();
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
require_once dirname(__DIR__, __ROOT_DEPTH__) . '/header.php';
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
    .site-header{position:fixed;top:0;left:0;right:0;background:var(--header-bg);box-shadow:var(--header-shadow);z-index:1000;text-align:left;}
    .header-container{max-width:var(--content-width);margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:var(--header-height);text-align:left;}
    .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;justify-content:flex-start;text-align:left;margin-right:auto;}
    .logo img{max-height:var(--logo-height);width:auto;display:block;margin:0;}
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
    main{margin-top:calc(var(--header-height) + 16px);padding:2rem 1rem;flex:1;}
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
    let saved = 'general';
    try { saved = localStorage.getItem(storageKey) || 'general'; } catch(e) {}
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
        $slug = trim($_POST['slug'] ?? '');
        $file = ACTIVE_PAGES_DIR . '/' . $slug . '.php';
        if (file_exists($file)) {
            $new_content = $_POST['content'] ?? '';
            $old = file_get_contents($file);
            if (preg_match('/\$content\s*=\s*<<<HTML\s*(.*?)\s*HTML;/s', $old, $m)) {
                $updated = str_replace($m[1], $new_content, $old);
                file_put_contents($file, $updated);
                $toast = ['type'=>'success', 'msg'=>'Zapisano zmiany'];
            } else {
                $toast = ['type'=>'error', 'msg'=>'Nie znaleziono bloku treści'];
            }
        } else {
            $toast = ['type'=>'error', 'msg'=>'Strona nie istnieje'];
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
    // AKCJA: ZAPIS MENU
    if ($action === 'save_menu') {
        $enabled = !empty($_POST['menu_enabled']);
        if ($enabled) {
            file_put_contents(__DIR__ . '/.menu_enabled', '1');
        } else {
            @unlink(__DIR__ . '/.menu_enabled');
        }
        $items = [];
        foreach (($_POST['menu_label'] ?? []) as $i => $label) {
            $label = trim($label);
            $url = trim($_POST['menu_url'][$i] ?? '');
            $icon = trim($_POST['menu_icon'][$i] ?? '');
            if ($label && $url) {
                $items[] = ['label' => $label, 'url' => $url, 'icon' => $icon];
            }
        }
        file_put_contents(__DIR__ . '/.menu.json', json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
                    $logo_path = BASE_URL . 'uploads/' . $safe_name;
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
            ];
            file_put_contents(__DIR__ . '/.theme.json', json_encode($theme_data, JSON_PRETTY_PRINT));
            $requested_page_folder = spidercms_sanitize_page_folder($_POST['page_folder'] ?? ($GLOBALS['active_page_folder'] ?? 'pages'));
            $requested_page_dir = spidercms_page_folder_dir($requested_page_folder);
            if (!is_dir($requested_page_dir)) { @mkdir($requested_page_dir, 0755, true); }
            file_put_contents($GLOBALS['page_folder_file'], json_encode(['folder' => $requested_page_folder], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $settings['logo'] = $logo_path;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
            $updated_pages = update_all_pages_colors();
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
        ];
        file_put_contents($chat_settings_file, json_encode($chat_settings_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $chat_settings = array_merge($chat_settings_defaults, $chat_settings_save);
        chat_write_widget_file();
        $changed = chat_sync_widget_in_pages();
        $toast = ['type' => 'success', 'msg' => 'Zapisano ustawienia czatu. Zsynchronizowano podstrony: ' . $changed];
        header('Location: admin.php?tab=chat');
        exit;
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
$chat_conversations = chat_load_conversations();
chat_backfill_archive_from_conversations();
$chat_archive_index = chat_load_archive_index();
$chat_unread = chat_unread_count();
// Jeżeli wskazana strona główna nie istnieje, wracamy do index.php albo pierwszej dostępnej strony.
$page_slugs = array_column($pages, 'slug');
if (!in_array($homepage_slug, $page_slugs, true)) {
    $homepage_slug = in_array('index', $page_slugs, true) ? 'index' : ($page_slugs[0] ?? 'index');
    file_put_contents(__DIR__ . '/.homepage', $homepage_slug);
}

$edit_slug = $_GET['edit'] ?? '';
$edit_content = '';
if ($edit_slug && file_exists($f = ACTIVE_PAGES_DIR . '/' . $edit_slug . '.php')) {
    $raw = file_get_contents($f);
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
            --sidebar: 260px;
            --menu-color: #c084fc;
            --footer-color: #fb923c;
            --settings-color: #60a5fa;
            --about-color: #f472b6;
            --chat-color: #22c55e;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:system-ui,sans-serif; background:var(--gray-50); color:#f8fafc; min-height:100vh; display:flex; }
        #sidebar { width:var(--sidebar); background:#0f172a; border-right:1px solid var(--gray-200); height:100vh; position:fixed; overflow-y:auto; }
        #sidebar-header { padding:1.2rem 1.4rem; font-size:1.45rem; font-weight:700; color:var(--primary); border-bottom:1px solid var(--gray-200); display:flex; align-items:center; gap:0.6rem; }
        #sidebar-header img { max-height:80px; width:auto; border-radius:4px; }
        #sidebar a { display:flex; align-items:center; gap:0.8rem; padding:0.95rem 1.4rem; color:#94a3b8; text-decoration:none; transition:0.15s; }
        #sidebar a:hover, #sidebar a.active { background:var(--gray-100); color:var(--primary); }
        #sidebar .menu-tab { color:var(--menu-color); }
        #sidebar .settings-tab { color:var(--settings-color); }
        #sidebar .about-tab { color:var(--about-color); }
        #sidebar .footer-tab { color:var(--footer-color); }
        #sidebar .menu-tab.active, #sidebar .settings-tab.active, #sidebar .about-tab.active, #sidebar .footer-tab.active { background:var(--gray-100); font-weight:600; }
        #main { margin-left:var(--sidebar); flex:1; padding:2rem 2.4rem; }
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
        @media (max-width: 980px) { .chat-layout { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<aside id="sidebar">
    <div id="sidebar-header">
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo">
        <?php endif; ?>
        SpiderCMS
    </div>
    <nav>
        <a href="/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Strona główna</a>
        <a href="admin.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="admin.php?tab=strony" class="<?= $tab === 'strony' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i> Strony</a>
        <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active menu-tab' : 'menu-tab' ?>"><i class="fa-solid fa-bars"></i> Menu</a>
        <a href="admin.php?tab=stopka" class="<?= $tab === 'stopka' ? 'active footer-tab' : 'footer-tab' ?>"><i class="fa-solid fa-shoe-prints"></i> Stopka</a>
		<a href="admin.php?tab=media" class="<?= $tab === 'media' ? 'active' : '' ?>" style="color:#34d399;"><i class="fa-solid fa-images"></i> Media</a>
        <a href="admin.php?tab=chat" class="<?= $tab === 'chat' ? 'active' : '' ?>" style="color:#22c55e;"><i class="fa-solid fa-comments"></i> Chat <?php if (($chat_unread ?? 0) > 0): ?><span class="chat-unread-badge"><?= (int)$chat_unread ?></span><?php endif; ?></a>
        <a href="admin.php?tab=ustawienia" class="<?= $tab === 'ustawienia' ? 'active settings-tab' : 'settings-tab' ?>"><i class="fa-solid fa-gear"></i> Ustawienia</a>
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
            <div class="dash-card">
                <h3>Szybkie akcje</h3>
                <div style="margin-top:1rem; display:flex; flex-direction:column; gap:0.8rem;">
                    <a href="admin.php?tab=strony" class="btn btn-view" style="text-align:center; justify-content:center;">
                        <i class="fa-solid fa-plus"></i> Dodaj nową stronę
                    </a>
                    <a href="admin.php?tab=ustawienia" class="btn btn-edit" style="text-align:center; justify-content:center;">
                        <i class="fa-solid fa-palette"></i> Zmień kolory / logo
                    </a>
                </div>
            </div>
        </div>
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
        <div class="card">
            <h2 style="color:var(--chat-color);"><i class="fa-solid fa-comments"></i> Chat z odwiedzającymi</h2>
            <p style="color:#94a3b8;margin:0.6rem 0 1.2rem;">Widget czatu pojawia się na publicznych podstronach. Rozmowy aktywne są w <code>.chat/conversations.json</code>, a pełna historia każdej wiadomości jest dopisywana do <code>.chat/archive.jsonl</code>.</p>
            <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;align-items:end;margin-bottom:1.4rem;">
                <input type="hidden" name="action" value="save_chat_settings">
                <label style="display:flex;align-items:center;gap:0.7rem;margin:0;">
                    <input type="checkbox" name="chat_enabled" <?= !empty($chat_settings['enabled']) ? 'checked' : '' ?> style="width:auto;transform:scale(1.25);">
                    <strong>Włącz chat na stronie</strong>
                </label>
                <div><label style="margin-top:0;">Tytuł</label><input type="text" name="chat_title" value="<?= htmlspecialchars($chat_settings['title'] ?? '') ?>"></div>
                <div><label style="margin-top:0;">Podtytuł</label><input type="text" name="chat_subtitle" value="<?= htmlspecialchars($chat_settings['subtitle'] ?? '') ?>"></div>
                <div><label style="margin-top:0;">Tekst przycisku</label><input type="text" name="chat_button_text" value="<?= htmlspecialchars($chat_settings['button_text'] ?? '') ?>"></div>
                <div><label style="margin-top:0;">Nazwa administratora</label><input type="text" name="chat_admin_name" value="<?= htmlspecialchars($chat_settings['admin_name'] ?? '') ?>"></div>
                <div style="grid-column:1/-1;"><label>Wiadomość powitalna</label><input type="text" name="chat_welcome" value="<?= htmlspecialchars($chat_settings['welcome'] ?? '') ?>"></div>
                <div><button type="submit"><i class="fa-solid fa-save"></i> Zapisz ustawienia czatu</button></div>
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

    <?php elseif ($tab === 'ustawienia'): ?>
        <div class="card card-settings settings-tabs-card">
            <div class="settings-header-row">
                <div>
                    <h2 style="margin-top:0; color:var(--settings-color);"><i class="fa-solid fa-gear" style="margin-right:0.6rem;"></i> Ustawienia witryny</h2>
                    <p style="color:#94a3b8;margin:0.4rem 0 0;">Ustawienia zostały podzielone na podzakładki, aby nie trzeba było przewijać jednej długiej strony.</p>
                </div>
                <button type="button" class="settings-save-floating" onclick="document.getElementById('settings-main-form').requestSubmit();"><i class="fa-solid fa-floppy-disk"></i> Zapisz ustawienia</button>
            </div>

            <div class="settings-tabs" role="tablist" aria-label="Podzakładki ustawień SpiderCMS">
                <button type="button" class="settings-tab-btn active" data-settings-tab="general"><i class="fa-solid fa-sliders"></i> Ogólne</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="appearance"><i class="fa-solid fa-palette"></i> Wygląd</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="layout"><i class="fa-solid fa-ruler-combined"></i> Układ</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="media-logo"><i class="fa-solid fa-image"></i> Logo</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="social"><i class="fa-solid fa-share-nodes"></i> Social Media</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="security"><i class="fa-solid fa-shield-halved"></i> Bezpieczeństwo</button>
                <button type="button" class="settings-tab-btn" data-settings-tab="advanced"><i class="fa-solid fa-screwdriver-wrench"></i> Zaawansowane</button>
            </div>

            <form id="settings-main-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">

                <section class="settings-panel active" data-settings-panel="general">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-sliders"></i> Ustawienia ogólne</h3>
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
                        <h3><i class="fa-solid fa-palette"></i> Wygląd i kolory</h3>
                        <p>Kolory globalne, tło strony, linki, przyciski, nagłówek i stopka.</p>
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
                        <h3><i class="fa-solid fa-ruler-combined"></i> Układ i styl komponentów</h3>
                        <p>Czcionka, wysokość nagłówka, rozmiar logo, szerokość treści i zaokrąglenia.</p>
                    </div>
                    <div class="settings-grid">
                        <div><label for="font_family">Font CSS</label><input type="text" id="font_family" name="font_family" value="<?= htmlspecialchars(theme_value('font-family', 'system-ui, sans-serif')) ?>"></div>
                        <div><label for="header_height">Wysokość nagłówka [px]</label><input type="text" id="header_height" name="header_height" value="<?= htmlspecialchars(theme_value('header-height', '74')) ?>"></div>
                        <div><label for="logo_height">Maks. wysokość logo [px]</label><input type="text" id="logo_height" name="logo_height" value="<?= htmlspecialchars(theme_value('logo-height', '100')) ?>"></div>
                        <div><label for="content_width">Szerokość treści [px]</label><input type="text" id="content_width" name="content_width" value="<?= htmlspecialchars(theme_value('content-width', '1240')) ?>"></div>
                        <div><label for="border_radius">Zaokrąglenia [px]</label><input type="text" id="border_radius" name="border_radius" value="<?= htmlspecialchars(theme_value('border-radius', '10')) ?>"></div>
                        <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="shadow_enabled" <?= theme_value('shadow-enabled', '1') === '1' ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Cień nagłówka</label></div>
                    </div>
                    <div class="settings-actions-bottom"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz układ</button></div>
                </section>

                <section class="settings-panel" data-settings-panel="media-logo">
                    <div class="settings-panel-title">
                        <h3><i class="fa-solid fa-image"></i> Logo witryny</h3>
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
                <div class="settings-security-box">
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
                    <?php for ($i = 0; $i < 8; $i++): $item = $menu_items[$i] ?? ['label' => '', 'url' => '', 'icon' => '']; ?>
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
                    <?php endfor; ?>
                </div>
                <div style="margin-top:2.5rem;"><button type="submit"><i class="fa-solid fa-save"></i> Zapisz menu</button></div>
            </form>
        </div>
    <?php elseif ($tab === 'o-cms'): ?>
        <div class="card card-about">
            <h2 style="margin-top:0; color:var(--about-color);"><i class="fa-solid fa-info-circle" style="margin-right:0.6rem;"></i> O tym CMS-ie</h2>
            <p style="margin:1.5rem 0; line-height:1.7;">
                <strong>SpiderCMS</strong> to ultra-lekki, plikowy system zarządzania treścią (Flat-File) stworzony z myślą o wydajności i prostocie.
            </p>
            <div style="margin-top:2.5rem; text-align:center; border-top:1px solid var(--gray-200); padding-top:1.5rem;">
                <p style="color:#94a3b8;">Wersja: 1.1 Cyber-Update | Autor: [Kamil Paprota]</p>
                <p style="color:#6b7280;">© <?= date('Y') ?> SpiderCMS – wszystkie prawa zastrzeżone</p>
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
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="slug" value="<?= htmlspecialchars($edit_slug) ?>">
                <?php render_editor_tools(); ?>
                <textarea name="content" class="editor"><?= htmlspecialchars($edit_content) ?></textarea>
                <div style="margin-top:1.6rem;">
                    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz zmiany</button>
                    <a href="admin.php" style="margin-left:1.2rem;color:#94a3b8;text-decoration:none;">Anuluj</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <div class="card">
            <h2>Nowa strona</h2>
            <form method="post">
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
    let saved = 'general';
    try { saved = localStorage.getItem(storageKey) || 'general'; } catch(e) {}
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

</body>
</html>