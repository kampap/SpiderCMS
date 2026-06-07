<?php
// ======================================================================
// header.php – globalny nagłówek SpiderCMS
// Ten plik jest generowany / aktualizowany z panelu admin.php.
// ======================================================================

$settings_file = __DIR__ . '/.settings.json';
$menu_file = __DIR__ . '/.menu.json';
$menu_enabled_file = __DIR__ . '/.menu_enabled';

$settings = [
    'logo' => '',
    'header_enabled' => '1',
];

if (file_exists($settings_file)) {
    $loaded_settings = json_decode((string)file_get_contents($settings_file), true);
    if (is_array($loaded_settings)) {
        $settings = array_merge($settings, $loaded_settings);
    }
}

$header_enabled = ($settings['header_enabled'] ?? '1') === '1' || ($settings['header_enabled'] ?? true) === true;
if (!$header_enabled) {
    return;
}

$logo_url = trim((string)($settings['logo'] ?? ''));
if ($logo_url === '') {
    $logo_url = (defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png';
}

$menu_enabled = file_exists($menu_enabled_file);
$menu_items = [];
if (file_exists($menu_file)) {
    $loaded_menu = json_decode((string)file_get_contents($menu_file), true);
    if (is_array($loaded_menu)) {
        $menu_items = $loaded_menu;
    }
}
?>
<header class="site-header">
  <div class="header-container">
    <a class="logo" href="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
      <?php if ($logo_url !== ''): ?>
        <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Logo', ENT_QUOTES, 'UTF-8'); ?>">
      <?php else: ?>
        <?php echo htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'SpiderCMS', ENT_QUOTES, 'UTF-8'); ?>
      <?php endif; ?>
    </a>

    <?php if ($menu_enabled && !empty($menu_items)): ?>
      <nav class="nav-menu" id="navMenu">
        <?php foreach ($menu_items as $item): ?>
          <?php
            $label = trim((string)($item['label'] ?? ''));
            $url = trim((string)($item['url'] ?? '#'));
            $icon = trim((string)($item['icon'] ?? ''));
            if ($label === '') { continue; }
          ?>
          <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($icon !== ''): ?>
              <?php if (preg_match('~^https?://|^/|\.(png|jpg|jpeg|gif|webp|svg)$~i', $icon)): ?>
                <img src="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" alt="">
              <?php else: ?>
                <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
              <?php endif; ?>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="menu-toggle" onclick="document.getElementById('navMenu')?.classList.toggle('active')">☰</div>
    <?php endif; ?>
  </div>
</header>