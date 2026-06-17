<?php
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}

$settings_file = __DIR__ . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode((string)file_get_contents($settings_file), true) : [];
if (!is_array($settings)) $settings = [];
if (!array_key_exists('show_site_name_in_header', $settings)) $settings['show_site_name_in_header'] = '0';
if (!array_key_exists('header_title_font_size', $settings)) $settings['header_title_font_size'] = '22';
if (!array_key_exists('header_title_font_weight', $settings)) $settings['header_title_font_weight'] = '800';
if (!array_key_exists('header_title_color', $settings)) $settings['header_title_color'] = '';
if (!array_key_exists('header_title_gap', $settings)) $settings['header_title_gap'] = '10';
if (!array_key_exists('header_title_uppercase', $settings)) $settings['header_title_uppercase'] = '0';
if (!array_key_exists('header_title_italic', $settings)) $settings['header_title_italic'] = '0';
if (!array_key_exists('header_title_shadow', $settings)) $settings['header_title_shadow'] = '0';
if (!array_key_exists('header_title_bg', $settings)) $settings['header_title_bg'] = '';
if (!array_key_exists('header_title_radius', $settings)) $settings['header_title_radius'] = '0';
$show_site_name_in_header = (string)($settings['show_site_name_in_header'] ?? '0') === '1';

if (!function_exists('spidercms_header_public_url')) {
    function spidercms_header_public_url($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('~^(https?:)?//|^data:|^mailto:|^tel:|^/~i', $url)) return $url;
        $base = defined('BASE_URL') ? trim((string)BASE_URL) : '';
        if ($base === '') {
            $base = '/';
        }
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}

$logo_url = $logo_url ?? ($settings['logo'] ?? ((defined('BASE_URL') ? BASE_URL : '') . 'assets/images/spidercms-icon.png'));
$logo_url = spidercms_header_public_url($logo_url);
$menu_enabled = $menu_enabled ?? file_exists(__DIR__ . '/.menu_enabled');
$menu_items = $menu_items ?? json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true);
if (!is_array($menu_items)) $menu_items = [];

$theme_file = __DIR__ . '/.theme.json';
$theme = file_exists($theme_file) ? json_decode((string)file_get_contents($theme_file), true) : [];
if (!is_array($theme)) $theme = [];
$menu_position = $theme['menu-position'] ?? 'right';
if (!in_array($menu_position, ['left','center','right'], true)) $menu_position = 'right';

if (!function_exists('spidercms_header_icon_html')) {
    function spidercms_header_icon_html($icon) {
        $icon = trim((string)$icon);
        if ($icon === '') return '';
        if (preg_match('~^(https?:)?//|^/|\.(png|jpe?g|gif|webp|svg)$~i', $icon)) {
            if (function_exists('spidercms_header_public_url')) {
                $icon = spidercms_header_public_url($icon);
            }
            return '<img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt="">';
        }
        return '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>';
    }
}
$header_title_font_size = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_font_size'] ?? '22'));
if ($header_title_font_size === '') $header_title_font_size = '22';
$header_title_font_weight = preg_replace('/[^0-9]/', '', (string)($settings['header_title_font_weight'] ?? '800'));
if ($header_title_font_weight === '') $header_title_font_weight = '800';
$header_title_gap = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_gap'] ?? '10'));
if ($header_title_gap === '') $header_title_gap = '10';
$header_title_radius = preg_replace('/[^0-9.]/', '', (string)($settings['header_title_radius'] ?? '0'));
if ($header_title_radius === '') $header_title_radius = '0';
$header_title_color_raw = trim((string)($settings['header_title_color'] ?? ''));
$header_title_color = $header_title_color_raw !== '' ? preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', $header_title_color_raw) : 'var(--header-text,#374151)';
$header_title_bg_raw = trim((string)($settings['header_title_bg'] ?? ''));
$header_title_bg = $header_title_bg_raw !== '' ? preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', $header_title_bg_raw) : 'transparent';
$header_title_transform = ((string)($settings['header_title_uppercase'] ?? '0') === '1') ? 'uppercase' : 'none';
$header_title_style = ((string)($settings['header_title_italic'] ?? '0') === '1') ? 'italic' : 'normal';
$header_title_shadow = ((string)($settings['header_title_shadow'] ?? '0') === '1') ? '0 2px 10px rgba(0,0,0,.25)' : 'none';
?>
<?php
$spidercms_theme_css_file = __DIR__ . '/assets/spidercms-theme.css';
$spidercms_theme_css_v = file_exists($spidercms_theme_css_file) ? filemtime($spidercms_theme_css_file) : time();
$spidercms_theme_css_href = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '') . '/assets/spidercms-theme.css?v=' . $spidercms_theme_css_v;
?>
<link id="spidercms-global-theme-css" rel="stylesheet" href="<?= htmlspecialchars($spidercms_theme_css_href, ENT_QUOTES, 'UTF-8') ?>">
<style id="spidercms-submenu-style">
.nav-item{position:relative;display:flex;align-items:center}.nav-item>a{white-space:nowrap}.nav-item.has-submenu>a::after{content:"▾";font-size:.72em;margin-left:.35rem;opacity:.75}.submenu{display:none;position:absolute;left:0;top:100%;min-width:220px;background:var(--header-bg,#fff);box-shadow:0 14px 35px rgba(0,0,0,.16);border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:.45rem;z-index:1005}.nav-item.has-submenu:hover>.submenu,.nav-item.has-submenu:focus-within>.submenu{display:flex;flex-direction:column;gap:.15rem}.submenu a{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;border-radius:10px;color:var(--header-text,#374151);text-decoration:none;white-space:nowrap}.submenu a:hover{background:rgba(168,85,247,.10);color:var(--primary,#a855f7)}.logo{gap:.65rem}.header-site-name{display:inline-block;color:var(--header-text,#374151);font-weight:800;font-size:clamp(1rem,1.8vw,1.35rem);line-height:1.1;white-space:nowrap}.logo img + .header-site-name{margin-left:.1rem}@media(max-width:768px){.nav-item{width:100%;display:block}.submenu{position:static;display:flex;flex-direction:column;box-shadow:none;border:0;background:rgba(0,0,0,.03);margin:.2rem 0 .4rem 1rem;min-width:0}.submenu a{white-space:normal}.header-site-name{font-size:1rem;max-width:52vw;overflow:hidden;text-overflow:ellipsis}}
</style>
<style id="spidercms-header-title-style">
.logo{gap:<?= htmlspecialchars($header_title_gap, ENT_QUOTES, 'UTF-8') ?>px!important;}
.header-site-name{
    color:<?= htmlspecialchars($header_title_color, ENT_QUOTES, 'UTF-8') ?>!important;
    font-size:<?= htmlspecialchars($header_title_font_size, ENT_QUOTES, 'UTF-8') ?>px!important;
    font-weight:<?= htmlspecialchars($header_title_font_weight, ENT_QUOTES, 'UTF-8') ?>!important;
    text-transform:<?= htmlspecialchars($header_title_transform, ENT_QUOTES, 'UTF-8') ?>!important;
    font-style:<?= htmlspecialchars($header_title_style, ENT_QUOTES, 'UTF-8') ?>!important;
    text-shadow:<?= htmlspecialchars($header_title_shadow, ENT_QUOTES, 'UTF-8') ?>!important;
    background:<?= htmlspecialchars($header_title_bg, ENT_QUOTES, 'UTF-8') ?>!important;
    border-radius:<?= htmlspecialchars($header_title_radius, ENT_QUOTES, 'UTF-8') ?>px!important;
    padding:<?= $header_title_bg !== 'transparent' ? '0.18em 0.42em' : '0' ?>!important;
}
@media(max-width:768px){.header-site-name{font-size:min(<?= htmlspecialchars($header_title_font_size, ENT_QUOTES, 'UTF-8') ?>px,1rem)!important;}}
</style>
<header class="site-header menu-<?= htmlspecialchars($menu_position, ENT_QUOTES, 'UTF-8') ?>">
    <div class="header-container">
        <a href="<?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') ?: '/', ENT_QUOTES, 'UTF-8') ?>" class="logo">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Logo', ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <?php if ($show_site_name_in_header || empty($logo_url)): ?>
                <span class="header-site-name"><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'SpiderCMS', ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </a>

        <?php if ($menu_enabled && !empty($menu_items)): ?>
            <nav class="nav-menu" id="spidercms-nav-menu">
                <?php foreach ($menu_items as $item): ?>
                    <?php
                    $label = trim((string)($item['label'] ?? ''));
                    $url = trim((string)($item['url'] ?? '#'));
                    $icon = trim((string)($item['icon'] ?? ''));
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    if ($label === '' && $url === '') continue;
                    ?>
                    <?php if (!empty($children)): ?>
                        <div class="nav-item has-submenu">
                            <a href="<?= htmlspecialchars($url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($icon) ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                            <div class="submenu">
                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $child_label = trim((string)($child['label'] ?? ''));
                                    $child_url = trim((string)($child['url'] ?? '#'));
                                    $child_icon = trim((string)($child['icon'] ?? ''));
                                    if ($child_label === '' && $child_url === '') continue;
                                    ?>
                                    <a href="<?= htmlspecialchars($child_url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($child_icon) ?><?= htmlspecialchars($child_label, ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($url ?: '#', ENT_QUOTES, 'UTF-8') ?>"><?= spidercms_header_icon_html($icon) ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <div class="menu-toggle" onclick="document.getElementById('spidercms-nav-menu')?.classList.toggle('active')">☰</div>
        <?php endif; ?>
    </div>
</header>