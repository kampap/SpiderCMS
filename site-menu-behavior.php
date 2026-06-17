<?php
$spider_menu_cfg = array (
  'behavior' => 'standard',
  'transparentTop' => false,
  'blur' => false,
  'shadowScroll' => true,
  'autoHide' => false,
  'animate' => true,
  'mobileStyle' => 'dropdown',
  'mobileSide' => 'right',
  'mobileAutoclose' => true,
  'height' => 74,
  'radius' => 0,
  'activeStyle' => 'underline',
  'hoverStyle' => 'lift',
  'logoShrink' => true,
  'progress' => false,
  'ctaEnabled' => false,
  'ctaText' => 'Umów wizytę',
  'ctaUrl' => '#kontakt',
);
?>
<style>
:root{
    --spider-site-menu-height: <?php echo (int)$spider_menu_cfg['height']; ?>px;
    --spider-site-menu-radius: <?php echo (int)$spider_menu_cfg['radius']; ?>px;
}
.site-header{
    min-height:var(--spider-site-menu-height)!important;
    border-radius:0 0 var(--spider-site-menu-radius) var(--spider-site-menu-radius)!important;
    transition:transform .25s ease, background .25s ease, box-shadow .25s ease, backdrop-filter .25s ease!important;
}
<?php if (in_array($spider_menu_cfg['behavior'], ['sticky','autohide'], true)): ?>
.site-header{position:sticky!important;top:0!important;z-index:9990!important}
<?php elseif ($spider_menu_cfg['behavior'] === 'fixed'): ?>
.site-header{position:fixed!important;top:0!important;left:0!important;right:0!important;z-index:9990!important}
body{padding-top:var(--spider-site-menu-height)}
<?php endif; ?>
<?php if ($spider_menu_cfg['transparentTop']): ?>
.site-header:not(.spider-menu-scrolled){background:transparent!important;box-shadow:none!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['blur']): ?>
.site-header{backdrop-filter:blur(14px)!important;-webkit-backdrop-filter:blur(14px)!important;background:rgba(255,255,255,.78)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['shadowScroll']): ?>
.site-header.spider-menu-scrolled{box-shadow:0 12px 34px rgba(15,23,42,.14)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['autoHide']): ?>
.site-header.spider-menu-hidden{transform:translateY(-110%)!important}
<?php endif; ?>
<?php if ($spider_menu_cfg['animate']): ?>
.site-header{animation:spiderMenuIn .38s ease both}
@keyframes spiderMenuIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
<?php endif; ?>
<?php if ($spider_menu_cfg['logoShrink']): ?>
.site-header.spider-menu-scrolled .site-logo,
.site-header.spider-menu-scrolled .logo img,
.site-header.spider-menu-scrolled img.logo{max-height:42px!important;transition:max-height .25s ease}
<?php endif; ?>
.nav-menu a,.site-header nav a{transition:transform .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease}
<?php if ($spider_menu_cfg['hoverStyle'] === 'lift'): ?>
.nav-menu a:hover,.site-header nav a:hover{transform:translateY(-2px)}
<?php elseif ($spider_menu_cfg['hoverStyle'] === 'underline'): ?>
.nav-menu a:hover,.site-header nav a:hover{text-decoration:underline;text-underline-offset:6px}
<?php elseif ($spider_menu_cfg['hoverStyle'] === 'background'): ?>
.nav-menu a:hover,.site-header nav a:hover{background:rgba(168,85,247,.12);border-radius:999px}
<?php endif; ?>
<?php if ($spider_menu_cfg['activeStyle'] === 'underline'): ?>
.nav-menu a.active,.site-header nav a.active{border-bottom:2px solid var(--primary,#a855f7)}
<?php elseif ($spider_menu_cfg['activeStyle'] === 'pill'): ?>
.nav-menu a.active,.site-header nav a.active{background:var(--primary,#a855f7);color:#fff!important;border-radius:999px}
<?php elseif ($spider_menu_cfg['activeStyle'] === 'box'): ?>
.nav-menu a.active,.site-header nav a.active{background:rgba(168,85,247,.12);box-shadow:inset 0 0 0 1px rgba(168,85,247,.28);border-radius:12px}
<?php endif; ?>
#spider-scroll-progress{position:fixed;top:0;left:0;height:3px;background:var(--primary,#a855f7);width:0;z-index:10000;display:<?php echo $spider_menu_cfg['progress'] ? 'block' : 'none'; ?>}
.spider-menu-cta{display:inline-flex;align-items:center;justify-content:center;padding:.65rem 1rem;border-radius:999px;background:var(--primary,#a855f7);color:#fff!important;text-decoration:none!important;font-weight:800;margin-left:.6rem}
@media(max-width:760px){
    <?php if ($spider_menu_cfg['mobileStyle'] === 'fullscreen'): ?>
    .nav-menu,.site-header nav{position:fixed!important;inset:0!important;z-index:9998!important;background:#fff!important;display:none!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:1rem!important;padding:2rem!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{display:flex!important}
    <?php elseif ($spider_menu_cfg['mobileStyle'] === 'side'): ?>
    .nav-menu,.site-header nav{position:fixed!important;top:0!important;bottom:0!important;<?php echo ($spider_menu_cfg['mobileSide'] === 'left') ? 'left:0;transform:translateX(-105%)' : 'right:0;transform:translateX(105%)'; ?>!important;width:min(340px,86vw)!important;z-index:9998!important;background:#fff!important;display:flex!important;flex-direction:column!important;gap:.5rem!important;padding:5rem 1rem 1rem!important;transition:transform .25s ease!important;box-shadow:0 20px 60px rgba(0,0,0,.25)!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{transform:translateX(0)!important}
    <?php else: ?>
    .nav-menu,.site-header nav{display:none!important;position:absolute!important;top:100%!important;left:0!important;right:0!important;background:#fff!important;flex-direction:column!important;padding:1rem!important;box-shadow:0 16px 40px rgba(0,0,0,.14)!important}
    body.spider-site-menu-open .nav-menu,body.spider-site-menu-open .site-header nav{display:flex!important}
    <?php endif; ?>
}
</style>
<div id="spider-scroll-progress"></div>
<script>
(function(){
    const cfg = <?php echo json_encode($spider_menu_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function(){
        const header = document.querySelector('.site-header');
        if(!header) return;

        let lastY = window.scrollY || 0;
        const progress = document.getElementById('spider-scroll-progress');

        function onScroll(){
            const y = window.scrollY || 0;
            header.classList.toggle('spider-menu-scrolled', y > 10);

            if(cfg.autoHide){
                header.classList.toggle('spider-menu-hidden', y > lastY && y > 90);
            }

            if(progress && cfg.progress){
                const h = document.documentElement;
                const max = Math.max(1, h.scrollHeight - h.clientHeight);
                progress.style.width = Math.min(100, Math.max(0, (y / max) * 100)) + '%';
            }

            lastY = y;
        }

        window.addEventListener('scroll', onScroll, {passive:true});
        onScroll();

        if(cfg.ctaEnabled){
            const nav = header.querySelector('.nav-menu') || header.querySelector('nav');
            if(nav && !nav.querySelector('.spider-menu-cta')){
                const a = document.createElement('a');
                a.className = 'spider-menu-cta';
                a.href = cfg.ctaUrl || '#';
                a.textContent = cfg.ctaText || 'Umów wizytę';
                nav.appendChild(a);
            }
        }

        document.querySelectorAll('.menu-toggle,.hamburger,.nav-toggle,[data-menu-toggle]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                document.body.classList.toggle('spider-site-menu-open');
            });
        });

        if(cfg.mobileAutoclose){
            document.querySelectorAll('.nav-menu a,.site-header nav a').forEach(function(a){
                a.addEventListener('click', function(){
                    document.body.classList.remove('spider-site-menu-open');
                });
            });
        }
    });
})();
</script>