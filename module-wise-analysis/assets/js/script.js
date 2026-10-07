document.addEventListener('DOMContentLoaded', function () {
    // Animate bar fills from 0 to their target width
    var bars = document.querySelectorAll('.mwa-bar-fill');
    bars.forEach(function (bar) {
        var target = bar.style.width;
        bar.style.width = '0%';
        requestAnimationFrame(function () {
            setTimeout(function () {
                bar.style.width = target;
            }, 50);
        });
    });

    // Render Chart.js
    if (typeof Chart === 'undefined') {
        return;
    }

    var canvases = document.querySelectorAll('.mwa-chart-canvas');
    canvases.forEach(function (canvas) {
        var labels, values, colors;
        try {
            labels = JSON.parse(canvas.getAttribute('data-labels'));
            values = JSON.parse(canvas.getAttribute('data-values'));
            colors = JSON.parse(canvas.getAttribute('data-colors'));
        } catch (e) {
            return;
        }

        if (!labels || labels.length === 0) {
            return;
        }

        canvas.parentElement.style.height = Math.max(180, labels.length * 34) + 'px';

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderRadius: 4,
                    barPercentage: 0.6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.parsed.x + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        min: 0,
                        max: 100,
                        ticks: { callback: function (v) { return v + '%'; } },
                        grid: { color: '#f0f1f3' }
                    },
                    y: {
                        grid: { display: false }
                    }
                }
            }
        });
    });
});