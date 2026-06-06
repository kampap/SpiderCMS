<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../header.php';
$title = 'cennik';
$content = <<<HTML
<p>Wpisz zawartość...</p>
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

</body>
</html>