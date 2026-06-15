(function () {
  'use strict';

  function parseJsonAttribute(element, attributeName, fallback) {
    if (!element) return fallback;
    var rawValue = element.getAttribute(attributeName);
    if (!rawValue) return fallback;

    try {
      return JSON.parse(rawValue);
    } catch (error) {
      console.error('Impossibile leggere i dati del grafico:', attributeName, error);
      return fallback;
    }
  }

  function formatQuantity(value) {
    return new Intl.NumberFormat('it-IT', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(value || 0);
  }

  function renderPieChart() {
    var pieCanvas = document.getElementById('categoryPieChart');
    if (!pieCanvas || typeof Chart === 'undefined') return;

    new Chart(pieCanvas, {
      type: 'pie',
      data: {
        labels: parseJsonAttribute(pieCanvas, 'data-chart-labels', []),
        datasets: [{
          data: parseJsonAttribute(pieCanvas, 'data-chart-values', []),
          backgroundColor: parseJsonAttribute(pieCanvas, 'data-chart-colors', []),
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function (context) {
                return context.label + ': ' + formatQuantity(context.parsed);
              }
            }
          }
        }
      }
    });
  }

  function renderLineChart() {
    var lineCanvas = document.getElementById('categoryTimelineChart');
    if (!lineCanvas || typeof Chart === 'undefined') return;

    new Chart(lineCanvas, {
      type: 'line',
      data: {
        labels: parseJsonAttribute(lineCanvas, 'data-chart-labels', []),
        datasets: parseJsonAttribute(lineCanvas, 'data-chart-datasets', [])
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return formatQuantity(value);
              }
            }
          }
        },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function (context) {
                return context.dataset.label + ': ' + formatQuantity(context.parsed.y);
              }
            }
          }
        }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderPieChart();
    renderLineChart();
  });
}());
