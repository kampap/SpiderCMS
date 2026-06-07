<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../header.php';
$title = 'Kontakt';
$content = <<<HTML
<section class="cms-hero"><h1>Kontakt</h1><p>Masz pytanie? Napisz do nas przez formularz.</p></section>
<section id="kontakt" class="cms-contact-form" style="max-width:860px;margin:2rem auto;padding:2rem;border:1px solid rgba(0,0,0,.08);border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.06);">
  <h2>Formularz kontaktowy</h2>
  <p>Wypełnij formularz, a odpowiemy najszybciej jak to możliwe.</p>
  <form method="post" action="/contact.php" style="display:grid;gap:1rem;margin-top:1.2rem;">
    <input type="hidden" name="redirect_ok" value="/pages/kontakt.php">
    <input type="hidden" name="redirect_error" value="/pages/kontakt.php">
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <input type="text" name="name" placeholder="Imię i nazwisko" required style="padding:.9rem;border:1px solid #d1d5db;border-radius:10px;">
      <input type="email" name="email" placeholder="Adres e-mail" required style="padding:.9rem;border:1px solid #d1d5db;border-radius:10px;">
    </div>
    <input type="text" name="phone" placeholder="Telefon (opcjonalnie)" style="padding:.9rem;border:1px solid #d1d5db;border-radius:10px;">
    <input type="text" name="subject" placeholder="Temat wiadomości" style="padding:.9rem;border:1px solid #d1d5db;border-radius:10px;">
    <textarea name="message" rows="6" placeholder="Treść wiadomości" required style="padding:.9rem;border:1px solid #d1d5db;border-radius:10px;"></textarea>
    <label style="display:flex;gap:.6rem;align-items:flex-start;font-size:.95rem;"><input type="checkbox" name="consent" required> Wyrażam zgodę na kontakt w celu obsługi zapytania.</label>
    <button type="submit" class="cms-btn" style="border:0;cursor:pointer;width:max-content;">Wyślij wiadomość</button>
  </form>
</section>
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
 --gray50: #f9fafb;
 --gray800: #1f2937;
}
    *{margin:0;padding:0;box-sizing:border-box;} body{font-family:var(--font-family);line-height:1.6;color:var(--page-text);background:var(--page-bg);display:flex;flex-direction:column;min-height:100vh;}
    .cms-hero{padding:3rem 2rem;border-radius:18px;background:rgba(168,85,247,0.10);margin:1.5rem auto;max-width:var(--content-width);} .cms-hero h1{font-size:clamp(2rem,4vw,3.4rem);line-height:1.1;margin-bottom:1rem;color:var(--primary);} .cms-btn{display:inline-block;padding:.85rem 1.25rem;border-radius:999px;background:var(--button-bg);color:var(--button-text);text-decoration:none;font-weight:700;}
    .site-header{position:fixed;top:0;left:0;right:0;background:var(--header-bg);box-shadow:var(--header-shadow);z-index:1000;text-align:left;} .header-container{max-width:var(--content-width);margin:0 auto;padding:0 1.5rem;display:flex;justify-content:space-between;align-items:center;height:var(--header-height);text-align:left;} .logo{font-weight:700;font-size:1.4rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;justify-content:flex-start;text-align:left;margin-right:auto;} .logo img{max-height:var(--logo-height);width:auto;display:block;margin:0;} .nav-menu{display:flex;gap:2rem;align-items:center;} .nav-menu a{color:var(--header-text);text-decoration:none;font-weight:500;padding:0.5rem 1rem;display:flex;align-items:center;gap:0.5rem;} .nav-menu a:hover{color:var(--primary);} .menu-toggle{display:none;font-size:1.9rem;cursor:pointer;color:#374151;} @media(max-width:768px){.nav-menu{display:none;position:absolute;top:74px;left:0;right:0;background:white;flex-direction:column;padding:1.5rem;box-shadow:0 6px 16px rgba(0,0,0,0.1);} .nav-menu.active{display:flex;} .menu-toggle{display:block;}}
    main{margin-top:calc(var(--header-height) + 16px);padding:2rem 1rem;flex:1;} .site-footer{background:var(--footer-bg);color:var(--footer-text);padding:3rem 1.5rem;margin-top:5rem;font-size:0.95rem;} .footer-container{max-width:var(--content-width);margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:2.5rem;text-align:left;} .footer-col h4{color:var(--primary);margin-bottom:1rem;font-size:1.15rem;} .footer-col a{color:var(--footer-muted);text-decoration:none;} .footer-bottom{max-width:var(--content-width);margin:2rem auto 0;padding-top:1.5rem;border-top:1px solid #374151;color:var(--footer-muted);}
  </style>
<?php require_once dirname(__DIR__, 1) . '/social-meta.php'; ?>
</head>
<body>
<main><?php echo $content; ?></main>
<?php require_once __DIR__ . '/../footer.php'; ?>
<?php require_once __DIR__ . '/../chat-widget.php'; ?>
<?php require_once dirname(__DIR__, 1) . '/social-widget.php'; ?>
</body>
</html>