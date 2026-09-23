(() => {
  const canvas = document.getElementById('tramontoDayAccessTimeline');
  if (!canvas || typeof window.Chart === 'undefined') return;

  const labels = JSON.parse(canvas.dataset.labels || '[]');
  const values = JSON.parse(canvas.dataset.values || '[]');
  new window.Chart(canvas, {
    type: 'line',
    data: { labels, datasets: [{ label: 'Accessi', data: values, borderColor: '#f59e0b', backgroundColor: '#f59e0b22', fill: true, tension: 0.25, borderWidth: 2, pointRadius: labels.length > 90 ? 0 : 2, pointHoverRadius: 5 }] },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Accessi' } }, x: { ticks: { maxTicksLimit: 14, maxRotation: 0 } } },
      plugins: { legend: { display: false }, tooltip: { callbacks: { title: (items) => items[0]?.label || '' } } }
    }
  });
})();
