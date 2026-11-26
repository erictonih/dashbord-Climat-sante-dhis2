'use strict';
// Socle commun : requêtes (délai, reprise, cache), notifications, rendu sûr du DOM, tâches par lots.
const App = (() => {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const bar = document.getElementById('barre');
  let active = 0;

  /** Crée un élément sans jamais interpréter de HTML venant des données. */
  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (value !== false && value != null) node.setAttribute(key, value === true ? '' : value);
    }
    node.append(...children.flat().filter((c) => c != null));
    return node;
  }

  function toast(message, type = 'info') {
    const node = el('div', { class: `toast ${type}`, role: type === 'error' ? 'alert' : 'status' }, message);
    document.getElementById('toasts').append(node);
    setTimeout(() => node.remove(), type === 'error' ? 8000 : 4000);
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function request(url, { method = 'GET', body, signal, timeout = 55000, fresh = false } = {}) {
    const attempts = method === 'GET' ? 3 : 1;
    active++; bar.classList.add('active');
    try {
      for (let attempt = 1; ; attempt++) {
        const limit = AbortSignal.timeout(timeout);
        try {
          const response = await fetch(url, {
            method,
            cache: fresh ? 'reload' : 'default',
            credentials: 'same-origin',
            signal: signal ? AbortSignal.any([signal, limit]) : limit,
            headers: { Accept: 'application/json', ...(method !== 'GET' && { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }) },
            body: body ? JSON.stringify(body) : undefined,
          });
          if (response.status === 401) { clearCache(); location.assign('/login'); throw new Error('Session expirée.'); }
          const data = await response.json().catch(() => null);
          if (response.ok && data?.success) return data;
          const error = new Error(data?.error?.message || `Le serveur a répondu une erreur (${response.status}).`);
          error.code = data?.error?.code;
          // On ne réessaie que les pannes passagères sans code applicatif.
          error.retry = !data && response.status >= 500;
          throw error;
        } catch (error) {
          if (signal?.aborted) throw error;
          if (error.name === 'TimeoutError') error.message = 'Le serveur met trop de temps à répondre.';
          const transient = error.retry || error instanceof TypeError;
          if (!transient || attempt >= attempts) {
            if (error instanceof TypeError) error.message = 'Connexion réseau interrompue.';
            throw error;
          }
          await sleep(600 * attempt);
        }
      }
    } finally {
      if (--active === 0) bar.classList.remove('active');
    }
  }

  const get = (url, params = {}, options = {}) => {
    const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null));
    return request(query.size ? `${url}?${query}` : url, options);
  };
  const post = (url, body) => request(url, { method: 'POST', body });

  function clearCache() {
    try { Object.keys(sessionStorage).filter((k) => k.startsWith('cs:')).forEach((k) => sessionStorage.removeItem(k)); } catch { /* stockage indisponible */ }
  }

  /** Affiche tout de suite la dernière réponse connue, puis la version à jour. */
  async function cached(url, params, render, options = {}) {
    const key = `cs:${url}:${JSON.stringify(params)}`;
    let shown = false;
    try {
      const stored = JSON.parse(sessionStorage.getItem(key));
      if (stored) { render(stored, true); shown = true; }
    } catch { /* cache illisible : ignoré */ }
    try {
      const data = await get(url, params, options);
      try { sessionStorage.setItem(key, JSON.stringify(data)); } catch { /* quota atteint */ }
      render(data, false);
    } catch (error) {
      if (options.signal?.aborted) return;
      if (shown) toast(`Données non actualisées : ${error.message}`, 'error');
      else throw error;
    }
  }

  async function busy(button, work) {
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    try { return await work(); }
    catch (error) { toast(error.message, 'error'); }
    finally { if (button) { button.disabled = false; button.removeAttribute('aria-busy'); } }
  }

  const debounce = (fn, ms = 300) => { let timer; return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); }; };

  const skeletonRows = (tbody, rows, columns) => tbody.replaceChildren(...Array.from({ length: rows }, () =>
    el('tr', {}, Array.from({ length: columns }, () => el('td', {}, el('span', { class: 'skeleton' }))))));

  const emptyRow = (columns, message) => el('tr', {}, el('td', { colspan: columns, class: 'muted' }, message));

  /**
   * Enchaîne des lots avec progression, arrêt et reprise après erreur passagère.
   * step(offset) renvoie { next, total, done, status?, lines: [{text, error}] }.
   */
  function job(root, step) {
    const panel = root.querySelector('[data-job]');
    const progress = panel.querySelector('progress');
    const status = panel.querySelector('[data-status]');
    const log = panel.querySelector('[data-log]');
    const stop = root.querySelector('[data-stop]');
    const start = root.querySelector('[data-start], [type="submit"]');
    let running = false;
    stop.addEventListener('click', () => { running = false; status.textContent = 'Arrêt après le lot en cours…'; });
    window.addEventListener('beforeunload', (e) => { if (running) e.preventDefault(); });

    return async () => {
      if (running) return;
      running = true; start.disabled = true; stop.hidden = false; panel.hidden = false;
      log.replaceChildren(); progress.value = 0; status.textContent = 'Démarrage…';
      let offset = 0, failures = 0, outcome = 'Arrêté.';
      while (running) {
        try {
          const r = await step(offset);
          failures = 0;
          log.append(...r.lines.map((l) => el('p', { class: l.error ? 'err' : '' }, l.text)));
          log.scrollTop = log.scrollHeight;
          if (r.total) { progress.max = r.total; progress.value = Math.min(r.next, r.total); }
          status.textContent = r.status || `${Math.min(r.next, r.total)} / ${r.total} communes traitées`;
          if (r.done) { outcome = `Terminé. ${status.textContent}.`; break; }
          offset = r.next;
        } catch (error) {
          const permanent = error.code && !['dhis2_unreachable', 'dhis2_error', 'db_unreachable'].includes(error.code);
          if (permanent || ++failures >= 3) { outcome = `Interrompu : ${error.message}`; toast(error.message, 'error'); break; }
          status.textContent = `${error.message} Nouvelle tentative…`;
          await sleep(2000 * failures);
        }
      }
      status.textContent = outcome;
      running = false; start.disabled = false; stop.hidden = true;
    };
  }

  // --- Interface commune ---
  const menu = document.getElementById('menu');
  const opener = document.getElementById('ouvrirMenu');
  const toggle = (open) => { menu.classList.toggle('open', open); opener.setAttribute('aria-expanded', String(open)); };
  opener.addEventListener('click', (e) => { e.stopPropagation(); toggle(!menu.classList.contains('open')); });
  document.addEventListener('click', (e) => { if (!menu.contains(e.target)) toggle(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggle(false); });
  document.getElementById('deconnexion').addEventListener('submit', clearCache);

  get('/api/status').then(({ base, dhis2 }) => {
    const messages = [];
    if (!base.configuree) messages.push('La base de données n’est pas encore configurée (variables DB_HOST, DB_NAME, DB_USER, DB_PASS).');
    else if (!base.accessible) messages.push(base.message || 'La base de données est inaccessible.');
    if (!dhis2.configure && document.body.dataset.page === 'dhis2') messages.push('La connexion DHIS2 n’est pas encore configurée (variables DHIS2_BASE_URL et DHIS2_TOKEN).');
    else if (!dhis2.jeu_de_donnees && document.body.dataset.page === 'dhis2') messages.push('Le jeu de données DHIS2 (DHIS2_DATASET_UID) n’est pas renseigné.');
    if (messages.length) { const banner = document.getElementById('alerte'); banner.textContent = messages.join(' '); banner.hidden = false; }
  }).catch(() => {});

  return { el, toast, get, post, cached, busy, debounce, skeletonRows, emptyRow, job };
})();
