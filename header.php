<?php
// header.php

require_once __DIR__ . '/config.php';

// Wczytanie ustawień (logo + ewentualnie inne rzeczy w przyszłości)
$settings_file = __DIR__ . '/.settings.json';
$settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
$logo_url = $settings['logo'] ?? '';

// Menu
$menu_enabled = file_exists(__DIR__ . '/.menu_enabled');
$menu_items = $menu_enabled ? json_decode(@file_get_contents(__DIR__ . '/.menu.json') ?: '[]', true) : [];
?>

<header class="site-header">
    <div class="header-container">
        <a href="/" class="logo">
            <?php if ($logo_url): ?>
                <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" style="max-height:50px; width:auto;">
            <?php else: ?>
                <?= htmlspecialchars(SITE_NAME) ?>
            <?php endif; ?>
        </a>

        <div class="menu-toggle" onclick="document.querySelector('.nav-menu').classList.toggle('active')">
            <i class="fa-solid fa-bars"></i>
        </div>

        <nav class="nav-menu">
            <?php if ($menu_enabled): ?>
                <?php foreach ($menu_items as $item): 
                    $url   = htmlspecialchars($item['url'] ?? '#');
                    $label = htmlspecialchars($item['label'] ?? '');
                    $icon  = $item['icon'] ?? '';
                    $display = $label;

                    if ($icon) {
                        if (strpos($icon, 'fa-') === 0) {
                            $display = '<i class="' . htmlspecialchars($icon) . '" style="font-size:1.3rem;"></i>';
                        } else {
                            $display = '<img src="' . htmlspecialchars($icon) . '" alt="' . $label . '" style="height:28px; width:auto; vertical-align:middle;">';
                        }
                    }
                ?>
                    <a href="<?= $url ?>" title="<?= $label ?>"><?= $display ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </div>
</header>