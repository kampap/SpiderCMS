<?php
$spidercms_sliders = array (
);
if (!is_array($spidercms_sliders) || empty($spidercms_sliders)) return;
?>
<style>
.spidercms-slider{position:relative;overflow:hidden;width:100%;margin:1.5rem auto;border-radius:var(--slider-radius,18px);background:#0f172a;box-shadow:0 20px 50px rgba(15,23,42,.18)}
.spidercms-slider-track{display:flex;height:100%;transition:transform .55s ease;will-change:transform}.spidercms-slide{position:relative;min-width:100%;height:100%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--slider-bg,#0f172a)}.spidercms-slide img{width:100%;height:100%;display:block}.spidercms-slider.fit-contain .spidercms-slide img{object-fit:contain;max-width:100%;max-height:100%;background:transparent}.spidercms-slider.fit-cover .spidercms-slide img{object-fit:cover}.spidercms-slider.fit-auto .spidercms-slide img{width:auto;height:auto;max-width:100%;max-height:100%;object-fit:contain}.spidercms-slide-caption{position:absolute;left:0;right:0;bottom:0;padding:clamp(1rem,3vw,2rem);color:#fff;background:linear-gradient(to top,rgba(0,0,0,.72),rgba(0,0,0,0));text-shadow:0 2px 10px rgba(0,0,0,.35)}.spidercms-slide-caption h3{margin:0 0 .35rem;font-size:clamp(1.35rem,3vw,2.4rem);line-height:1.1}.spidercms-slide-caption p{margin:0;max-width:720px;opacity:.92}.spidercms-slider.no-overlay .spidercms-slide-caption{background:transparent;text-shadow:0 2px 12px rgba(0,0,0,.65)}.spidercms-slider-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:44px;height:44px;border:0;border-radius:999px;background:rgba(255,255,255,.88);color:#111827;font-size:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 25px rgba(0,0,0,.18)}.spidercms-slider-arrow:hover{background:#fff}.spidercms-slider-prev{left:16px}.spidercms-slider-next{right:16px}.spidercms-slider-dots{position:absolute;left:0;right:0;bottom:14px;z-index:6;display:flex;gap:8px;justify-content:center}.spidercms-slider-dot{width:10px;height:10px;border-radius:999px;border:0;background:rgba(255,255,255,.55);cursor:pointer}.spidercms-slider-dot.active{background:#fff;transform:scale(1.25)}.spidercms-slider.style-glass{box-shadow:0 24px 70px rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.25)}.spidercms-slider.style-minimal{box-shadow:none}.spidercms-slider.style-dark{background:#020617;box-shadow:0 24px 60px rgba(0,0,0,.35)}.spidercms-slider.style-cards{padding:10px;background:#fff}.spidercms-slider.style-cards .spidercms-slide img{border-radius:calc(var(--slider-radius,18px) - 6px)}@media(max-width:700px){.spidercms-slider{height:min(var(--slider-height,420px),60vh)!important}.spidercms-slider-arrow{width:38px;height:38px}.spidercms-slide-caption{padding:1rem 1rem 2.2rem}}
</style>
<script>
(function(){
  const sliders = <?php echo json_encode($spidercms_sliders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function publicUrl(url){url=String(url||'').trim(); if(!url) return ''; if(/^(https?:)?\/\//i.test(url)||url[0]==='/'||/^data:image\//i.test(url)) return url; const depth=(location.pathname.match(/\//g)||[]).length-1; return '../'.repeat(Math.max(0,depth)) + url.replace(/^\/+/, '');}
  function renderSlider(id){
    const s = sliders[id]; if(!s || !Array.isArray(s.images) || !s.images.length) return '<div class="spidercms-slider-missing">Brak slidera: '+esc(id)+'</div>';
    const h = parseInt(s.height||420,10); const radius=parseInt(s.radius||18,10);
    const fit = ['contain','cover','auto'].includes(String(s.fit_mode||'contain')) ? String(s.fit_mode||'contain') : 'contain';
    let html = '<div class="spidercms-slider style-'+esc(s.style||'modern')+' fit-'+esc(fit)+(s.overlay==='1'?'':' no-overlay')+'" data-autoplay="'+esc(s.autoplay||'0')+'" data-interval="'+esc(s.interval||4500)+'" style="height:'+h+'px;--slider-height:'+h+'px;--slider-radius:'+radius+'px">';
    html += '<div class="spidercms-slider-track">';
    s.images.forEach(function(img){ html += '<div class="spidercms-slide"><img src="'+esc(publicUrl(img.url))+'" alt="'+esc(img.title||s.name||'Slider')+'">'; if(img.title || img.desc){ html += '<div class="spidercms-slide-caption">'+(img.title?'<h3>'+esc(img.title)+'</h3>':'')+(img.desc?'<p>'+esc(img.desc)+'</p>':'')+'</div>'; } html += '</div>'; });
    html += '</div>';
    if(s.arrows==='1' && s.images.length>1){ html += '<button class="spidercms-slider-arrow spidercms-slider-prev" type="button" aria-label="Poprzedni">‹</button><button class="spidercms-slider-arrow spidercms-slider-next" type="button" aria-label="Następny">›</button>'; }
    if(s.dots==='1' && s.images.length>1){ html += '<div class="spidercms-slider-dots">'+s.images.map(function(_,i){return '<button class="spidercms-slider-dot'+(i===0?' active':'')+'" type="button" data-i="'+i+'"></button>';}).join('')+'</div>'; }
    html += '</div>'; return html;
  }
  function replaceShortcodes(root){
    root = root || document.body;
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:function(n){return n.nodeValue.indexOf('[slider')!==-1?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;}});
    const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(n){
      const span=document.createElement('span');
      span.innerHTML = n.nodeValue.replace(/\[slider\s+id=["']?([a-zA-Z0-9_-]+)["']?\]/g,function(_,id){return renderSlider(id);});
      n.parentNode.replaceChild(span,n);
    });
  }
  function initSlider(el){
    const track=el.querySelector('.spidercms-slider-track'); const slides=el.querySelectorAll('.spidercms-slide'); if(!track||slides.length<2) return;
    let i=0; const dots=el.querySelectorAll('.spidercms-slider-dot');
    function go(n){ i=(n+slides.length)%slides.length; track.style.transform='translateX('+(-i*100)+'%)'; dots.forEach(function(d,k){d.classList.toggle('active',k===i);}); }
    const prev=el.querySelector('.spidercms-slider-prev'); const next=el.querySelector('.spidercms-slider-next'); if(prev) prev.onclick=function(){go(i-1)}; if(next) next.onclick=function(){go(i+1)}; dots.forEach(function(d){d.onclick=function(){go(parseInt(d.dataset.i||0,10));};});
    if(el.dataset.autoplay==='1'){ setInterval(function(){go(i+1)}, Math.max(1500, parseInt(el.dataset.interval||4500,10))); }
  }
  function boot(){ replaceShortcodes(document.body); document.querySelectorAll('.spidercms-slider').forEach(initSlider); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
</script>