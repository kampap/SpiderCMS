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
$title = 'Strona Główna';
$content = <<<HTML
<!-- HERO -->
<section style="background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 120px 20px; text-align: center; position: relative; overflow: hidden;">
<div style="max-width: 1000px; margin: auto; position: relative; z-index: 2;">
<h1 style="font-size: 48px; line-height: 1.2; margin-bottom: 20px; background: linear-gradient(90deg, #2563eb, #7dd3fc); -webkit-background-clip: text; color: transparent; font-weight: bold; animation: fadeIn 2s ease-out;">Tworzę <br>eleganckie i przyjazne strony internetowe</h1>
<p style="font-size: 20px; max-width: 700px; margin: 0 auto; color: #cbd5e1; animation: fadeIn 3s ease-out;">Każdy projekt powstaje w ramach działań hobbystycznych, z dbałością o minimalistyczny design i intuicyjną obsługę.</p>
</div>
<div style="position: absolute; width: 300px; height: 300px; background: rgba(37,99,235,0.2); border-radius: 50%; top: -50px; left: -50px;"></div>
<div style="position: absolute; width: 200px; height: 200px; background: rgba(125,211,252,0.2); border-radius: 50%; bottom: -40px; right: -40px;"></div>
</section>
<!-- OFERTA -->
<section style="padding: 90px 20px;">
<div style="max-width: 1200px; margin: auto;">
<div style="text-align: center; margin-bottom: 60px;">
<h2 style="font-size: 32px; margin-bottom: 15px;">Nasza Oferta</h2>
<p style="color: #64748b;">Kompleksowe rozwiązania dla Twojego biznesu</p>
</div>
<div style="display: grid; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); gap: 30px;">
<div style="background: linear-gradient(145deg, #ffffff, #f1f5f9); padding: 35px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); transition: 0.3s ease; text-align: center;">
<div style="font-size: 40px; margin-bottom: 15px;">🌐</div>
<h3 style="margin-bottom: 15px; font-size: 20px;">Projektowanie stron</h3>
<p>Nowoczesne strony firmowe, landing page oraz dedykowane systemy CMS dopasowane do Twoich potrzeb.</p>
</div>
<div style="background: linear-gradient(145deg, #ffffff, #f1f5f9); padding: 35px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); transition: 0.3s ease; text-align: center;">
<div style="font-size: 40px; margin-bottom: 15px;">🛠️</div>
<h3 style="margin-bottom: 15px; font-size: 20px;">Administracja</h3>
<p>Stałe wsparcie techniczne, aktualizacje, zabezpieczenia i monitoring działania serwisu.</p>
</div>
<div style="background: linear-gradient(145deg, #ffffff, #f1f5f9); padding: 35px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); transition: 0.3s ease; text-align: center;">
<div style="font-size: 40px; margin-bottom: 15px;">⚡</div>
<h3 style="margin-bottom: 15px; font-size: 20px;">Optymalizacja i rozw&oacute;j</h3>
<p>Przyspieszamy działanie stron i rozwijamy funkcjonalności.</p>
</div>
</div>
</div>
</section>
<!-- DLACZEGO MY -->
<section style="padding: 90px 20px; background: #f1f5f9;">
<div style="max-width: 1200px; margin: auto;">
<div style="text-align: center; margin-bottom: 60px;">
<h2 style="font-size: 32px;">Dlaczego warto mi zaufać?</h2>
</div>
<ul style="max-width: 800px; margin: auto; list-style: none; padding-left: 0;">
<li style="margin-bottom: 15px; font-size: 18px;">✔ Nowoczesny i minimalistyczny design</li>
<li style="margin-bottom: 15px; font-size: 18px;">✔ Pełna responsywność na wszystkich urządzeniach</li>
<li style="margin-bottom: 15px; font-size: 18px;">✔ Szybka realizacja projekt&oacute;w</li>
<li style="margin-bottom: 15px; font-size: 18px;">✔ Stabilność i bezpieczeństwo</li>
<li style="margin-bottom: 15px; font-size: 18px;">✔ Indywidualne podejście do klienta</li>
</ul>
</div>
</section>
<!-- REALIZACJE SLIDER -->
<section style="padding: 90px 20px;">
<div style="max-width: 1200px; margin: auto; text-align: center; margin-bottom: 40px;">
<h2 style="font-size: 32px;">Moje realizacje</h2>
<p style="color: #64748b;">Przykładowe strony wykonane w ramach działań hobbystycznych</p>
</div>
<div style="overflow: hidden; max-width: 1200px; margin: auto; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
<div id="slider" style="display: flex; width: max-content;"><!-- Original Slides -->
<div style="display: flex;"><a href="https://www.lanellys.de/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.lanellys.de</p>
</a><a href="https://www.hannah-piotrowska.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.hannah-piotrowska.pl</p>
</a><a href="https://www.medan.com.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.medan.com.pl</p>
</a><a href="https://www.hexdent.com/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.hexdent.com</p>
</a><a href="https://pire.polsl.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>pire.polsl.pl</p>
</a></div>
<!-- Duplicate Slides for infinite loop -->
<div style="display: flex;"><a href="https://www.lanellys.de/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.lanellys.de</p>
</a><a href="https://www.hannah-piotrowska.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.hannah-piotrowska.pl</p>
</a><a href="https://www.medan.com.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.medan.com.pl</p>
</a><a href="https://www.hexdent.com/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>www.hexdent.com</p>
</a><a href="https://pire.polsl.pl/" target="_blank" style="min-width: 220px; margin: 20px; background: linear-gradient(145deg,#ffffff,#f1f5f9); padding: 35px; border-radius: 15px; text-decoration: none; color: #1e293b; text-align: center;" rel="noopener">
<div style="font-size: 50px; margin-bottom: 15px;">🌐</div>
<p>pire.polsl.pl</p>
</a></div>
</div>
</div>
</section>
<!-- CTA -->
<section style="padding: 90px 20px;">
<div style="max-width: 1200px; margin: auto; text-align: center; background: #2563eb; color: white; padding: 80px 20px; border-radius: 20px;" id="kontakt">
<h2 style="font-size: 30px; margin-bottom: 20px;">Gotowy na rozw&oacute;j swojej marki?</h2>
<p style="margin-bottom: 10px; font-size: 18px;">Skontaktuj się ze mną i otrzymaj bezpłatną wycenę projektu.</p>
<p style="margin-bottom: 30px; font-size: 18px;">📞 +48 790 208 796</p>
<a href="mailto:kamilpaprota@gmail.com" style="display: inline-block; padding: 14px 30px; background: white; color: #2563eb; text-decoration: none; border-radius: 50px; font-weight: 600;">Napisz do mnie</a></div>
</section>
<!-- FOOTER --><footer style="background: #0f172a; color: #cbd5e1; text-align: center; padding: 30px 20px; margin-top: 60px;">
<p>&copy; 2026 Kamil Paprota &mdash; Wszystko w ramach działań hobbystycznych, nie jestem firmą.</p>
<p>📞 +48 790 208 796 | ✉ kontakt@twojadomena.pl</p>
</footer>
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
    body{font-family:system-ui,sans-serif;line-height:1.6;color:var(--text);background:var(--page-bg);}
    .site-header{position:fixed;top:0;left:0;right:0;background:var(--surface);box-shadow:0 2px 10px rgba(0,0,0,0.08);z-index:1000;}
    .header-container{max-width:var(--container-width);margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:var(--header-height);}
    .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;}
    .logo img{max-height:50px;width:auto;}
    .nav-menu{display:flex;gap:2rem;align-items:center;}
    .nav-menu a{color:var(--header-text);text-decoration:none;font-weight:500;padding:0.5rem 1rem;display:flex;align-items:center;gap:0.5rem;}
    .nav-menu a:hover{color:var(--primary);}
    .nav-menu a img{height:28px;width:auto;vertical-align:middle;}
    .menu-toggle{display:none;font-size:1.9rem;cursor:pointer;color:var(--header-text);}
    @media (max-width:768px){
      .nav-menu{display:none;position:absolute;top:74px;left:0;right:0;background:var(--surface);flex-direction:column;padding:1.5rem;box-shadow:0 6px 16px rgba(0,0,0,0.1);}
      .nav-menu.active{display:flex;}
      .menu-toggle{display:block;}
    }
    main{margin-top:90px;padding:2rem 1rem;}
  </style>
<?php require_once dirname(__DIR__, 1) . '/social-meta.php'; ?>
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

<footer style="text-align:center;padding:4rem 1rem;background:#f1f5f9;color:#6b7280;margin-top:5rem;">
  © <?= date('Y') ?> <?= SITE_NAME ?>
</footer>

<?php require_once __DIR__ . '/../chat-widget.php'; ?>
<?php require_once dirname(__DIR__, 1) . '/social-widget.php'; ?>
</body>
</html>