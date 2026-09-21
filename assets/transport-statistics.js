(() => {
  if (typeof window.Chart === 'undefined') return;

  document.querySelectorAll('[data-transport-chart]').forEach((canvas) => {
    const labels = JSON.parse(canvas.dataset.labels || '[]');
    const values = JSON.parse(canvas.dataset.values || '[]');
    new window.Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Transfer eseguiti', data: values, borderColor: canvas.dataset.color,
          backgroundColor: `${canvas.dataset.color}20`, fill: true, tension: 0.25,
          pointRadius: labels.length > 90 ? 0 : 2, pointHoverRadius: 5, borderWidth: 2
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { maxTicksLimit: 14, maxRotation: 0 } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { title: (items) => items[0]?.label || '' } } }
      }
    });
  });
})();
