/* ============================================================================
   APSARA VIMANA ONE â€” js/apsara.js
   InteracciÃ³n del chat con api/engine.php.
   Presenta respuestas, imÃ¡genes, enlaces y opciones (progressive disclosure).
   ============================================================================ */
(function () {
  'use strict';

  /* ---------- Referencias del DOM (index.html) ---------- */
  const conversation = document.getElementById('conversation');
  const welcome      = document.getElementById('welcome');
  const composer     = document.getElementById('composer');
  const message      = document.getElementById('message');
  const sendBtn      = document.getElementById('send');
  const typing       = document.getElementById('typing');

  const API_URL = 'api/engine.php';
  const IMG_BASE = ''; // rutas relativas desde ia/
  const IMG_PENSANDO = 'imagenes/estados/Pensando.jpg';
  const IMG_BASE_BOT = 'imagenes/estados/2.jpg';

  let ocupada = false;

  /* ---------- Utilidades ---------- */
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function escUrl(u) { return String(u).replace(/["'<>\s]/g, encodeURIComponent); }

  function scrollAbajo() {
    conversation.scrollTop = conversation.scrollHeight;
  }

  function ocultarBienvenida() {
    /* No borrar la foto: solo compactar la bienvenida */
    if (welcome) welcome.classList.add('welcome-compact');
  }

  /* ---------- Burbujas ---------- */
  function burbujaUsuario(texto) {
    ocultarBienvenida();
    const row = el('div', 'msg user');
    const b = el('div', 'bubble', texto);
    row.appendChild(b);
    conversation.appendChild(row);
    scrollAbajo();
  }

  function burbujaBot(data) {
    ocultarBienvenida();
    const row = el('div', 'msg bot');

    // Avatar de estado / persona / Ã¡lbum (imagen devuelta por el motor)
    const imgSrc = data.image ? data.image.split('/').map(encodeURIComponent).join('/') : IMG_BASE_BOT;
    const av = el('img', 'avatar');
    av.src = IMG_BASE + imgSrc;
    av.alt = 'Apsara';
    av.width = 44; av.height = 44;
    row.appendChild(av);

    const columna = el('div', 'bot-column');

    const b = el('div', 'bubble bot-bubble');
    // Texto plano con saltos de lÃ­nea (textContent = inyecciÃ³n segura)
    b.textContent = data.reply || 'â€¦';
    columna.appendChild(b);

    // Enlaces clicables
    if (Array.isArray(data.links) && data.links.length) {
      const lista = el('div', 'links');
      data.links.forEach(function (l) {
        const a = el('a', 'link-card');
        a.href = l.u;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        const t = el('span', 'link-title', l.t || l.u);
        const u = el('span', 'link-url', (l.u || '').replace(/^https?:\/\//, ''));
        a.appendChild(t); a.appendChild(u);
        lista.appendChild(a);
      });
      columna.appendChild(lista);
    }

    // Opciones (progressive disclosure) â€” chips que envÃ­an texto
    if (Array.isArray(data.options) && data.options.length) {
      const chips = el('div', 'options');
      data.options.forEach(function (op) {
        const c = el('button', 'chip', op);
        c.type = 'button';
        c.addEventListener('click', function () { enviar(op); });
        chips.appendChild(c);
      });
      columna.appendChild(chips);
    }

    row.appendChild(columna);
    conversation.appendChild(row);
    scrollAbajo();
  }

  /* ---------- EnvÃ­o ---------- */
  function setEnviando(on) {
    ocupada = on;
    sendBtn.classList.toggle('sending', on);
    sendBtn.disabled = on;
    message.disabled = on;
    typing.hidden = !on;
    if (on) scrollAbajo();
  }

  async function enviar(texto) {
    const q = (texto !== undefined ? texto : message.value).trim();
    if (!q || ocupada) return;
    burbujaUsuario(q);
    message.value = '';
    autoGrow();
    setEnviando(true);

    try {
      const fd = new FormData();
      fd.append('q', q);
      const r = await fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'error');
      burbujaBot(data);
    } catch (e) {
      burbujaBot({
        reply: 'No pude completar la respuesta en este momento. IntÃ©ntalo de nuevo en unos segundos.',
        image: 'imagenes/estados/Reconectando.jpg',
        links: [], options: []
      });
    } finally {
      setEnviando(false);
      message.focus();
    }
  }

  /* ---------- Comportamiento del campo ---------- */
  function autoGrow() {
    message.style.height = 'auto';
    message.style.height = Math.min(message.scrollHeight, 140) + 'px';
  }

  composer.addEventListener('submit', function (ev) {
    ev.preventDefault();
    enviar();
  });

  message.addEventListener('input', autoGrow);
  message.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      enviar();
    }
  });

  /* ---------- Altura real con teclado mÃ³vil (sin doble scroll) ---------- */
  function ajustarAltura() {
    const vv = window.visualViewport;
    if (vv) {
      document.documentElement.style.setProperty('--app-h', vv.height + 'px');
    }
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', ajustarAltura);
    ajustarAltura();
  }

  autoGrow();

  /* Keep-awake: mientras la pestaña esté abierta, ping cada 10 min.
     Render free duerme ~15 min sin tráfico. Si nadie tiene el chat abierto,
     configura un cron externo (cron-job.org) a /api/ping.php cada 10–14 min. */
  (function keepAwake() {
    var PING = 'api/ping.php';
    var MS = 10 * 60 * 1000;
    function tick() {
      if (document.visibilityState === 'hidden') return;
      try {
        fetch(PING, { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
      } catch (e) {}
    }
    setInterval(tick, MS);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') tick();
    });
  })();
})();