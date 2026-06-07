<?php
require_once dirname(__DIR__, 1) . '/config.php';
$spidercms_root_dir = dirname(__DIR__, 1);
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
$title = 'o nas';
$content = <<<HTML
<p>Wpisz zawartość...fsf</p>
HTML;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($title); ?> • <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <?php require_once dirname(__DIR__, 1) . '/social-meta.php'; ?>
  <style>
    :root {
 --primary: #a855f7;
 --primary-dark: #7e22ce;
 --accent: #2563eb;
 --page-bg: #f9fafb;
 --page-text: #111827;
 --header-bg: #ffffff;
 --header-text: #374151;
 --footer-bg: #1f2937;
 --footer-text: #f3f4f6;
 --footer-muted: #9ca3af;
 --link-color: #a855f7;
 --button-bg: #a855f7;
 --button-text: #ffffff;
 --font-family: system-ui, sans-serif;
 --header-height: 74px;
 --logo-height: 100px;
 --content-width: 1240px;
 --radius: 10px;
 --header-shadow: 0 2px 10px rgba(0,0,0,0.08);
 --menu-position: center;
 --spidercms-logo-max-height: min(var(--logo-height,100px), calc(var(--header-height,74px) - 14px));
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
</head>
<body>
<?php // Nagłówek wczytany z header.php ?>

<main><?php echo $content; ?></main>
<?php
// ZMIANA: Zamiast generować stopkę w każdym pliku osobno, wczytujemy globalny plik footer.php
require_once dirname(__DIR__, 1) . '/footer.php';
require_once dirname(__DIR__, 1) . '/chat-widget.php';
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

<?php require_once dirname(__DIR__, 1) . '/social-widget.php'; ?>
</body>
</html>