/* TAL Messaging — Formulaire de contact du site public. */
(() => {
  'use strict';
  const form = document.getElementById('ct-form');
  if (!form) return;
  const { api } = window.TAL;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('ct-err');
    const btn = document.getElementById('ct-send');
    err.classList.add('hidden');
    btn.disabled = true;
    try {
      await api('POST', '/api/public/contact', Object.fromEntries(new FormData(form)));
      form.reset();
      document.getElementById('ct-ok').classList.remove('hidden');
    } catch (x) {
      err.textContent = x.message + (x.fields ? ' ' + Object.values(x.fields).flat().join(' ') : '');
      err.classList.remove('hidden');
    }
    btn.disabled = false;
  });
})();
