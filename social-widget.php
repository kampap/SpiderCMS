<?php
$social_payload = array (
  'settings' => 
  array (
    'enabled' => '1',
    'show_header' => '0',
    'show_footer' => '1',
    'show_floating' => '1',
    'show_contact_widget' => '0',
    'floating_side' => 'right',
    'og_enabled' => '0',
    'og_title' => '',
    'og_description' => '',
    'og_image' => '',
    'email' => '',
    'phone' => '',
    'facebook' => 'https://www.facebook.com/kampap91',
    'instagram' => '',
    'youtube' => '',
    'tiktok' => '',
    'linkedin' => '',
    'x' => '',
    'github' => '',
    'discord' => '',
    'whatsapp' => '',
    'messenger' => '',
  ),
  'links' => 
  array (
    0 => 
    array (
      'key' => 'facebook',
      'label' => 'Facebook',
      'icon' => 'fa-brands fa-facebook-f',
      'url' => 'https://www.facebook.com/kampap91',
    ),
  ),
);
$social_settings = $social_payload['settings'] ?? [];
$social_links = $social_payload['links'] ?? [];
if (($social_settings['enabled'] ?? '1') !== '1' || empty($social_links)) return;
function spidercms_social_safe_url($url) {
    $url = trim((string)$url);
    return preg_match('~^(https?://|mailto:|tel:)~i', $url) ? $url : '#';
}
function spidercms_social_render_link($item, $class = 'spidercms-social-link') {
    $url = spidercms_social_safe_url($item['url'] ?? '#');
    $label = htmlspecialchars($item['label'] ?? 'Social', ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($item['icon'] ?? 'fa-solid fa-link', ENT_QUOTES, 'UTF-8');
    $target = preg_match('~^https?://~i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
    return '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" aria-label="' . $label . '" title="' . $label . '"' . $target . '><i class="' . $icon . '"></i><span>' . $label . '</span></a>';
}
?>
<style>
.spidercms-social-link{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;text-decoration:none;transition:.18s}.spidercms-social-link i{font-size:1.05em}.spidercms-social-footer{max-width:var(--content-width,1240px);margin:1.5rem auto 0;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.14);display:flex;flex-wrap:wrap;gap:.7rem;align-items:center}.spidercms-social-footer-title{font-weight:800;color:var(--footer-text,#fff);margin-right:.35rem}.spidercms-social-footer .spidercms-social-link{width:38px;height:38px;border-radius:999px;background:rgba(255,255,255,.09);color:var(--footer-text,#fff)}.spidercms-social-footer .spidercms-social-link span,.spidercms-social-header .spidercms-social-link span,.spidercms-social-float .spidercms-social-link span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.spidercms-social-header{display:flex;gap:.45rem;align-items:center;margin-left:1rem}.spidercms-social-header .spidercms-social-link{width:32px;height:32px;border-radius:999px;background:rgba(168,85,247,.10);color:var(--header-text,#374151)}.spidercms-social-header .spidercms-social-link:hover,.spidercms-social-footer .spidercms-social-link:hover{transform:translateY(-2px);color:var(--primary,#a855f7)}.spidercms-social-float{position:fixed;top:50%;transform:translateY(-50%);z-index:99990;display:flex;flex-direction:column;gap:.55rem}.spidercms-social-float.right{right:18px}.spidercms-social-float.left{left:18px}.spidercms-social-float .spidercms-social-link{width:44px;height:44px;border-radius:999px;background:#111827;color:#fff;box-shadow:0 12px 28px rgba(0,0,0,.25)}.spidercms-social-float .spidercms-social-link:hover{transform:scale(1.08);background:var(--primary,#a855f7)}.spidercms-social-contact{position:fixed;left:22px;bottom:22px;z-index:99988;background:#fff;color:#111827;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 20px 50px rgba(0,0,0,.22);width:min(330px,calc(100vw - 32px));overflow:hidden}.spidercms-social-contact-head{padding:14px 16px;background:linear-gradient(135deg,#111827,#7e22ce);color:#fff;font-weight:900}.spidercms-social-contact-body{padding:12px;display:grid;gap:.55rem}.spidercms-social-contact .spidercms-social-link{justify-content:flex-start;padding:.75rem .85rem;border-radius:12px;background:#f8fafc;color:#111827;font-weight:700}.spidercms-social-contact .spidercms-social-link:hover{background:#f3e8ff;color:#7e22ce}@media(max-width:760px){.spidercms-social-float{top:auto;bottom:88px;transform:none}.spidercms-social-float.right{right:12px}.spidercms-social-float.left{left:12px}.spidercms-social-contact{left:12px;bottom:12px}.spidercms-social-header{display:none}}
</style>
<?php if (($social_settings['show_floating'] ?? '0') === '1'): ?>
<div class="spidercms-social-float <?php echo htmlspecialchars(($social_settings['floating_side'] ?? 'right') === 'left' ? 'left' : 'right'); ?>">
  <?php foreach ($social_links as $item) echo spidercms_social_render_link($item); ?>
</div>
<?php endif; ?>
<?php if (($social_settings['show_contact_widget'] ?? '0') === '1'): ?>
<div class="spidercms-social-contact">
  <div class="spidercms-social-contact-head">Szybki kontakt</div>
  <div class="spidercms-social-contact-body">
    <?php foreach ($social_links as $item) echo spidercms_social_render_link($item); ?>
  </div>
</div>
<?php endif; ?>
<script>
(function(){
  const linksHtml = <?php echo json_encode(array_map(fn($item) => spidercms_social_render_link($item), $social_links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>.join('');
  const showHeader = <?php echo json_encode(($social_settings['show_header'] ?? '0') === '1'); ?>;
  const showFooter = <?php echo json_encode(($social_settings['show_footer'] ?? '0') === '1'); ?>;
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    if (showHeader) {
      const nav = document.querySelector('.nav-menu') || document.querySelector('.header-container');
      if (nav && !document.querySelector('.spidercms-social-header')) {
        const box = document.createElement('div'); box.className = 'spidercms-social-header'; box.innerHTML = linksHtml; nav.appendChild(box);
      }
    }
    if (showFooter) {
      const footer = document.querySelector('.site-footer');
      if (footer && !document.querySelector('.spidercms-social-footer')) {
        const box = document.createElement('div'); box.className = 'spidercms-social-footer'; box.innerHTML = '<span class="spidercms-social-footer-title">Social media:</span>' + linksHtml; footer.appendChild(box);
      }
    }
  });
})();
</script>