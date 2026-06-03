<?php
// admin.php – panel administracyjny Spider CMS
// Dodana strona startowa (dashboard) po zalogowaniu
// Menu wróciło do prostej wersji (bez submenu)
// Wersja: marzec 2026

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
$logo_url = $settings['logo'] ?? '';

// ----------------------------------------------------------------------
// Wczytanie kolorów
// ----------------------------------------------------------------------
$theme_file = __DIR__ . '/.theme.json';
$theme = file_exists($theme_file) ? json_decode(file_get_contents($theme_file), true) : [
    'primary'      => '#2563eb',
    'primary-dark' => '#1d4ed8',
    'accent'       => '#10b981',
];

// ----------------------------------------------------------------------
// Funkcja aktualizująca kolory we wszystkich stronach
// ----------------------------------------------------------------------
function update_all_pages_colors() {
    global $theme;
    $primary     = $theme['primary']      ?? '#2563eb';
    $primary_dark = $theme['primary-dark'] ?? '#1d4ed8';
    $accent      = $theme['accent']       ?? '#10b981';

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
            header('Location: admin.php?tab=dashboard'); // ← po zalogowaniu idziemy na dashboard
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
        <title>Logowanie – Panel</title>
        <style>
            body{font-family:system-ui,sans-serif;background:linear-gradient(135deg,#e0f2fe,#bae6fd);display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}
            .card{background:#fff;padding:2.8rem 2.2rem;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,0.14);width:100%;max-width:400px;}
            h1{text-align:center;color:#1e40af;margin:0 0 1.8rem;font-size:1.9rem;}
            input{width:100%;padding:1rem;margin:0.5rem 0 1.5rem;border:1px solid #cbd5e1;border-radius:8px;font-size:1.05rem;}
            button{width:100%;padding:1rem;background:#1d4ed8;color:white;border:none;border-radius:8px;font-size:1.05rem;font-weight:600;cursor:pointer;}
            button:hover{background:#1e40af;}
            .error{color:#dc2626;text-align:center;margin-bottom:1.2rem;font-weight:500;}
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Panel administracyjny</h1>
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

$tab = $_GET['tab'] ?? 'dashboard'; // domyślnie dashboard po zalogowaniu

// ----------------------------------------------------------------------
// Obsługa POST
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', trim(strtolower($_POST['slug'] ?? '')));
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';

        if ($slug && $title) {
            $file = PAGES_DIR . '/' . $slug . '.php';
            if (file_exists($file)) {
                $toast = ['type'=>'error', 'msg'=>'Taki slug już istnieje'];
            } else {
                $template = <<<PHP
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../header.php';

\$title = '$title';
\$content = <<<HTML
$content
HTML;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(\$title); ?> • <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <style>
    :root {
      --primary: <?php echo \$theme['primary'] ?? '#2563eb'; ?>;
      --primary-dark: <?php echo \$theme['primary-dark'] ?? '#1d4ed8'; ?>;
      --accent: <?php echo \$theme['accent'] ?? '#10b981'; ?>;
      --gray50: #f9fafb;
      --gray800: #1f2937;
    }
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:system-ui,sans-serif;line-height:1.6;color:#111827;background:var(--gray50);}
    .site-header{position:fixed;top:0;left:0;right:0;background:white;box-shadow:0 2px 10px rgba(0,0,0,0.08);z-index:1000;}
    .header-container{max-width:1240px;margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:74px;}
    .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;}
    .logo img{max-height:50px;width:auto;}
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
    main{margin-top:90px;padding:2rem 1rem;}
  </style>
</head>
<body>

<?php // Nagłówek wczytany z header.php ?>

<main><?php echo \$content; ?></main>

<footer style="text-align:center;padding:4rem 1rem;background:#f1f5f9;color:#6b7280;margin-top:5rem;">
  © <?= date('Y') ?> <?= SITE_NAME ?>
</footer>

</body>
</html>
PHP;
                file_put_contents($file, $template);
                $toast = ['type'=>'success', 'msg'=>"Utworzono stronę /$slug"];
            }
        } else {
            $toast = ['type'=>'error', 'msg'=>'Slug i tytuł są wymagane'];
        }
    }

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
                $items[] = [
                    'label' => $label,
                    'url'   => $url,
                    'icon'  => $icon
                ];
            }
        }

        file_put_contents(__DIR__ . '/.menu.json', json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $toast = ['type'=>'success', 'msg'=>'Konfiguracja menu zapisana'];
        header('Location: admin.php?tab=menu');
        exit;
    }

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
                'primary'      => $new_primary   ?: '#2563eb',
                'primary-dark' => $new_primary_d ?: '#1d4ed8',
                'accent'       => $new_accent    ?: '#10b981',
            ];
            file_put_contents(__DIR__ . '/.theme.json', json_encode($theme_data, JSON_PRETTY_PRINT));

            $settings['logo'] = $logo_path;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));

            $updated_pages = update_all_pages_colors();

            $toast = [
                'type' => 'success',
                'msg'  => "Ustawienia zapisane. Zaktualizowano kolory na <strong>$updated_pages</strong> stronach."
            ];
            header('Location: admin.php?tab=ustawienia');
            exit;
        }
    }

    if ($action === 'export_all') {
        $zip_name = 'litecard-cms-full-' . date('Y-m-d-H-i-s') . '.zip';
        $zip_file = sys_get_temp_dir() . '/' . $zip_name;

        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $root_files = glob(__DIR__ . '/*');
            foreach ($root_files as $file) {
                if (is_file($file) && basename($file) !== 'admin.php') {
                    $zip->addFile($file, basename($file));
                }
            }

            $pages_files = glob(PAGES_DIR . '/*.php');
            foreach ($pages_files as $file) {
                $zip->addFile($file, 'pages/' . basename($file));
            }

            $uploads_dir = __DIR__ . '/uploads/';
            if (is_dir($uploads_dir)) {
                $upload_files = glob($uploads_dir . '*');
                foreach ($upload_files as $file) {
                    if (is_file($file)) {
                        $zip->addFile($file, 'uploads/' . basename($file));
                    }
                }
            }

            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($zip_file));
            readfile($zip_file);
            unlink($zip_file);
            exit;
        } else {
            $toast = ['type' => 'error', 'msg' => 'Nie udało się utworzyć archiwum ZIP'];
        }
    }
}

// ----------------------------------------------------------------------
// Widok panelu
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
    <title>Panel administracyjny</title>
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
            --primary: <?= htmlspecialchars($theme['primary'] ?? '#2563eb') ?>;
            --primary-dark: <?= htmlspecialchars($theme['primary-dark'] ?? '#1d4ed8') ?>;
            --accent: <?= htmlspecialchars($theme['accent'] ?? '#10b981') ?>;
            --success: #10b981;
            --success-dark: #059669;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --sidebar: 260px;
            --menu-color: #8b5cf6;
            --settings-color: #f59e0b;
            --about-color: #ec4899;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:system-ui,sans-serif; background:var(--gray-50); color:#111827; min-height:100vh; display:flex; }
        #sidebar { width:var(--sidebar); background:white; border-right:1px solid var(--gray-200); height:100vh; position:fixed; overflow-y:auto; }
        #sidebar-header { padding:1.5rem 1.4rem; font-size:1.45rem; font-weight:700; color:var(--primary); border-bottom:1px solid var(--gray-200); }
        #sidebar a { display:flex; align-items:center; gap:0.8rem; padding:0.95rem 1.4rem; color:#4b5563; text-decoration:none; transition:0.15s; }
        #sidebar a:hover, #sidebar a.active { background:var(--gray-100); color:var(--primary); }
        #sidebar .menu-tab    { color:var(--menu-color); }
        #sidebar .settings-tab { color:var(--settings-color); }
        #sidebar .about-tab   { color:var(--about-color); }
        #sidebar .menu-tab.active, 
        #sidebar .settings-tab.active,
        #sidebar .about-tab.active { background:var(--gray-100); font-weight:600; }
        #main { margin-left:var(--sidebar); flex:1; padding:2rem 2.4rem; }
        header { background:white; padding:1.2rem 2rem; border-bottom:1px solid var(--gray-200); display:flex; justify-content:space-between; align-items:center; border-radius:10px; margin-bottom:1.8rem; box-shadow:0 2px 10px rgba(0,0,0,0.04); }
        header h1 { font-size:1.6rem; margin:0; }
        .card { background:white; border-radius:10px; box-shadow:0 3px 16px rgba(0,0,0,0.05); padding:1.7rem 2rem; margin-bottom:1.8rem; }
        label { display:block; margin:1.2rem 0 0.4rem; font-weight:500; color:#4b5563; }
        input[type="text"], input[type="color"], input[type="file"] { width:100%; padding:0.75rem 1rem; border:1px solid #d1d5db; border-radius:6px; }
        input[type="color"] { padding:0.4rem; height:2.8rem; width:4rem; }
        .toast { position:fixed; top:1.2rem; right:1.2rem; padding:0.9rem 1.5rem; border-radius:8px; color:white; font-weight:500; z-index:1000; box-shadow:0 5px 18px rgba(0,0,0,0.16); animation:toast-in .35s, toast-out .35s 4s forwards; }
        .toast.success { background:var(--success); }
        .toast.error   { background:var(--danger); }
        @keyframes toast-in  { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:translateY(0); } }
        table { width:100%; border-collapse:collapse; margin-top:0.8rem; }
        th, td { padding:0.9rem 1.1rem; text-align:left; border-bottom:1px solid var(--gray-200); }
        th { background:var(--gray-100); font-weight:600; }
        tr:hover { background:var(--gray-50); }

        .btn {
            padding: 0.55rem 1.1rem !important;
            border-radius: 6px !important;
            color: white !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.45rem !important;
            transition: 0.14s !important;
            text-shadow: 0 0 1px rgba(0,0,0,0.3) !important;
        }

        .btn-view     { background: #10b981 !important; }
        .btn-view:hover     { background: #059669 !important; }
        .btn-edit     { background: #3b82f6 !important; }
        .btn-delete   { background: #ef4444 !important; }
        .btn-export   { background: #8b5cf6 !important; }

        .btn i { color: white !important; }

        button[type="submit"] {
            background: var(--primary) !important;
            color: white !important;
            border: none !important;
            padding: 0.9rem 1.6rem !important;
            border-radius: 6px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
        }
        button[type="submit"]:hover { background: var(--primary-dark) !important; }

        .btn-full-export {
            background: #059669 !important;
            color: white !important;
            padding: 1rem 2rem !important;
            border-radius: 8px !important;
            font-weight: 700 !important;
            font-size: 1.1rem !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.6rem !important;
            margin-top: 2rem !important;
            border: none !important;
            cursor: pointer !important;
            box-shadow: 0 4px 12px rgba(5,150,105,0.3) !important;
        }
        .btn-full-export:hover {
            background: #047857 !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 16px rgba(5,150,105,0.4) !important;
        }

        .menu-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem; align-items:flex-end; }
        .menu-row label { font-size:0.9rem; margin-bottom:0.3rem; display:block; }
        .color-preview { display:flex; align-items:center; gap:1rem; margin-top:0.4rem; }
        .color-preview span { font-family:monospace; font-size:0.95rem; color:#4b5563; }

        /* Dashboard – proste karty */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .dash-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
            padding: 1.5rem;
            text-align: center;
        }
        .dash-card h3 {
            margin: 0 0 1rem;
            color: var(--primary);
            font-size: 1.3rem;
        }
        .dash-number {
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--accent);
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>

<aside id="sidebar">
    <div id="sidebar-header">Spider CMS</div>
    <nav>
        <a href="/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Strona główna</a>
        <a href="admin.php?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="admin.php?tab=strony" class="<?= $tab === 'strony' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i> Strony</a>
        <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active menu-tab' : 'menu-tab' ?>"><i class="fa-solid fa-bars"></i> Menu</a>
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
                case 'menu':      echo 'Konfiguracja górnego menu'; break;
                case 'ustawienia': echo 'Ustawienia witryny'; break;
                case 'o-cms':     echo 'O tym CMS-ie'; break;
                default:          echo 'Zarządzanie stronami';
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
                <p style="color:#6b7280;">w tym strona główna</p>
            </div>

            <div class="dash-card">
                <h3>Ostatnia modyfikacja</h3>
                <?php
                $last_modified = 'Brak stron';
                $last_date = 0;
                foreach ($pages as $p) {
                    $time = filemtime(PAGES_DIR . '/' . $p['slug'] . '.php');
                    if ($time > $last_date) {
                        $last_date = $time;
                        $last_modified = date('d.m.Y H:i', $time) . ' – ' . $p['slug'];
                    }
                }
                ?>
                <div style="font-size:1.3rem; font-weight:600; color:#374151;"><?= $last_modified ?></div>
            </div>

            <div class="dash-card">
                <h3>Menu nawigacyjne</h3>
                <div style="font-size:1.4rem; margin:0.8rem 0;">
                    <?= $menu_enabled ? '<span style="color:var(--success);">WŁĄCZONE</span>' : '<span style="color:var(--danger);">WYŁĄCZONE</span>' ?>
                </div>
                <p style="color:#6b7280;"><?= count($menu_items) ?> pozycji</p>
            </div>

            <div class="dash-card">
                <h3>Szybkie akcje</h3>
                <div style="margin-top:1rem; display:flex; flex-direction:column; gap:0.8rem;">
                    <a href="admin.php?tab=strony" class="btn btn-view" style="text-align:center;">
                        <i class="fa-solid fa-plus"></i> Dodaj nową stronę
                    </a>
                    <a href="admin.php?tab=ustawienia" class="btn btn-edit" style="text-align:center;">
                        <i class="fa-solid fa-palette"></i> Zmień kolory / logo
                    </a>
                    <a href="/" target="_blank" class="btn btn-export" style="text-align:center;">
                        <i class="fa-solid fa-eye"></i> Podgląd witryny
                    </a>
                </div>
            </div>
        </div>

    <?php elseif ($tab === 'ustawienia'): ?>

        <div class="card card-settings">
            <h2 style="margin-top:0; color:var(--settings-color);"><i class="fa-solid fa-gear" style="margin-right:0.6rem;"></i> Ustawienia witryny</h2>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">

                <label for="site_name">Nazwa witryny (tekstowa wersja logo – fallback)</label>
                <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>" required placeholder="np. Spider CMS">

                <div style="margin:2.5rem 0 1.5rem; font-weight:600; color:#6b7280;">Główne kolory</div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem;">
                    <div>
                        <label for="primary">Kolor główny (--primary)</label>
                        <div class="color-preview">
                            <input type="color" id="primary" name="primary" value="<?= htmlspecialchars($theme['primary'] ?? '#2563eb') ?>">
                            <span><?= htmlspecialchars($theme['primary'] ?? '#2563eb') ?></span>
                        </div>
                    </div>

                    <div>
                        <label for="primary_dark">Kolor główny ciemny (--primary-dark)</label>
                        <div class="color-preview">
                            <input type="color" id="primary_dark" name="primary_dark" value="<?= htmlspecialchars($theme['primary-dark'] ?? '#1d4ed8') ?>">
                            <span><?= htmlspecialchars($theme['primary-dark'] ?? '#1d4ed8') ?></span>
                        </div>
                    </div>

                    <div>
                        <label for="accent">Kolor akcentujący (--accent)</label>
                        <div class="color-preview">
                            <input type="color" id="accent" name="accent" value="<?= htmlspecialchars($theme['accent'] ?? '#10b981') ?>">
                            <span><?= htmlspecialchars($theme['accent'] ?? '#10b981') ?></span>
                        </div>
                    </div>
                </div>

                <div style="margin-top:3rem;">
                    <label for="logo_upload">Logo witryny (zamiast nazwy tekstowej po lewej)</label>
                    <input type="file" id="logo_upload" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/gif" style="width:100%; padding:0.8rem; border:1px solid #d1d5db; border-radius:6px;">

                    <p style="margin:1rem 0 0.5rem; color:#6b7280; font-size:0.95rem;">lub wklej bezpośredni URL do obrazka:</p>
                    <input type="text" name="logo_url" placeholder="https://example.com/logo.png" value="<?= htmlspecialchars($logo_url) ?>" style="width:100%; padding:0.75rem 1rem; border:1px solid #d1d5db; border-radius:6px;">

                    <?php if ($logo_url): ?>
                        <div style="margin-top:1.5rem;">
                            <strong>Aktualne logo:</strong><br>
                            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo witryny" style="max-height:120px; margin-top:0.5rem; border:1px solid #e5e7eb; border-radius:8px;">
                        </div>
                    <?php endif; ?>
                </div>

				<form method="post" enctype="multipart/form-data">
					<input type="hidden" name="action" value="save_settings">

					<!-- wszystkie pola ustawień -->

					<div style="margin-top:3rem; text-align:center;">
						<button type="submit">
							<i class="fa-solid fa-floppy-disk"></i> Zapisz wszystkie ustawienia
						</button>
					</div>
				</form>

				<!-- DRUGI FORMULARZ – POZA PIERWSZYM -->
				<form method="post" style="margin-top:1.5rem; text-align:center;">
					<input type="hidden" name="action" value="export_all">
					<button type="submit" class="btn-full-export">
						<i class="fa-solid fa-download"></i> Eksport całej witryny (ZIP)
					</button>
				</form>

                <p style="margin-top:2rem; color:#6b7280; font-size:0.95rem; text-align:center;">
                    Po zapisaniu odśwież stronę główną (Ctrl + F5), aby zobaczyć zmiany.
                </p>
            </form>
        </div>

    <?php elseif ($tab === 'menu'): ?>

        <div class="card">
            <h2 style="margin-top:0; color:var(--menu-color);"><i class="fa-solid fa-bars" style="margin-right:0.6rem;"></i> Górne menu nawigacyjne</h2>

            <form method="post">
                <input type="hidden" name="action" value="save_menu">

                <label style="display:flex; align-items:center; gap:0.8rem; font-size:1.1rem; margin:1.8rem 0 1.2rem;">
                    <input type="checkbox" name="menu_enabled" <?= $menu_enabled ? 'checked' : '' ?> style="width:auto; transform:scale(1.3);">
                    <strong>Włącz górne menu na wszystkich stronach</strong>
                </label>

                <div style="margin:2.5rem 0 1rem; font-weight:600; color:#6b7280;">Pozycje menu (maksymalnie 8)</div>

                <div id="menu-items">
                    <?php for ($i = 0; $i < 8; $i++):
                        $item = $menu_items[$i] ?? ['label' => '', 'url' => '', 'icon' => ''];
                    ?>
                    <div class="menu-row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem; align-items:flex-end;">
                        <div>
                            <label style="font-size:0.9rem; margin-bottom:0.3rem; display:block;">Nazwa / tekst</label>
                            <input type="text" name="menu_label[]" placeholder="np. O nas" value="<?= htmlspecialchars($item['label'] ?? '') ?>">
                        </div>

                        <div>
                            <label style="font-size:0.9rem; margin-bottom:0.3rem; display:block;">Link (URL)</label>
                            <input type="text" name="menu_url[]" placeholder="/o-nas lub https://" value="<?= htmlspecialchars($item['url'] ?? '') ?>">
                        </div>

                        <div>
                            <label style="font-size:0.9rem; margin-bottom:0.3rem; display:block;">Ikona / URL obrazka</label>
                            <input type="text" name="menu_icon[]" placeholder="https://...png / jpg / svg lub fa-solid fa-home" value="<?= htmlspecialchars($item['icon'] ?? '') ?>">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <div style="margin-top:2.5rem;">
                    <button type="submit"><i class="fa-solid fa-save"></i> Zapisz konfigurację menu</button>
                </div>

                <p style="margin-top:1.5rem; color:#6b7280; font-size:0.95rem;">
                    W polu „Ikona / URL obrazka” możesz wpisać:<br>
                    • bezpośredni link do obrazka (png / jpg / svg / gif)<br>
                    • klasę Font Awesome (np. fa-solid fa-home)<br>
                    • jeśli puste → wyświetli się sama nazwa
                </p>
            </form>
        </div>

    <?php elseif ($tab === 'o-cms'): ?>

        <div class="card card-about">
            <h2 style="margin-top:0; color:var(--about-color);">
                <i class="fa-solid fa-info-circle" style="margin-right:0.6rem;"></i>
                O tym CMS-ie
            </h2>

            <p style="margin:1.5rem 0; line-height:1.7;">
                <strong>Spider CMS</strong> to lekki, plikowy system zarządzania treścią stworzony specjalnie z myślą o prostocie i szybkości.
            </p>

            <ul style="list-style: none; padding-left: 0; line-height: 2;">
                <li><i class="fa-solid fa-check" style="color:var(--success); margin-right:0.8rem;"></i>Zero bazy danych – wszystko w plikach</li>
                <li><i class="fa-solid fa-check" style="color:var(--success); margin-right:0.8rem;"></i>Edycja kodu HTML/CSS/PHP wprost w przeglądarce</li>
                <li><i class="fa-solid fa-check" style="color:var(--success); margin-right:0.8rem;"></i>Dynamiczne logo i kolory zapisywane w plikach JSON</li>
                <li><i class="fa-solid fa-check" style="color:var(--success); margin-right:0.8rem;"></i>Proste płaskie menu nawigacyjne</li>
                <li><i class="fa-solid fa-check" style="color:var(--success); margin-right:0.8rem;"></i>Eksport całej witryny jednym kliknięciem (ZIP)</li>
            </ul>

            <p style="margin-top:2rem; font-style:italic; color:#6b7280;">
                Projekt powstał jako alternatywa dla ciężkich CMS-ów – idealny do małych i średnich stron firmowych, portfolio, landing page.
            </p>

            <div style="margin-top:2rem; text-align:center;">
                <p style="color:#6b7280;">Wersja: 1.0 | Autor: [Kamil Paprota]</p>
                <p style="color:#6b7280;">© <?= date('Y') ?> SpiderCMS – wszystkie prawa zastrzeżone</p>
            </div>
        </div>

    <?php else: ?>

        <div class="card">
            <h2>Nowa strona</h2>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <label>Slug (adres URL)</label>
                <input type="text" name="slug" required pattern="[a-z0-9\-_]+" placeholder="np. kontakt" title="litery, cyfry, -, _">

                <label>Tytuł strony</label>
                <input type="text" name="title" required placeholder="np. Kontakt">

                <label>Treść strony</label>
                <textarea name="content" class="editor"><p>Wpisz zawartość...</p></textarea>

                <div style="margin-top:1.6rem;">
                    <button type="submit"><i class="fa-solid fa-plus"></i> Utwórz stronę</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Twoje strony (<?= count($pages) ?>)</h2>

            <?php if (empty($pages)): ?>
                <p style="color:#6b7280;padding:1rem 0;">Brak stron – strona główna została utworzona automatycznie.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Slug / Plik</th>
                        <th>Ostatnia modyfikacja</th>
                        <th>Podgląd</th>
                        <th>Eksport</th>
                        <th>Akcje</th>
                    </tr>
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
                    <td>
                        <a href="<?= htmlspecialchars(PAGES_URL . $page['slug'] . '.php') ?>" target="_blank" class="btn btn-view">
                            <i class="fa-solid fa-eye"></i> Podgląd
                        </a>
                    </td>
                    <td>
                        <a href="?export=<?= urlencode($page['slug']) ?>" class="btn btn-export" title="Pobierz ZIP z tą stroną">
                            <i class="fa-solid fa-file-zipper"></i> Eksport
                        </a>
                    </td>
                    <td>
                        <a href="admin.php?tab=strony&edit=<?= urlencode($page['slug']) ?>" class="btn btn-edit">
                            <i class="fa-solid fa-pen-to-square"></i> Edytuj
                        </a>
                        <?php if ($page['slug'] !== 'index'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Na pewno usunąć <?= htmlspecialchars($page['slug']) ?>?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($page['slug']) ?>">
                            <button type="submit" class="btn btn-delete">
                                <i class="fa-solid fa-trash-can"></i> Usuń
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php if ($edit_slug): ?>
        <div class="card">
            <h2>Edycja: <?= htmlspecialchars($edit_slug) ?><?php if ($edit_slug === 'index') echo ' <small>(strona główna)</small>'; ?></h2>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="slug" value="<?= htmlspecialchars($edit_slug) ?>">
                <label>Treść strony</label>
                <textarea name="content" class="editor"><?= htmlspecialchars($edit_content) ?></textarea>
                <div style="margin-top:1.6rem;">
                    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Zapisz zmiany</button>
                    <a href="admin.php" style="margin-left:1.2rem;color:#6b7280;text-decoration:none;">Anuluj</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
