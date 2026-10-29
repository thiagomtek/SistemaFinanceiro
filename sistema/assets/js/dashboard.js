// Graficos do dashboard (Chart.js). Dados vem de window.DASH, montado no servidor.
(function () {
  var D = window.DASH;
  if (!D || !window.Chart) { return; }
  Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif';
  Chart.defaults.color = '#475569';
  var moeda = function (ctx) { return ' ' + brl(ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed); };
  var eixoY = { ticks: { callback: function (v) { return brl(v).replace(',00', ''); } }, grid: { color: '#f1f5f9' } };

  new Chart(document.getElementById('ch-rd'), {
    type: 'bar',
    data: {
      labels: D.rd.labels,
      datasets: [
        { label: 'Receitas', data: D.rd.rec, backgroundColor: '#16a34a', borderRadius: 4 },
        { label: 'Despesas', data: D.rd.desp, backgroundColor: '#dc2626', borderRadius: 4 }
      ]
    },
    options: { maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } } }, scales: { y: eixoY, x: { grid: { display: false } } } }
  });

  var cat = document.getElementById('ch-cat');
  if (cat) {
    new Chart(cat, {
      type: 'doughnut',
      data: { labels: D.cat.labels, datasets: [{ data: D.cat.valores, backgroundColor: D.cat.cores, borderWidth: 2 }] },
      options: { maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + brl(c.parsed); } } } } }
    });
  }

  new Chart(document.getElementById('ch-proj'), {
    type: 'line',
    data: { labels: D.proj.labels, datasets: [
      { label: 'Patrimônio (contas + investimentos)', data: D.proj.pat, borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.10)', fill: true, tension: .25, pointRadius: 3 },
      { label: 'Patrimônio líquido de IR/IOF', data: D.proj.patliq, borderColor: '#16a34a', borderDash: [4, 3], fill: false, tension: .25, pointRadius: 2 },
      { label: 'Saldo em contas', data: D.proj.saldo, borderColor: '#2563eb', fill: false, tension: .25, pointRadius: 2 }
    ] },
    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + moeda(c); } } } }, scales: { y: eixoY, x: { grid: { display: false } } } }
  });
})();
