<?php
$social_meta = array (
  'enabled' => '0',
  'title' => '',
  'description' => '',
  'image' => '',
);
if (($social_meta['enabled'] ?? '1') !== '1') return;
$og_title = trim((string)($social_meta['title'] ?? ''));
$og_desc = trim((string)($social_meta['description'] ?? ''));
$og_image = trim((string)($social_meta['image'] ?? ''));
if ($og_title === '' && defined('SITE_NAME')) $og_title = SITE_NAME;
if ($og_title !== '') echo '<meta property="og:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if ($og_desc !== '') echo '<meta property="og:description" content="' . htmlspecialchars($og_desc, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if ($og_image !== '') echo '<meta property="og:image" content="' . htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if (defined('SITE_NAME')) echo '<meta property="og:site_name" content="' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<meta property="og:type" content="website">' . "\n";
?>