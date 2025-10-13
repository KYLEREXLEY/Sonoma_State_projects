$(document).ready(function () {
  console.log('app.js loaded');

  // Prefer DB.php canvas (#tempChart); fallback to gragh.html (#mycanvas)
  var canvas = document.getElementById('tempChart') || document.getElementById('mycanvas');
  if (!canvas) {
    console.warn('No canvas element found (#tempChart or #mycanvas).');
    return;
  }

  $.ajax({
    url: "/Chartjs/data.php?n=node_1",
    method: "GET",
    dataType: "json",
    success: function (data) {
      console.log('Chart data:', data);

      if (!Array.isArray(data) || data.length === 0) {
        const msg = document.createElement('div');
        msg.style.padding = '1rem';
        msg.style.color = '#666';
        msg.textContent = 'No data found for node_1.';
        canvas.parentNode.appendChild(msg);
        return;
      }

      const labels = data.map(r => r.time_received);
      const temps  = data.map(r => parseFloat(r.temperature));

      const ctx = canvas.getContext('2d');

      new Chart(ctx, {
        type: 'bar',      // change to 'line' or 'bar'
        data: {
          labels,
          datasets: [{
            label: 'Sensor Node node_1',
            data: temps,
            backgroundColor: 'rgba(0,255,0,1)',
            borderColor: 'rgba(0,255,0,1)',
            borderWidth: 1
          }]
        },
        options: {
          plugins: {
            title: { display: true, text: 'Sensor Node node_1 – Temperature Over Time' },
            legend: { display: true }
          },
          scales: {
            x: { title: { display: true, text: 'Time' } },
            y: { beginAtZero: true, title: { display: true, text: 'Temperature (°C)' } }
          }
        }
      });
    },
    error: function (xhr, status, err) {
      console.error('AJAX error:', status, err);
      console.error('Response:', xhr.responseText);
    }
  });
});
