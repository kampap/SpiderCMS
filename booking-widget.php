<?php
$spidercms_booking_settings = array (
  'enabled' => '1',
  'title' => 'Zarezerwuj termin',
  'subtitle' => 'Wybierz dogodny dzień i godzinę. Potwierdzenie wyślemy e-mailem.',
  'service_name' => 'Konsultacja',
  'slot_minutes' => '60',
  'days_ahead' => '30',
  'min_notice_hours' => '4',
  'work_days' => 
  array (
    0 => '1',
    1 => '2',
    2 => '3',
    3 => '4',
    4 => '5',
  ),
  'work_start' => '09:00',
  'work_end' => '17:00',
  'admin_email' => 'kamilpaprota@gmail.com',
  'notify_admin' => '1',
  'notify_client' => '1',
  'require_phone' => '0',
  'confirmation_mode' => 'manual',
  'email_mode' => NULL,
  'from_email' => '',
  'from_name' => 'SpiderCMS',
  'smtp_host' => '',
  'smtp_port' => '587',
  'smtp_secure' => NULL,
  'smtp_user' => '',
  'smtp_pass' => '',
);
if (($spidercms_booking_settings['enabled'] ?? '1') !== '1') return;
$spidercms_booking_endpoint = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/admin.php';
?>
<div class="spidercms-booking-widget" data-endpoint="<?php echo htmlspecialchars($spidercms_booking_endpoint, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="spidercms-booking-head">
    <h2><?php echo htmlspecialchars($spidercms_booking_settings['title'] ?? 'Zarezerwuj termin', ENT_QUOTES, 'UTF-8'); ?></h2>
    <p><?php echo htmlspecialchars($spidercms_booking_settings['subtitle'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
  </div>

  <div class="spidercms-booking-layout">
    <div class="spidercms-booking-calendar">
      <div class="spidercms-booking-monthbar">
        <button type="button" data-cal-prev>‹</button>
        <strong data-cal-title></strong>
        <button type="button" data-cal-next>›</button>
      </div>
      <div class="spidercms-booking-weekdays">
        <span>Pn</span><span>Wt</span><span>Śr</span><span>Cz</span><span>Pt</span><span>Sb</span><span>Nd</span>
      </div>
      <div class="spidercms-booking-days" data-cal-days></div>
    </div>

    <form class="spidercms-booking-form">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">
      <input type="hidden" name="date" required>

      <label>Wybrana data
        <input type="text" data-selected-date readonly placeholder="Wybierz dzień w kalendarzu">
      </label>

      <label>Godzina
        <select name="time" required>
          <option value="">Najpierw wybierz datę</option>
        </select>
      </label>

      <label>Imię i nazwisko
        <input type="text" name="name" required maxlength="120">
      </label>

      <label>E-mail
        <input type="email" name="email" required maxlength="160">
      </label>

      <label>Telefon
        <input type="tel" name="phone" maxlength="80" <?php echo (($spidercms_booking_settings['require_phone'] ?? '0') === '1') ? 'required' : ''; ?>>
      </label>

      <label>Wiadomość
        <textarea name="message" rows="3" maxlength="1200"></textarea>
      </label>

      <button type="submit">Zarezerwuj termin</button>
      <p class="spidercms-booking-status"></p>
    </form>
  </div>
</div>

<style>
.spidercms-booking-widget{max-width:1040px;margin:2rem auto;padding:1.5rem;border-radius:24px;background:#fff;box-shadow:0 18px 50px rgba(15,23,42,.12);border:1px solid #e5e7eb;color:#111827;font-family:system-ui,sans-serif}
.spidercms-booking-head h2{margin:0 0 .35rem;color:var(--primary,#a855f7);font-size:clamp(1.6rem,3vw,2.4rem)}
.spidercms-booking-head p{margin:0 0 1.2rem;color:#64748b}
.spidercms-booking-layout{display:grid;grid-template-columns:1.15fr .85fr;gap:1.2rem;align-items:start}
.spidercms-booking-calendar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:1rem}
.spidercms-booking-monthbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem}
.spidercms-booking-monthbar button{width:38px;height:38px;border:0;border-radius:12px;background:#111827;color:white;font-size:1.6rem;line-height:1;cursor:pointer}
.spidercms-booking-monthbar strong{font-size:1.05rem}
.spidercms-booking-weekdays,.spidercms-booking-days{display:grid;grid-template-columns:repeat(7,1fr);gap:.45rem}
.spidercms-booking-weekdays span{text-align:center;color:#64748b;font-weight:800;font-size:.85rem;padding:.35rem 0}
.spidercms-booking-day{aspect-ratio:1/1;border:1px solid #e2e8f0;border-radius:14px;background:white;color:#111827;font-weight:850;cursor:pointer;display:flex;align-items:center;justify-content:center;position:relative}
.spidercms-booking-day:hover{border-color:var(--primary,#a855f7);box-shadow:0 8px 20px rgba(168,85,247,.12)}
.spidercms-booking-day.is-muted{opacity:.25;pointer-events:none}
.spidercms-booking-day.is-disabled{opacity:.35;cursor:not-allowed;background:#e5e7eb;pointer-events:none}
.spidercms-booking-day.is-selected{background:var(--primary,#a855f7);color:#fff;border-color:var(--primary,#a855f7)}
.spidercms-booking-day.has-slots::after{content:"";position:absolute;bottom:7px;width:6px;height:6px;border-radius:999px;background:#22c55e}
.spidercms-booking-form{display:grid;gap:.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:1rem}
.spidercms-booking-form label{display:grid;gap:.35rem;font-weight:750;color:#334155}
.spidercms-booking-form input,.spidercms-booking-form select,.spidercms-booking-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:.8rem;font:inherit;background:white;color:#111827}
.spidercms-booking-form button{border:0;border-radius:999px;background:var(--button-bg,#a855f7);color:var(--button-text,#fff);font-weight:900;padding:.9rem 1.2rem;cursor:pointer}
.spidercms-booking-status{min-height:1.4rem;color:#475569;font-weight:700}
@media(max-width:820px){.spidercms-booking-layout{grid-template-columns:1fr}.spidercms-booking-widget{margin:1rem;padding:1rem;border-radius:18px}.spidercms-booking-day{border-radius:10px;font-size:.9rem}}
</style>

<script>
(function(){
  document.querySelectorAll('.spidercms-booking-widget').forEach(function(widget){
    if(widget.dataset.ready === '1') return;
    widget.dataset.ready = '1';

    const endpoint = widget.dataset.endpoint || '/admin.php';
    const form = widget.querySelector('.spidercms-booking-form');
    const hiddenDate = form.querySelector('[name="date"]');
    const selectedDateView = form.querySelector('[data-selected-date]');
    const time = form.querySelector('[name="time"]');
    const status = widget.querySelector('.spidercms-booking-status');
    const daysBox = widget.querySelector('[data-cal-days]');
    const title = widget.querySelector('[data-cal-title]');
    const prev = widget.querySelector('[data-cal-prev]');
    const next = widget.querySelector('[data-cal-next]');
    const maxDays = parseInt(<?php echo json_encode((int)($spidercms_booking_settings['days_ahead'] ?? 30)); ?>,10) || 30;

    let current = new Date();
    current.setDate(1);
    const today = new Date();
    today.setHours(0,0,0,0);
    const max = new Date();
    max.setDate(max.getDate() + maxDays);
    max.setHours(0,0,0,0);

    function fmtDate(d){
      const y = d.getFullYear();
      const m = String(d.getMonth()+1).padStart(2,'0');
      const day = String(d.getDate()).padStart(2,'0');
      return y + '-' + m + '-' + day;
    }

    function setStatus(msg){ status.textContent = msg || ''; }

    function loadSlots(dateValue){
      time.innerHTML = '<option value="">Ładowanie...</option>';
      return fetch(endpoint + '?action=booking_public_slots&date=' + encodeURIComponent(dateValue), {credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
          time.innerHTML = '';
          if(!d.ok || !d.slots || !d.slots.length){
            time.innerHTML = '<option value="">Brak wolnych terminów</option>';
            return [];
          }
          time.innerHTML = '<option value="">Wybierz godzinę</option>';
          d.slots.forEach(function(s){
            const o = document.createElement('option');
            o.value = s; o.textContent = s; time.appendChild(o);
          });
          return d.slots;
        })
        .catch(() => {
          time.innerHTML = '<option value="">Błąd ładowania</option>';
          return [];
        });
    }

    function renderCalendar(){
      daysBox.innerHTML = '';
      const monthNames = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
      title.textContent = monthNames[current.getMonth()] + ' ' + current.getFullYear();

      const first = new Date(current.getFullYear(), current.getMonth(), 1);
      const startOffset = (first.getDay() + 6) % 7;
      const daysInMonth = new Date(current.getFullYear(), current.getMonth()+1, 0).getDate();

      for(let i=0;i<startOffset;i++){
        const blank = document.createElement('div');
        blank.className = 'spidercms-booking-day is-muted';
        daysBox.appendChild(blank);
      }

      for(let d=1; d<=daysInMonth; d++){
        const dateObj = new Date(current.getFullYear(), current.getMonth(), d);
        dateObj.setHours(0,0,0,0);
        const dateValue = fmtDate(dateObj);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'spidercms-booking-day';
        btn.textContent = d;
        btn.dataset.date = dateValue;

        if(dateObj < today || dateObj > max){
          btn.classList.add('is-disabled');
        }else{
          btn.classList.add('has-slots');
        }

        btn.addEventListener('click', function(){
          widget.querySelectorAll('.spidercms-booking-day.is-selected').forEach(el => el.classList.remove('is-selected'));
          btn.classList.add('is-selected');
          hiddenDate.value = dateValue;
          selectedDateView.value = dateValue;
          setStatus('Ładowanie dostępnych godzin...');
          loadSlots(dateValue).then(function(slots){
            setStatus(slots.length ? 'Wybierz godzinę i uzupełnij dane.' : 'Brak wolnych godzin w tym dniu.');
          });
        });

        daysBox.appendChild(btn);
      }
    }

    prev.addEventListener('click', function(){
      current.setMonth(current.getMonth()-1);
      renderCalendar();
    });

    next.addEventListener('click', function(){
      current.setMonth(current.getMonth()+1);
      renderCalendar();
    });

    form.addEventListener('submit', function(e){
      e.preventDefault();
      if(!hiddenDate.value){
        setStatus('Wybierz datę w kalendarzu.');
        return;
      }
      const fd = new FormData(form);
      fd.set('action','booking_public_create');
      setStatus('Wysyłanie rezerwacji...');
      fetch(endpoint, {method:'POST', body:fd, credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
          if(d.ok){
            form.reset();
            hiddenDate.value = '';
            selectedDateView.value = '';
            time.innerHTML = '<option value="">Najpierw wybierz datę</option>';
            widget.querySelectorAll('.spidercms-booking-day.is-selected').forEach(el => el.classList.remove('is-selected'));
            setStatus(d.message || 'Rezerwacja została zapisana.');
          }else{
            setStatus(d.error || 'Nie udało się zapisać rezerwacji.');
          }
        })
        .catch(() => setStatus('Błąd połączenia.'));
    });

    renderCalendar();
  });
})();
</script>