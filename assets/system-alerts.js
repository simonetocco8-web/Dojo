(function () {
  'use strict';
  var root = document.getElementById('systemAlerts');
  if (!root) return;
  var badge = document.getElementById('systemAlertsCount');
  var button = document.getElementById('systemAlertsButton');
  var list = document.getElementById('systemAlertsList');
  var status = document.getElementById('systemAlertsStatus');
  var busy = false;
  async function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      var response = await fetch(root.dataset.alertsUrl, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) throw new Error('Alert non disponibili');
      var data = await response.json();
      if (!Array.isArray(data.alerts)) throw new Error('Risposta non valida');
      list.replaceChildren();
      data.alerts.forEach(function (alert) {
        var link = document.createElement('a');
        link.className = 'dropdown-item border-bottom py-3 text-wrap';
        if (typeof alert.url !== 'string' || !alert.url.startsWith('/') || alert.url.startsWith('//')) return;
        link.href = root.dataset.baseUrl + alert.url;
        var title = document.createElement('div');
        title.className = 'fw-semibold';
        title.textContent = alert.title;
        var summary = document.createElement('div');
        summary.className = 'small text-muted mt-1';
        summary.textContent = alert.summary;
        link.append(title, summary);
        list.append(link);
      });
      var count = data.alerts.length;
      badge.textContent = String(count);
      badge.classList.toggle('d-none', count === 0);
      button.setAttribute('aria-label', count ? 'Alert di sistema: ' + count : 'Alert di sistema: nessun alert');
      if (!count) {
        var empty = document.createElement('p');
        empty.className = 'text-muted small m-0 px-3 py-3';
        empty.textContent = data.unavailable && data.unavailable.length ? 'Impossibile verificare tutti gli alert.' : 'Nessun alert presente.';
        list.append(empty);
      }
      status.textContent = data.unavailable && data.unavailable.length ? 'Non disponibili: ' + data.unavailable.join(', ') : 'Aggiornato ora';
    } catch (error) {
      status.textContent = 'Aggiornamento non disponibile. Riprova aprendo la campanella.';
    } finally { busy = false; }
  }
  refresh();
  root.addEventListener('show.bs.dropdown', refresh);
  document.addEventListener('visibilitychange', refresh);
  window.setInterval(refresh, 60000);
}());
