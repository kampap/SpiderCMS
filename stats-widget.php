<?php
$stats_settings_file = __DIR__ . '/.stats/settings.json';
$stats_settings = file_exists($stats_settings_file) ? json_decode((string)file_get_contents($stats_settings_file), true) : [];
if (!is_array($stats_settings)) $stats_settings = [];
if (($stats_settings['enabled'] ?? '1') !== '1') return;
?>
<script>
(function(){
  try{
    var key = 'spidercms_stats_visitor_v1';
    var visitor = localStorage.getItem(key);
    if(!visitor){ visitor = 'v_' + Date.now() + '_' + Math.random().toString(16).slice(2); localStorage.setItem(key, visitor); }
    var payload = new FormData();
    payload.set('action','stats_track');
    payload.set('visitor', visitor);
    payload.set('path', location.pathname + location.search);
    payload.set('title', document.title || '');
    payload.set('referrer', document.referrer || '');
    var candidates = [];
    <?php if (defined('BASE_URL') && BASE_URL !== ''): ?>
      candidates.push(<?php echo json_encode(rtrim(BASE_URL, '/') . '/admin.php'); ?>);
    <?php endif; ?>
    candidates.push('admin.php','../admin.php','../../admin.php','../../../admin.php','/admin.php');
    var i = 0;
    function sendNext(){
      if(i >= candidates.length) return;
      var url = candidates[i++];
      fetch(url, {method:'POST', body:payload, credentials:'same-origin', cache:'no-store'}).then(function(r){
        if(!r.ok) throw new Error('bad');
        return r.json();
      }).catch(sendNext);
    }
    if('requestIdleCallback' in window) requestIdleCallback(sendNext); else setTimeout(sendNext, 800);
  }catch(e){}
})();
</script>