<?php
$settings_file = __DIR__ . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
$logo_url = $settings['logo'] ?? (defined('BASE_URL') ? BASE_URL . 'assets/images/spidercms-icon.png' : '/assets/images/spidercms-icon.png');
$menu_enabled = file_exists(__DIR__ . '/.menu_enabled');
$menu_items = json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true);
?>
<style>
/* Twarda naprawa nagłówka: logo zawsze po lewej, menu zawsze po prawej. */
.site-header{
  position:fixed !important;
  top:0 !important;
  left:0 !important;
  right:0 !important;
  width:100% !important;
  z-index:1000 !important;
  text-align:left !important;
  background:var(--header-bg,#ffffff) !important;
  box-shadow:var(--shadow,0 2px 10px rgba(0,0,0,.08)) !important;
}
.site-header .header-container{
  width:100% !important;
  max-width:var(--container-width,1240px) !important;
  height:var(--header-height,74px) !important;
  margin:0 auto !important;
  padding:0 1.5rem !important;
  display:grid !important;
  grid-template-columns:auto minmax(1rem,1fr) auto !important;
  align-items:center !important;
  justify-items:stretch !important;
  text-align:left !important;
}
.site-header .logo{
  grid-column:1 !important;
  justify-self:start !important;
  align-self:center !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:flex-start !important;
  gap:.65rem !important;
  margin:0 !important;
  padding:0 !important;
  width:auto !important;
  max-width:360px !important;
  min-width:0 !important;
  flex:none !important;
  text-align:left !important;
  color:var(--primary,#a855f7) !important;
  text-decoration:none !important;
  font-weight:var(--heading-weight,700) !important;
  font-size:1.4rem !important;
  line-height:1 !important;
}
.site-header .logo img{
  display:block !important;
  max-height:var(--logo-height,80px) !important;
  max-width:100% !important;
  width:auto !important;
  height:auto !important;
  margin:0 !important;
  padding:0 !important;
  object-fit:contain !important;
  float:none !important;
  position:static !important;
  transform:none !important;
}
.site-header .nav-menu{
  grid-column:3 !important;
  justify-self:end !important;
  align-self:center !important;
  display:flex !important;
  align-items:center !important;
  justify-content:flex-end !important;
  gap:2rem !important;
  margin:0 !important;
  padding:0 !important;
  text-align:left !important;
}
.site-header .nav-menu a{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  gap:.5rem !important;
  white-space:nowrap !important;
  text-align:left !important;
  text-decoration:none !important;
}
.site-header .nav-menu a img{height:28px !important;width:auto !important;display:block !important;margin:0 !important;}
.site-header .menu-toggle{grid-column:3 !important;justify-self:end !important;display:none;font-size:1.9rem;cursor:pointer;color:var(--header-text,#374151);}
@media (max-width:768px){
  .site-header .header-container{grid-template-columns:auto 1fr auto !important;}
  .site-header .menu-toggle{display:block !important;}
  .site-header .nav-menu{display:none !important;position:absolute !important;top:var(--header-height,74px) !important;left:0 !important;right:0 !important;background:var(--header-bg,#ffffff) !important;flex-direction:column !important;align-items:flex-start !important;justify-content:flex-start !important;padding:1.5rem !important;box-shadow:var(--shadow,0 6px 16px rgba(0,0,0,.1)) !important;}
  .site-header .nav-menu.active{display:flex !important;}
}
</style>
<header class="site-header">
  <div class="header-container">
    <a href="/" class="logo">
      <?php if (!empty($logo_url)): ?>
        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'Logo'; ?>">
      <?php else: ?>
        <span><?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'SpiderCMS'; ?></span>
      <?php endif; ?>
    </a>
    <?php if ($menu_enabled && !empty($menu_items)): ?>
      <nav class="nav-menu" id="navMenu">
        <?php foreach ($menu_items as $item): ?>
          <?php
            $label = $item['label'] ?? '';
            $url = $item['url'] ?? '#';
            $icon = trim($item['icon'] ?? '');
          ?>
          <a href="<?php echo htmlspecialchars($url); ?>">
            <?php if ($icon): ?>
              <?php if (preg_match('/^https?:\/\//', $icon) || str_starts_with($icon, '/')): ?>
                <img src="<?php echo htmlspecialchars($icon); ?>" alt="">
              <?php else: ?>
                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
              <?php endif; ?>
            <?php endif; ?>
            <?php echo htmlspecialchars($label); ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="menu-toggle" onclick="document.getElementById('navMenu')?.classList.toggle('active')"><i class="fa-solid fa-bars"></i></div>
    <?php endif; ?>
  </div>
</header>