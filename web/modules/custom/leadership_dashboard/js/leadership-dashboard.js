/**
 * @file
 * Leadership Dashboard JavaScript behaviors.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.leadershipDashboard = {
    attach: function (context, settings) {
      const chartData = drupalSettings.leadershipDashboard || {};

      // Initialize all charts
      this.initRiskDistributionChart(chartData.riskDistribution);
      this.initToolAccessChart(chartData.toolAccessDistribution);
      this.initSentimentChart(chartData.sentimentDistribution);
      this.initAnxietyGauge(chartData);
      this.initTrustGauge(chartData);
      this.initFatigueGauge(chartData);
      this.initROIChart(chartData.roiScenarios);
      this.initHeatMapChart(chartData.heatMapData);
      this.initROICalculator(chartData.roiScenarios);
    },

    /**
     * Initialize Risk Distribution Chart.
     */
    initRiskDistributionChart: function(distribution) {
      const canvas = document.getElementById('riskDistributionChart');
      if (!canvas || canvas.chartInstance) return;

      const ctx = canvas.getContext('2d');
      canvas.chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['High Risk', 'Medium Risk', 'Low Risk'],
          datasets: [{
            data: [
              distribution?.high || 0,
              distribution?.medium || 0,
              distribution?.low || 0
            ],
            backgroundColor: ['#e74c3c', '#f39c12', '#27ae60'],
            borderWidth: 2,
            borderColor: '#fff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              position: 'bottom'
            },
            title: {
              display: false
            }
          }
        }
      });
    },

    /**
     * Initialize Tool Access Chart.
     */
    initToolAccessChart: function(distribution) {
      const canvas = document.getElementById('toolAccessChart');
      if (!canvas || canvas.chartInstance) return;

      const ctx = canvas.getContext('2d');
      canvas.chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['Full Access', 'Limited Access', 'No Access'],
          datasets: [{
            label: 'Users',
            data: [
              distribution?.yes || 0,
              distribution?.limited || 0,
              distribution?.no || 0
            ],
            backgroundColor: ['#27ae60', '#f39c12', '#e74c3c']
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                stepSize: 1
              }
            }
          }
        }
      });
    },

    /**
     * Initialize Sentiment Distribution Chart.
     */
    initSentimentChart: function(distribution) {
      const canvas = document.getElementById('sentimentChart');
      if (!canvas || canvas.chartInstance) return;

      const sentimentLabels = {
        'energized': 'Energized',
        'cautious_optimistic': 'Cautiously Optimistic',
        'neutral': 'Neutral',
        'skeptical': 'Skeptical',
        'overwhelmed': 'Overwhelmed'
      };

      const labels = [];
      const data = [];
      const colors = [];

      for (const [key, count] of Object.entries(distribution || {})) {
        labels.push(sentimentLabels[key] || key);
        data.push(count);

        // Color based on sentiment
        if (key === 'energized') colors.push('#27ae60');
        else if (key === 'cautious_optimistic') colors.push('#3498db');
        else if (key === 'neutral') colors.push('#95a5a6');
        else if (key === 'skeptical') colors.push('#f39c12');
        else colors.push('#e74c3c');
      }

      const ctx = canvas.getContext('2d');
      canvas.chartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
          labels: labels,
          datasets: [{
            data: data,
            backgroundColor: colors,
            borderWidth: 2,
            borderColor: '#fff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                font: {
                  size: 10
                }
              }
            }
          }
        }
      });
    },

    /**
     * Initialize Anxiety Gauge.
     */
    initAnxietyGauge: function(data) {
      const canvas = document.getElementById('anxietyGauge');
      if (!canvas || canvas.chartInstance) return;

      const avgAnxiety = data.heatMapData?.[0]?.anxiety || 0;
      this.createGaugeChart(canvas, avgAnxiety, 'Anxiety Level', '#e74c3c');
    },

    /**
     * Initialize Trust Gauge.
     */
    initTrustGauge: function(data) {
      const canvas = document.getElementById('trustGauge');
      if (!canvas || canvas.chartInstance) return;

      // Calculate from culture data (would need to pass this separately)
      const trustScore = 3.5; // Placeholder
      this.createGaugeChart(canvas, trustScore, 'Trust Score', '#3498db');
    },

    /**
     * Initialize Fatigue Gauge.
     */
    initFatigueGauge: function(data) {
      const canvas = document.getElementById('fatigueGauge');
      if (!canvas || canvas.chartInstance) return;

      // Fatigue is 0-100 scale, convert to 0-5
      const fatigueScore = 0; // Placeholder, would calculate from data
      this.createGaugeChart(canvas, fatigueScore / 20, 'Fatigue', '#f39c12');
    },

    /**
     * Helper: Create a gauge chart.
     */
    createGaugeChart: function(canvas, value, label, color) {
      const ctx = canvas.getContext('2d');
      const percentage = (value / 5) * 100;

      canvas.chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
          datasets: [{
            data: [percentage, 100 - percentage],
            backgroundColor: [color, '#ecf0f1'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          circumference: 180,
          rotation: 270,
          cutout: '70%',
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              enabled: false
            }
          }
        }
      });
    },

    /**
     * Initialize ROI Chart.
     */
    initROIChart: function(scenarios) {
      const canvas = document.getElementById('roiChart');
      if (!canvas || canvas.chartInstance) return;

      const adoptionRates = Object.keys(scenarios || {});
      const annualValues = adoptionRates.map(rate => scenarios[rate]?.annual_value || 0);

      const ctx = canvas.getContext('2d');
      canvas.chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: adoptionRates.map(r => r + '%'),
          datasets: [{
            label: 'Annual Value ($)',
            data: annualValues,
            borderColor: '#d4a849',
            backgroundColor: 'rgba(212, 168, 73, 0.1)',
            fill: true,
            tension: 0.4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return '$' + value.toLocaleString();
                }
              }
            }
          }
        }
      });
    },

    /**
     * Initialize Heat Map Chart.
     */
    initHeatMapChart: function(heatMapData) {
      const canvas = document.getElementById('heatMapChart');
      if (!canvas || canvas.chartInstance || !heatMapData || heatMapData.length === 0) return;

      const departments = heatMapData.map(d => d.department);
      const riskScores = heatMapData.map(d => d.risk_score);
      const skillLevels = heatMapData.map(d => d.skill_level);
      const confidenceScores = heatMapData.map(d => d.confidence);

      const ctx = canvas.getContext('2d');
      canvas.chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: departments,
          datasets: [
            {
              label: 'Risk Score',
              data: riskScores,
              backgroundColor: '#e74c3c',
              stack: 'metrics'
            },
            {
              label: 'Skill Level',
              data: skillLevels.map(v => v * 20), // Scale to 0-100
              backgroundColor: '#3498db',
              stack: 'metrics'
            },
            {
              label: 'Confidence',
              data: confidenceScores.map(v => v * 20), // Scale to 0-100
              backgroundColor: '#27ae60',
              stack: 'metrics'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              position: 'bottom'
            }
          },
          scales: {
            x: {
              stacked: false
            },
            y: {
              beginAtZero: true,
              max: 100
            }
          }
        }
      });
    },

    /**
     * Initialize ROI Calculator Interactive Slider.
     */
    initROICalculator: function(scenarios) {
      const slider = document.getElementById('adoptionRate');
      const display = document.getElementById('adoptionRateDisplay');

      if (!slider || !display) return;

      slider.addEventListener('input', function() {
        const rate = parseInt(this.value);
        display.textContent = rate;

        // Find closest scenario
        let closestRate = 25;
        let minDiff = Math.abs(rate - 25);

        for (const scenarioRate of Object.keys(scenarios)) {
          const diff = Math.abs(rate - parseInt(scenarioRate));
          if (diff < minDiff) {
            minDiff = diff;
            closestRate = parseInt(scenarioRate);
          }
        }

        const scenario = scenarios[closestRate];

        if (scenario) {
          document.getElementById('weeklyHours').textContent = scenario.weekly_hours_saved;
          document.getElementById('annualHours').textContent = scenario.annual_hours_saved.toLocaleString();
          document.getElementById('annualValue').textContent = '$' + scenario.annual_value.toLocaleString();
          document.getElementById('perUserSavings').textContent = scenario.per_user_weekly_savings + 'h';
        }
      });
    }

  };

})(Drupal, drupalSettings);
