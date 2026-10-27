'use strict';
// Start: Ereignisse der Grundoberfläche und erste Anzeige

$('#modalClose').onclick = closeModal;
$('#modal').addEventListener('mousedown', (e) => { if (e.target.id === 'modal') closeModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#modal').hidden) closeModal(); });
$('#logoutBtn').onclick = () => logout();
$('#menuBtn').onclick = () => document.body.classList.toggle('nav-open');
$('#themeBtn').onclick = () => {
  const dunkel = document.documentElement.getAttribute('data-theme') !== 'dark';
  document.documentElement.setAttribute('data-theme', dunkel ? 'dark' : 'light');
  store.set('mv_theme', dunkel ? 'dunkel' : 'hell');
};
window.addEventListener('hashchange', () => { closeModal(); if (S.meta) rerender(); });
boot();
