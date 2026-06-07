<?php
$chat_settings_file = __DIR__ . '/.chat/settings.json';
$chat_settings = file_exists($chat_settings_file) ? json_decode(file_get_contents($chat_settings_file), true) : [];
if (!is_array($chat_settings)) $chat_settings = [];
$chat_enabled = ($chat_settings['enabled'] ?? '1') === '1';
if (!$chat_enabled) return;
$chat_title = $chat_settings['title'] ?? 'Masz pytanie?';
$chat_subtitle = $chat_settings['subtitle'] ?? 'Napisz do nas. Odpowiemy możliwie szybko.';
$chat_welcome = $chat_settings['welcome'] ?? 'Cześć! W czym możemy pomóc?';
$chat_button = $chat_settings['button_text'] ?? 'Chat';
?>
<div id="spidercms-chat">
  <button type="button" id="spidercms-chat-toggle">💬 <?php echo htmlspecialchars($chat_button); ?></button>
  <div id="spidercms-chat-box" aria-live="polite">
    <div class="spidercms-chat-head">
      <div>
        <strong><?php echo htmlspecialchars($chat_title); ?></strong>
        <span><?php echo htmlspecialchars($chat_subtitle); ?></span>
      </div>
      <button type="button" id="spidercms-chat-close">×</button>
    </div>
    <div id="spidercms-chat-messages">
      <div class="spidercms-chat-msg admin"><?php echo htmlspecialchars($chat_welcome); ?></div>
    </div>
    <form id="spidercms-chat-form">
      <div class="spidercms-chat-user-fields" id="spidercms-chat-user-fields">
        <input type="text" name="name" placeholder="Imię" maxlength="80" autocomplete="name">
        <input type="email" name="email" placeholder="E-mail, opcjonalnie" maxlength="120" autocomplete="email">
      </div>
      <div id="spidercms-chat-remembered" style="display:none;">
        <span></span>
        <button type="button" id="spidercms-chat-change-user">Zmień dane</button>
      </div>
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">
      <textarea name="message" rows="3" placeholder="Napisz wiadomość..." required maxlength="2000"></textarea>
      <button type="submit">Wyślij</button>
      <small id="spidercms-chat-status"></small>
    </form>
  </div>
</div>
<style>
#spidercms-chat{position:fixed;right:22px;bottom:22px;z-index:99999;font-family:system-ui,sans-serif;color:#0f172a}#spidercms-chat-toggle{border:0;border-radius:999px;background:#a855f7;color:#fff;padding:13px 18px;font-weight:800;box-shadow:0 12px 30px rgba(0,0,0,.28);cursor:pointer}#spidercms-chat-box{display:none;width:min(360px,calc(100vw - 32px));background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden}#spidercms-chat.open #spidercms-chat-box{display:block}#spidercms-chat.open #spidercms-chat-toggle{display:none}.spidercms-chat-head{background:linear-gradient(135deg,#7e22ce,#a855f7);color:#fff;padding:15px 16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.spidercms-chat-head span{display:block;font-size:12px;opacity:.88;margin-top:3px}.spidercms-chat-head button{background:transparent;border:0;color:#fff;font-size:26px;line-height:1;cursor:pointer}#spidercms-chat-messages{height:230px;overflow:auto;background:#f8fafc;padding:14px;display:flex;flex-direction:column;gap:9px}.spidercms-chat-msg{max-width:82%;padding:9px 11px;border-radius:14px;font-size:14px;line-height:1.35;word-break:break-word}.spidercms-chat-msg.admin{background:#fff;border:1px solid #e5e7eb;align-self:flex-start}.spidercms-chat-msg.user{background:#a855f7;color:#fff;align-self:flex-end}#spidercms-chat-form{padding:12px;background:#fff;border-top:1px solid #e5e7eb}.spidercms-chat-user-fields{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.spidercms-chat-user-fields.is-hidden{display:none}#spidercms-chat-remembered{align-items:center;justify-content:space-between;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;margin-bottom:8px;color:#475569;font-size:13px}#spidercms-chat-remembered button{border:0;background:transparent;color:#7e22ce;font-weight:800;cursor:pointer;padding:0;white-space:nowrap}#spidercms-chat.has-remembered-user .spidercms-chat-user-fields{display:none!important}#spidercms-chat input,#spidercms-chat textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:10px;padding:9px;font:inherit;font-size:14px}#spidercms-chat textarea{resize:vertical;min-height:72px}#spidercms-chat-form button[type=submit]{margin-top:8px;width:100%;border:0;border-radius:10px;background:#111827;color:#fff;padding:10px 12px;font-weight:800;cursor:pointer}#spidercms-chat-status{display:block;min-height:18px;margin-top:7px;color:#64748b}@media(max-width:520px){#spidercms-chat{right:12px;bottom:12px}.spidercms-chat-user-fields{grid-template-columns:1fr}}
</style>
<script>
(function(){
  const root = document.getElementById('spidercms-chat');
  if (!root) return;

  const box = document.getElementById('spidercms-chat-messages');
  const form = document.getElementById('spidercms-chat-form');
  const status = document.getElementById('spidercms-chat-status');
  const userFields = document.getElementById('spidercms-chat-user-fields');
  const remembered = document.getElementById('spidercms-chat-remembered');
  const rememberedText = remembered ? remembered.querySelector('span') : null;
  const changeUserBtn = document.getElementById('spidercms-chat-change-user');
  const nameInput = form ? form.querySelector('input[name="name"]') : null;
  const emailInput = form ? form.querySelector('input[name="email"]') : null;
  const messageInput = form ? form.querySelector('textarea[name="message"]') : null;
  const storageKey = 'spidercms_chat_user_v2';
  const oldStorageKey = 'spidercms_chat_user_v1';

  function readCookie(name){
    const parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift() || '');
    return '';
  }
  function writeCookie(name, value, days){
    const maxAge = days * 24 * 60 * 60;
    document.cookie = name + '=' + encodeURIComponent(value || '') + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }
  function deleteCookie(name){
    document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
  }
  function getSavedUser(){
    let data = {};
    try { data = JSON.parse(localStorage.getItem(storageKey) || localStorage.getItem(oldStorageKey) || '{}') || {}; } catch(e) { data = {}; }
    if (!data.name && !data.email) {
      data.name = readCookie('spidercms_chat_name');
      data.email = readCookie('spidercms_chat_email');
    }
    return {
      name: String(data.name || '').trim(),
      email: String(data.email || '').trim()
    };
  }
  function saveUser(name, email){
    const data = {
      name: String(name || '').trim(),
      email: String(email || '').trim()
    };
    if (!data.name && !data.email) return false;
    try { localStorage.setItem(storageKey, JSON.stringify(data)); localStorage.removeItem(oldStorageKey); } catch(e) {}
    writeCookie('spidercms_chat_name', data.name, 365);
    writeCookie('spidercms_chat_email', data.email, 365);
    return true;
  }
  function clearSavedUser(){
    try { localStorage.removeItem(storageKey); localStorage.removeItem(oldStorageKey); } catch(e) {}
    deleteCookie('spidercms_chat_name');
    deleteCookie('spidercms_chat_email');
  }
  function setUserFieldsVisible(visible){
    if (userFields) {
      userFields.style.display = visible ? '' : 'none';
      userFields.classList.toggle('is-hidden', !visible);
    }
    if (remembered) remembered.style.display = visible ? 'none' : 'flex';
    root.classList.toggle('has-remembered-user', !visible);
  }
  function applySavedUser(){
    const u = getSavedUser();
    const hasUser = !!(u.name || u.email);
    if (nameInput) nameInput.value = u.name;
    if (emailInput) emailInput.value = u.email;
    setUserFieldsVisible(!hasUser);
    if (rememberedText && hasUser) rememberedText.textContent = 'Piszesz jako ' + (u.name || u.email);
  }

  if (changeUserBtn) {
    changeUserBtn.addEventListener('click', function(){
      clearSavedUser();
      if (nameInput) nameInput.value = '';
      if (emailInput) emailInput.value = '';
      setUserFieldsVisible(true);
      if (nameInput) nameInput.focus();
    });
  }

  applySavedUser();

  function esc(s){
    return String(s || '').replace(/[&<>'"]/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
    });
  }
  function addMsg(from, body){
    const d = document.createElement('div');
    d.className = 'spidercms-chat-msg ' + (from === 'admin' ? 'admin' : 'user');
    d.innerHTML = esc(body);
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
  }
  function render(messages){
    box.innerHTML = '<div class="spidercms-chat-msg admin">' + esc(<?php echo json_encode($chat_welcome, JSON_UNESCAPED_UNICODE); ?>) + '</div>';
    (messages || []).forEach(m => addMsg(m.from, m.body));
  }

  const endpoint = <?php echo json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/') . '/admin.php'); ?>;

  function load(){
    fetch(endpoint + '?action=chat_public_get', {credentials:'same-origin'})
      .then(r => r.json())
      .then(d => { if (d.ok) render(d.messages); })
      .catch(() => {});
  }

  const toggle = document.getElementById('spidercms-chat-toggle');
  const close = document.getElementById('spidercms-chat-close');
  if (toggle) toggle.addEventListener('click', function(){ root.classList.add('open'); applySavedUser(); load(); });
  if (close) close.addEventListener('click', function(){ root.classList.remove('open'); });

  form.addEventListener('submit', function(e){
    e.preventDefault();

    const typedName = nameInput ? nameInput.value : '';
    const typedEmail = emailInput ? emailInput.value : '';
    const existingUser = getSavedUser();
    const finalName = String(typedName || existingUser.name || '').trim();
    const finalEmail = String(typedEmail || existingUser.email || '').trim();

    if (finalName || finalEmail) {
      saveUser(finalName, finalEmail);
      applySavedUser();
    }

    const fd = new FormData(form);
    fd.set('name', finalName);
    fd.set('email', finalEmail);
    fd.set('action', 'chat_public_send');

    status.textContent = 'Wysyłanie...';

    fetch(endpoint, {method:'POST', body:fd, credentials:'same-origin'})
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          addMsg('user', fd.get('message'));
          if (messageInput) messageInput.value = '';
          status.textContent = 'Wysłano.';
          setTimeout(load, 400);
        } else {
          status.textContent = d.error || 'Nie udało się wysłać.';
        }
      })
      .catch(() => status.textContent = 'Błąd połączenia.');
  });

  setInterval(function(){ if (root.classList.contains('open')) load(); }, 7000);
})();
</script>