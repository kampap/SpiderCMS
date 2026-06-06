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
// Wersja: czerwiec 2026
// ======================================================================

session_start();

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
define('BASE_URL', '/litecard-cms/'); // ← zmień jeśli folder nazywa się inaczej

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
    'col1_title' => 'Kontakt',
    'col1_content' => 'Email: kontakt@example.com',
    'col2_title' => 'Linki',
    'col2_content' => '<a href="/polityka-privacy">Polityka prywatności</a>'
];
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
    $files = glob(PAGES_DIR . '/*.php');
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
    $content .= "\$target = rtrim(PAGES_URL, '/') . '/' . \$homepage . '.php';\n";
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
    // AKCJA: TWORZENIE STRONY
    if ($action === 'create') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', trim(strtolower($_POST['slug'] ?? '')));
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        if ($slug && $title) {
            $file = PAGES_DIR . '/' . $slug . '.php';
            if (file_exists($file)) {
                $toast = ['type'=>'error', 'msg'=>'Taki slug już istnieje'];
            } else {
                $template = <<<'PHP'
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../header.php';
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
require_once __DIR__ . '/../footer.php';
?>
</body>
</html>
PHP;
                $template = str_replace('__TITLE__', addslashes($title), $template);
                $template = str_replace('__CONTENT__', $content, $template);
                file_put_contents($file, $template);
                $toast = ['type'=>'success', 'msg'=>"Utworzono stronę /$slug"];
            }
        } else {
            $toast = ['type'=>'error', 'msg'=>'Slug i tytuł są wymagane'];
        }
    }
    // AKCJA: USTAWIENIE STRONY GŁÓWNEJ
    if ($action === 'set_homepage') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '', trim($_POST['slug'] ?? $_POST['homepage_slug'] ?? ''));
        $file = PAGES_DIR . '/' . $slug . '.php';
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
        $file = PAGES_DIR . '/' . $slug . '.php';
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
            $file = PAGES_DIR . '/' . $slug . '.php';
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

        $footer_save = [
            'copyright' => trim($_POST['footer_copyright'] ?? ''),
            'about_text' => trim($_POST['footer_about_text'] ?? ''),
            'col1_title' => trim($_POST['footer_col1_title'] ?? ''),
            'col1_content' => $_POST['footer_col1_content'] ?? '',
            'col2_title' => trim($_POST['footer_col2_title'] ?? ''),
            'col2_content' => $_POST['footer_col2_content'] ?? '',
        ];
        file_put_contents($footer_file, json_encode($footer_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $global_footer_content = <<<'PHP'
<?php
if (!file_exists(__DIR__ . '/.footer_enabled')) {
    return;
}
$f_data = file_exists(__DIR__ . '/.footer.json') ? json_decode(file_get_contents(__DIR__ . '/.footer.json'), true) : [];
?>
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-col">
      <h4>O nas</h4>
      <p><?php echo htmlspecialchars($f_data['about_text'] ?? ''); ?></p>
    </div>
    <?php if(!empty($f_data['col1_title'])): ?>
    <div class="footer-col">
      <h4><?php echo htmlspecialchars($f_data['col1_title']); ?></h4>
      <p><?php echo $f_data['col1_content'] ?? ''; ?></p>
    </div>
    <?php endif; ?>
    <?php if(!empty($f_data['col2_title'])): ?>
    <div class="footer-col">
      <h4><?php echo htmlspecialchars($f_data['col2_title']); ?></h4>
      <p><?php echo $f_data['col2_content'] ?? ''; ?></p>
    </div>
    <?php endif; ?>
  </div>
  <div class="footer-bottom">
    <?php echo htmlspecialchars($f_data['copyright'] ?? ''); ?>
  </div>
</footer>
PHP;
        file_put_contents(__DIR__ . '/footer.php', $global_footer_content);
        $pages_files = glob(PAGES_DIR . '/*.php');
        foreach ($pages_files as $p_file) {
            $p_content = file_get_contents($p_file);
            if (strpos($p_content, 'require_once __DIR__ . \'/../footer.php\';') === false) {
                $pattern = '/<\?php\s*\/\/ NAPRAWIONO ŚCIEŻKĘ.*?\?>\s*<footer class="site-footer">.*?<\/footer>/s';
                if (preg_match($pattern, $p_content)) {
                    $p_content = preg_replace($pattern, "<?php require_once __DIR__ . '/../footer.php'; ?>", $p_content);
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
            foreach (glob(PAGES_DIR . '/*.php') as $file) {
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
        if (isset($_FILES['media_files']['tmp_name'])) {
            foreach ($_FILES['media_files']['tmp_name'] as $i => $tmp) {
                if ($_FILES['media_files']['error'][$i] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['media_files']['name'][$i];
                    $safe_name = preg_replace('/[^a-zA-Z0-9\._-]/', '-', $original_name);
                    if (move_uploaded_file($tmp, $uploads_dir . $safe_name)) {
                        $uploaded++;
                    }
                }
            }
        }
        $toast = ['type' => $uploaded > 0 ? 'success' : 'error', 'msg' => $uploaded > 0 ? "$uploaded plik(ów) wgrano pomyślnie" : 'Błąd podczas wgrywania plików'];
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
}

// ----------------------------------------------------------------------
// Widok panelu – zbieranie danych
// ----------------------------------------------------------------------
$menu_enabled = file_exists(__DIR__ . '/.menu_enabled');
$menu_items = json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true);
$pages = [];
foreach (glob(PAGES_DIR . '/*.php') ?: [] as $f) {
    $slug = basename($f, '.php');
    $pages[] = ['slug' => $slug, 'modified' => date('Y-m-d H:i', filemtime($f))];
}
// Media Library - musi być zawsze zdefiniowane
$media_files = get_media_files();
// Jeżeli wskazana strona główna nie istnieje, wracamy do index.php albo pierwszej dostępnej strony.
$page_slugs = array_column($pages, 'slug');
if (!in_array($homepage_slug, $page_slugs, true)) {
    $homepage_slug = in_array('index', $page_slugs, true) ? 'index' : ($page_slugs[0] ?? 'index');
    file_put_contents(__DIR__ . '/.homepage', $homepage_slug);
}

$edit_slug = $_GET['edit'] ?? '';
$edit_content = '';
if ($edit_slug && file_exists($f = PAGES_DIR . '/' . $edit_slug . '.php')) {
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
                    $time = @filemtime(PAGES_DIR . '/' . $p['slug'] . '.php');
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
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:2rem; margin-top:1.5rem;">
                    <div>
                        <h3 style="color: var(--primary); font-size: 1.1rem;">Kolumna 2</h3>
                        <label>Tytuł kolumny</label>
                        <input type="text" name="footer_col1_title" value="<?= htmlspecialchars($footer_data['col1_title'] ?? '') ?>" placeholder="Kontakt">
                        <label>Zawartość HTML / Tekst</label>
                        <textarea name="footer_col1_content" class="input-field" rows="4" placeholder="np. Email: biuro@example.com"><?= htmlspecialchars($footer_data['col1_content'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <h3 style="color: var(--primary); font-size: 1.1rem;">Kolumna 3</h3>
                        <label>Tytuł kolumny</label>
                        <input type="text" name="footer_col2_title" value="<?= htmlspecialchars($footer_data['col2_title'] ?? '') ?>" placeholder="Linki">
                        <label>Zawartość HTML / Tekst</label>
                        <textarea name="footer_col2_content" class="input-field" rows="4" placeholder="np. &lt;a href='/polityka'&gt;Polityka prywatności&lt;/a&gt;"><?= htmlspecialchars($footer_data['col2_content'] ?? '') ?></textarea>
                    </div>
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
		
    <?php elseif ($tab === 'ustawienia'): ?>
        <div class="card card-settings">
            <h2 style="margin-top:0; color:var(--settings-color);"><i class="fa-solid fa-gear" style="margin-right:0.6rem;"></i> Ustawienia witryny</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">
                <label for="site_name">Nazwa witryny</label>
                <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>" required>

                <div style="margin:2rem 0; padding:1.2rem; border:1px solid var(--gray-200); border-radius:10px; background:#0f172a;">
                    <h3 style="margin:0 0 0.8rem; color:#fbbf24;"><i class="fa-solid fa-house-chimney"></i> Strona główna witryny</h3>
                    <p style="color:#94a3b8; margin:0 0 1rem;">Aktualnie jako strona główna ustawiona jest: <strong style="color:#f8fafc;"><?= htmlspecialchars($homepage_slug) ?>.php</strong></p>
                    <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                        <div style="flex:1; min-width:240px;">
                            <label for="homepage_slug" style="margin-top:0;">Wybierz stronę główną</label>
                            <select id="homepage_slug" name="homepage_slug" style="width:100%;padding:0.75rem 1rem;border:1px solid var(--gray-200);background:#0f172a;color:#f8fafc;border-radius:6px;">
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?= htmlspecialchars($page['slug']) ?>" <?= $page['slug'] === $homepage_slug ? 'selected' : '' ?>><?= htmlspecialchars($page['slug']) ?>.php</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="action" value="set_homepage" style="background:#f59e0b;"><i class="fa-solid fa-star"></i> Ustaw jako główną</button>
                    </div>
                </div>
                <div style="margin:2.5rem 0 1.5rem; font-weight:600; color:#94a3b8;">Główne kolory</div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem;">
                    <div>
                        <label for="primary">Kolor główny (--primary)</label>
                        <div class="color-preview">
                            <input type="color" id="primary" name="primary" value="<?= htmlspecialchars($theme['primary'] ?? '#a855f7') ?>">
                            <span><?= htmlspecialchars($theme['primary'] ?? '#a855f7') ?></span>
                        </div>
                    </div>
                    <div>
                        <label for="primary_dark">Kolor główny ciemny (--primary-dark)</label>
                        <div class="color-preview">
                            <input type="color" id="primary_dark" name="primary_dark" value="<?= htmlspecialchars($theme['primary-dark'] ?? '#7e22ce') ?>">
                            <span><?= htmlspecialchars($theme['primary-dark'] ?? '#7e22ce') ?></span>
                        </div>
                    </div>
                    <div>
                        <label for="accent">Kolor akcentujący (--accent)</label>
                        <div class="color-preview">
                            <input type="color" id="accent" name="accent" value="<?= htmlspecialchars($theme['accent'] ?? '#2563eb') ?>">
                            <span><?= htmlspecialchars($theme['accent'] ?? '#2563eb') ?></span>
                        </div>
                    </div>
                </div>

                <div style="margin:2.5rem 0 1.5rem; font-weight:600; color:#94a3b8;">Kolory strony, nagłówka i stopki</div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem;">
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

                <div style="margin:2.5rem 0 1.5rem; font-weight:600; color:#94a3b8;">Style i układ</div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem;">
                    <div><label for="font_family">Font CSS</label><input type="text" id="font_family" name="font_family" value="<?= htmlspecialchars(theme_value('font-family', 'system-ui, sans-serif')) ?>"></div>
                    <div><label for="header_height">Wysokość nagłówka [px]</label><input type="text" id="header_height" name="header_height" value="<?= htmlspecialchars(theme_value('header-height', '74')) ?>"></div>
                    <div><label for="logo_height">Maks. wysokość logo [px]</label><input type="text" id="logo_height" name="logo_height" value="<?= htmlspecialchars(theme_value('logo-height', '100')) ?>"></div>
                    <div><label for="content_width">Szerokość treści [px]</label><input type="text" id="content_width" name="content_width" value="<?= htmlspecialchars(theme_value('content-width', '1240')) ?>"></div>
                    <div><label for="border_radius">Zaokrąglenia [px]</label><input type="text" id="border_radius" name="border_radius" value="<?= htmlspecialchars(theme_value('border-radius', '10')) ?>"></div>
                    <div><label style="display:flex;align-items:center;gap:0.8rem;margin-top:2.1rem;"><input type="checkbox" name="shadow_enabled" <?= theme_value('shadow-enabled', '1') === '1' ? 'checked' : '' ?> style="width:auto;transform:scale(1.2);"> Cień nagłówka</label></div>
                </div>
                <div style="margin-top:3rem;">
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
                <div style="margin-top:3rem; text-align:center;"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz ustawienia</button></div>
            </form>

            <!-- ==================== ZMIANA HASŁA ==================== -->
            <div style="margin: 3rem 0 2rem; padding: 1.8rem; border: 2px solid #334155; border-radius: 12px; background: #0f172a;">
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

                    <div style="margin-top: 1.8rem;">
                        <button type="submit" style="background:#ef4444;">Zmień hasło</button>
                    </div>
                </form>
            </div>

            <form method="post" style="margin-top:2rem; text-align:center; border-top:1px solid var(--gray-200); padding-top:1.5rem;">
                <input type="hidden" name="action" value="export_all">
                <button type="submit" class="btn-full-export"><i class="fa-solid fa-download"></i> Eksport całej witryny (ZIP)</button>
            </form>
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
                    <td><a href="<?= htmlspecialchars(PAGES_URL . $page['slug'] . '.php') ?>" target="_blank" class="btn btn-view"><i class="fa-solid fa-eye"></i> Podgląd</a></td>
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
</body>
</html>