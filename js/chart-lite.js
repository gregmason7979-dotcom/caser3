// Lightweight pie-only Chart helper for offline usage.
// Provides a minimal subset of the Chart.js API used by this project:
// new Chart(canvasElement, { type: 'pie', data: { labels, datasets:[{data, backgroundColor, borderColor}] }, options:{plugins:{legend:{position}, tooltip:{enabled}}}})
(function(global){
  'use strict';

  function now(){
    return (global.performance && global.performance.now) ? global.performance.now() : Date.now();
  }

  const requestFrame =
    global.requestAnimationFrame ||
    function(cb){ return setTimeout(function(){ cb(now()); }, 16); };

  const cancelFrame =
    global.cancelAnimationFrame ||
    function(id){ clearTimeout(id); };

  function ensureArray(value){
    return Array.isArray(value) ? value : [];
  }

  function Chart(canvas, config){
    if (!(this instanceof Chart)) return new Chart(canvas, config);
    this.canvas = canvas;
    this.ctx = canvas && canvas.getContext ? canvas.getContext('2d') : null;
    this.config = config || {};
    this.legendEl = null;
    this._animFrame = null;
    this._fallbackTimer = null;
    this._lastFrameTs = 0;
    this._startTime = 0;
    this._duration = 0;
    this._progress = 0;
    if (this.canvas) {
      this.canvas._chartLite = this;
    }
    this.render();
  }

  Chart.prototype._normalizeData = function(){
    const cfg = this.config || {};
    const dataset = (cfg.data && cfg.data.datasets && cfg.data.datasets[0]) || {};
    const labels = ensureArray(cfg.data && cfg.data.labels);
    const data = ensureArray(dataset.data).map(function(n){ return Number(n) || 0; });
    const colors = ensureArray(dataset.backgroundColor);
    const borders = ensureArray(dataset.borderColor);
    return { labels, data, colors, borders };
  };

  Chart.prototype._drawLegend = function(info){
    const cfg = this.config || {};
    const legendCfg = cfg.options && cfg.options.plugins && cfg.options.plugins.legend;
    if (legendCfg && legendCfg.display === false) return;
    if (!this.canvas || !this.canvas.parentElement) return;

    const container = document.createElement('div');
    container.className = 'chart-lite-legend';
    container.style.display = 'flex';
    container.style.flexWrap = 'wrap';
    container.style.justifyContent = 'center';
    container.style.gap = '8px';
    container.style.marginTop = '8px';
    container.style.fontSize = '12px';
    container.style.fontFamily = 'Arial, sans-serif';

    info.labels.forEach(function(label, idx){
      const item = document.createElement('div');
      item.style.display = 'inline-flex';
      item.style.alignItems = 'center';
      item.style.gap = '4px';

      const swatch = document.createElement('span');
      swatch.style.display = 'inline-block';
      swatch.style.width = '12px';
      swatch.style.height = '12px';
      swatch.style.borderRadius = '3px';
      swatch.style.background = info.colors[idx % info.colors.length] || '#4e79a7';
      swatch.style.border = '1px solid ' + (info.borders[idx % info.borders.length] || '#ccc');

      const text = document.createElement('span');
      text.textContent = label + ' (' + info.data[idx] + ')';

      item.appendChild(swatch);
      item.appendChild(text);
      container.appendChild(item);
    });

    // Remove previous legend if present
    if (this.legendEl && this.legendEl.parentElement) {
      this.legendEl.parentElement.removeChild(this.legendEl);
    }
    this.legendEl = container;
    this.canvas.parentElement.appendChild(container);
  };

  Chart.prototype._getAnimationDuration = function(){
    const cfg = this.config || {};
    const duration = cfg.options && cfg.options.animation && cfg.options.animation.duration;
    const val = Number(duration);
    return Number.isFinite(val) && val >= 0 ? val : 700;
  };

  Chart.prototype._drawSlices = function(info, progress){
    if (!this.ctx) return;
    const ctx = this.ctx;
    const canvas = this.canvas;

    const width = canvas.clientWidth || canvas.width || 320;
    const height = canvas.clientHeight || canvas.height || 320;
    canvas.width = width;
    canvas.height = height;

    const total = info.data.reduce(function(sum, val){ return sum + (Number.isFinite(val) ? val : 0); }, 0);
    const count = info.data.length || 1;
    const fallback = total <= 0;
    const radius = Math.max(30, Math.min(width, height) / 2 - 12);
    const centerX = width / 2;
    const centerY = height / 2;

    ctx.clearRect(0, 0, width, height);
    ctx.save();

    let start = -Math.PI / 2;
    let remaining = Math.PI * 2 * progress;
    for (let i = 0; i < count && remaining > 0; i++) {
      const value = fallback ? 1 : info.data[i];
      const slice = (value / (fallback ? count : total)) * Math.PI * 2;
      const drawArc = Math.min(slice, remaining);
      const color = info.colors[i % info.colors.length] || '#4e79a7';
      const border = info.borders[i % info.borders.length] || '#ccc';

      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, start, start + drawArc);
      ctx.closePath();
      ctx.fillStyle = color;
      ctx.strokeStyle = border;
      ctx.lineWidth = 1;
      ctx.fill();
      ctx.stroke();

      start += slice;
      remaining -= drawArc;
    }

    ctx.restore();
  };

  Chart.prototype.render = function(){
    if (!this.ctx) return;
    const info = this._normalizeData();
    const duration = this._getAnimationDuration();

    if (this._animFrame) {
      cancelFrame(this._animFrame);
      this._animFrame = null;
    }
    if (this._fallbackTimer) {
      clearInterval(this._fallbackTimer);
      this._fallbackTimer = null;
    }

    const startTime = now();
    this._startTime = startTime;
    this._duration = duration;

    const tick = (timestamp) => {
      const elapsed = timestamp - startTime;
      const progress = duration === 0 ? 1 : Math.min(1, elapsed / duration);
      this._progress = progress;
      this._drawSlices(info, progress);
      this._lastFrameTs = timestamp;
      if (progress < 1) {
        this._animFrame = requestFrame(tick);
      } else {
        this._animFrame = null;
        if (this._fallbackTimer) {
          clearInterval(this._fallbackTimer);
          this._fallbackTimer = null;
        }
        this._drawLegend(info);
      }
    };

    this._animFrame = requestFrame(tick);

    // Ensure animation still advances even if rAF is throttled/disabled (e.g., sandboxed tabs)
    this._fallbackTimer = setInterval(() => {
      const sinceLast = this._lastFrameTs ? now() - this._lastFrameTs : Infinity;
      if (!this._animFrame || sinceLast > 120) {
        tick(now());
      }
    }, 80);
  };

  Chart.prototype.update = function(newConfig){
    if (newConfig) {
      this.config = newConfig;
    }
    this.render();
  };

  Chart.prototype.destroy = function(){
    if (this.legendEl && this.legendEl.parentElement) {
      this.legendEl.parentElement.removeChild(this.legendEl);
    }
    if (this.ctx) {
      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }
    if (this._animFrame) {
      cancelFrame(this._animFrame);
    }
    if (this._fallbackTimer) {
      clearInterval(this._fallbackTimer);
    }
  };

  global.Chart = Chart;
})(window);
