// Hell oder dunkel je Gerät, vor dem ersten Zeichnen setzen
(function () {
  var t; try { t = localStorage.getItem('mv_theme'); } catch (e) { /* kein Speicher */ }
  var dunkel = t === 'dunkel' || (!t && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dunkel ? 'dark' : 'light');
})();
