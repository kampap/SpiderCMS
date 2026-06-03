<?php
// admin.php – panel administracyjny SpiderCMS
// Dostosowano styl wizualny do mrocznego, neonowego logo systemu
// Dodano wyświetlanie logo przy napisach SpiderCMS (Sidebar oraz Ekran Logowania)
// Naprawiono strukturę formularzy (zapis ustawień oraz eksport ZIP działają niezależnie)
// NAPRAWIONO: Dodano pełną obsługę, formularz oraz dynamiczny szablon generowania stopki z poprawną ścieżką
// DYNAMICZNA STOPKA: Zapis stopki aktualizuje teraz globalny plik footer.php oraz automatycznie naprawia istniejące podstrony!
// Wersja: czerwiec 2026

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
define('BASE_URL', '/litecard-cms/');   // ← zmień jeśli folder nazywa się inaczej

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
$logo_url = $settings['logo'] ?? (BASE_URL . 'assets/images/spider.png');

// ----------------------------------------------------------------------
// Wczytanie kolorów
// ----------------------------------------------------------------------
$theme_file = __DIR__ . '/.theme.json';
$theme = file_exists($theme_file) ? json_decode(file_get_contents($theme_file), true) : [
    'primary'      => '#a855f7', // Neonowy fiolet (z górnej części pająka)
    'primary-dark' => '#7e22ce', // Ciemniejszy fiolet do hoverów
    'accent'       => '#2563eb', // Elektryzujący błękit (z dolnej części pająka)
];

// ----------------------------------------------------------------------
// Wczytanie ustawień stopki
// ----------------------------------------------------------------------
$footer_file = __DIR__ . '/.footer.json';
$footer_data = file_exists($footer_file) ? json_decode(file_get_contents($footer_file), true) : [
    'copyright'    => '© ' . date('Y') . ' SpiderCMS – wszystkie prawa zastrzeżone.',
    'about_text'   => 'Ultra-lekki system zarządzania treścią Flat-File.',
    'col1_title'   => 'Kontakt',
    'col1_content' => 'Email: kontakt@example.com',
    'col2_title'   => 'Linki',
    'col2_content' => '<a href="/polityka-privacy">Polityka prywatności</a>'
];

// ----------------------------------------------------------------------
// Funkcja aktualizująca kolory we wszystkich stronach
// ----------------------------------------------------------------------
function update_all_pages_colors() {
    global $theme;
    $primary     = $theme['primary']      ?? '#a855f7';
    $primary_dark = $theme['primary-dark'] ?? '#7e22ce';
    $accent      = $theme['accent']       ?? '#2563eb';

    $root_block = ":root {\n      --primary: {$primary};\n      --primary-dark: {$primary_dark};\n      --accent: {$accent};\n      --gray50: #f9fafb;\n      --gray800: #1f2937;\n    }";

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
// Hasło + brute-force
// ----------------------------------------------------------------------
$hash_file = __DIR__ . '/.admin_hash';
if (!file_exists($hash_file)) {
    $default_password = 'admin2026'; // ZMIEŃ TO NATYCHMIAST!
    file_put_contents($hash_file, password_hash($default_password, PASSWORD_ARGON2ID));
    chmod($hash_file, 0600);
}
$ADMIN_HASH = trim(file_get_contents($hash_file));

$MAX_LOGIN_ATTEMPTS = 5;
$BLOCK_DURATION     = 15 * 60;

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
                // Składnia NOWDOC (<<<'PHP') chroni wewnętrzne zmienne i tagi przed przedwczesnym parsowaniem
                // ZMODYFIKOWANO: Cała stopka została wydelegowana do zewnętrznego pliku footer.php za pomocą require_once
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
      --gray50: #f9fafb;
      --gray800: #1f2937;
    }
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:system-ui,sans-serif;line-height:1.6;color:#111827;background:var(--gray50);display:flex;flex-direction:column;min-height:100vh;}
    .site-header{position:fixed;top:0;left:0;right:0;background:white;box-shadow:0 2px 10px rgba(0,0,0,0.08);z-index:1000;}
    .header-container{max-width:1240px;margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:74px;}
    .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;}
    .logo img{max-height:100px;width:auto;}
    .nav-menu{display:flex;gap:2rem;align-items:center;}
    .nav-menu a{color:#374151;text-decoration:none;font-weight:500;padding:0.5rem 1rem;display:flex;align-items:center;gap:0.5rem;}
    .nav-menu a:hover{color:var(--primary);}
    .nav-menu a img{height:28px;width:auto;vertical-align:middle;}
    .menu-toggle{display:none;font-size:1.9rem;cursor:pointer;color:#374151;}
    @media (max-width:768px){
      .nav-menu{display:none;position:absolute;top:74px;left:0;right:0;background:white;flex-direction:column;padding:1.5rem;box-shadow:0 6px 16px rgba(0,0,0,0.1);}
      .nav-menu.active{display:flex;}
      .menu-toggle{display:block;}
    }
    main{margin-top:90px;padding:2rem 1rem;flex:1;}
    .site-footer{background:#1f2937;color:#f3f4f6;padding:3rem 1.5rem;margin-top:5rem;font-size:0.95rem;}
    .footer-container{max-width:1240px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:2.5rem;}
    .footer-col h4{color:var(--primary);margin-bottom:1rem;font-size:1.15rem;}
    .footer-col a{color:#9ca3af;text-decoration:none;}
    .footer-col a:hover{color:white;}
    .footer-bottom{max-width:1240px;margin:2rem auto 0;padding-top:1.5rem;border-top:1px solid #374151;text-align:center;color:#9ca3af;}
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

                // Bezpieczna zamiana markerów na faktyczne dane
                $template = str_replace('__TITLE__', addslashes($title), $template);
                $template = str_replace('__CONTENT__', $content, $template);

                file_put_contents($file, $template);
                $toast = ['type'=>'success', 'msg'=>"Utworzono stronę /$slug"];
            }
        } else {
            $toast = ['type'=>'error', 'msg'=>'Slug i tytuł są wymagane'];
        }
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
        if ($slug === 'index') {
            $toast = ['type'=>'error', 'msg'=>'Nie można usunąć strony głównej'];
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
            $url   = trim($_POST['menu_url'][$i] ?? '');
            $icon  = trim($_POST['menu_icon'][$i] ?? '');

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
        $footer_save = [
            'copyright'    => trim($_POST['footer_copyright'] ?? ''),
            'about_text'   => trim($_POST['footer_about_text'] ?? ''),
            'col1_title'   => trim($_POST['footer_col1_title'] ?? ''),
            'col1_content' => $_POST['footer_col1_content'] ?? '',
            'col2_title'   => trim($_POST['footer_col2_title'] ?? ''),
            'col2_content' => $_POST['footer_col2_content'] ?? '',
        ];
        // Zapisujemy bazę JSON
        file_put_contents($footer_file, json_encode($footer_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // GENERUJEMY NOWY GLOBALNY PLIK footer.php W KATALOGU GŁÓWNYM
        $global_footer_content = <<<'PHP'
<?php
// Globalny plik reprezentujący stopkę serwisu generowany przez SpiderCMS
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

        // MAPOWANIE I NAPRAWA ISTNIEJĄCYCH STRON (Konwersja na model dynamiczny require_once)
        $pages_files = glob(PAGES_DIR . '/*.php');
        foreach ($pages_files as $p_file) {
            $p_content = file_get_contents($p_file);
            
            // Szukamy starej, statycznej struktury stopek <footer class="site-footer">...</footer> i kodu nad nią
            if (strpos($p_content, 'require_once __DIR__ . \'/../footer.php\';') === false) {
                // Sprytny regex wycina stary blok PHP ładujący plik oraz blok HTML <footer...>...</footer>
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
        $new_primary   = trim($_POST['primary']   ?? '');
        $new_primary_d = trim($_POST['primary_dark'] ?? '');
        $new_accent    = trim($_POST['accent']    ?? '');

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
                'primary'      => $new_primary   ?: '#a855f7',
                'primary-dark' => $new_primary_d ?: '#7e22ce',
                'accent'       => $new_accent    ?: '#2563eb',
            ];
            file_put_contents(__DIR__ . '/.theme.json', json_encode($theme_data, JSON_PRETTY_PRINT));

            $settings['logo'] = $logo_path;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));

            $updated_pages = update_all_pages_colors();
            header('Location: admin.php?tab=ustawienia');
            exit;
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

$edit_slug = $_GET['edit'] ?? '';
$edit_content = '';
if ($edit_slug && file_exists($f = PAGES_DIR . '/' . $edit_slug . '.php')) {
    $raw = file_get_contents($f);
    if (preg_match('/\$content\s*=\s*<<<HTML\s*(.*?)\s*HTML;/s', $raw, $m)) {
        $edit_content = trim($m[1]);
    }
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
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table help wordcount',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | code fullscreen removeformat',
        menubar: 'file edit view insert format tools table help',
        valid_elements: '*[*]',
        extended_valid_elements: 'style,script[*],iframe[*]'
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
        #sidebar .menu-tab    { color:var(--menu-color); }
        #sidebar .settings-tab { color:var(--settings-color); }
        #sidebar .about-tab   { color:var(--about-color); }
        #sidebar .footer-tab  { color:var(--footer-color); }
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
        .color-preview { display:flex; align-items:center; gap:1rem; margin-top:0.4rem; }
        .color-preview span { font-family:monospace; font-size:0.95rem; color:#94a3b8; }
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
                case 'dashboard':  echo 'Dashboard'; break;
                case 'menu':       echo 'Konfiguracja górnego menu'; break;
                case 'stopka':     echo 'Konfiguracja stopki witryny'; break;
                case 'ustawienia': echo 'Ustawienia witryny'; break;
                case 'o-cms':      echo 'O tym CMS-ie'; break;
                default:           echo 'Zarządzanie stronami';
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

    <?php elseif ($tab === 'ustawienia'): ?>
        <div class="card card-settings">
            <h2 style="margin-top:0; color:var(--settings-color);"><i class="fa-solid fa-gear" style="margin-right:0.6rem;"></i> Ustawienia witryny</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">
                <label for="site_name">Nazwa witryny</label>
                <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>" required>

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
            <table>
                <thead>
                    <tr><th>Slug / Plik</th><th>Modyfikacja</th><th>Podgląd</th><th>Akcje</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pages as $page): ?>
                <tr>
                    <td>
                        <code><?= htmlspecialchars($page['slug']) ?>.php</code>
                        <?php if ($page['slug'] === 'index'): ?>
                            <span style="color:var(--success);font-size:0.9rem;margin-left:0.6rem;">(strona główna)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $page['modified'] ?></td>
                    <td><a href="<?= htmlspecialchars(PAGES_URL . $page['slug'] . '.php') ?>" target="_blank" class="btn btn-view"><i class="fa-solid fa-eye"></i> Podgląd</a></td>
                    <td>
                        <a href="admin.php?tab=strony&edit=<?= urlencode($page['slug']) ?>" class="btn btn-edit"><i class="fa-solid fa-pen-to-square"></i> Edytuj</a>
                        <?php if ($page['slug'] !== 'index'): ?>
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
                <textarea name="content" class="editor"><p>Wpisz zawartość...</p></textarea>
                <div style="margin-top:1.6rem;"><button type="submit"><i class="fa-solid fa-plus"></i> Utwórz stronę</button></div>
            </form>
        </div>
    <?php endif; ?>
</main>
</body>
</html>