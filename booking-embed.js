(function(){
  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function(){
    document.querySelectorAll('[data-spidercms-booking]').forEach(function(box){
      if(box.dataset.loaded === '1') return;
      box.dataset.loaded = '1';
      var endpoint = box.getAttribute('data-endpoint') || '/admin.php';
      box.innerHTML = '<div style="padding:1rem;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-family:system-ui,sans-serif">Ładowanie rezerwacji...</div>';

      fetch(endpoint + '?action=booking_public_widget', {credentials:'same-origin'})
        .then(function(r){ return r.text(); })
        .then(function(html){
          box.innerHTML = html;
          box.querySelectorAll('script').forEach(function(oldScript){
            var s = document.createElement('script');
            if(oldScript.src) s.src = oldScript.src;
            s.text = oldScript.textContent || '';
            document.body.appendChild(s);
            oldScript.remove();
          });
        })
        .catch(function(){
          box.innerHTML = '<div style="padding:1rem;border-radius:14px;background:#fee2e2;color:#991b1b">Nie udało się załadować modułu rezerwacji.</div>';
        });
    });
  });
})();