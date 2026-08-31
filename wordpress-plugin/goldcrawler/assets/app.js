/* ============================================================
   کراولر قیمت — منطق رابط کاربری
   ============================================================ */
'use strict';

const GC_CONFIG = window.GoldCrawlerConfig || {};
const NONCE = GC_CONFIG.nonce || '';
const AJAX_URL = GC_CONFIG.ajaxUrl || '/wp-admin/admin-ajax.php';
// The container this shortcode rendered into - theme toggling must stay
// scoped to it, never to <html>, since the rest of the WordPress page
// (theme header/footer) must not be repainted by our dark mode.
const ROOT = document.getElementById('goldcrawler-app');

const PALETTE = [
  '#2f6fd0', '#c9922e', '#0f8a52', '#c0446e',
  '#7b53c1', '#0f9aa8', '#d2603a', '#5b7186',
];

const state = {
  meta: null,
  symbols: [],
  selected: new Set(),
  group: 'all',
  query: '',
  presets: [],
  preset: '30',
  result: null,
  range: null,
  activeTab: 0,
  chartMode: 'percent',
  hidden: new Set(),
  busy: false,
};

const $ = (id) => document.getElementById(id);
const el = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
};

/* ── قالب‌بندی اعداد و تاریخ ───────────────────────────── */
const faNumber = (value, decimals = 0) =>
  value === null || value === undefined || Number.isNaN(value)
    ? '—'
    : new Intl.NumberFormat('fa-IR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: decimals,
      }).format(value);

const faDigits = (text) => String(text).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]);
const faDate = (iso) => faDigits(iso || '');
const faPercent = (value) =>
  value === null || value === undefined ? '—' : `${faNumber(Math.abs(value), 2)}٪`;

/* ── ارتباط با سرور ────────────────────────────────────── */
async function api(name, { method = 'GET', body, raw = false } = {}) {
  const url = `${AJAX_URL}?action=goldcrawler_${name}`;
  const response = await fetch(url, {
    method: body ? 'POST' : method,
    headers: {
      'X-WP-Nonce': NONCE,
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  if (raw) {
    if (!response.ok) {
      const failure = await response.json().catch(() => ({}));
      throw new Error(failure.data?.message || `خطای ${faDigits(response.status)} از سرور`);
    }
    return response;
  }

  const envelope = await response.json().catch(() => null);
  if (!envelope) throw new Error('پاسخ سرور قابل خواندن نبود.');
  if (envelope.success === false) {
    throw new Error(envelope.data?.message || `خطای ${faDigits(response.status)} از سرور`);
  }
  // wp_send_json_success() wraps the real payload one level deeper.
  const payload = envelope.data !== undefined ? envelope.data : envelope;
  if (!response.ok && !payload.error) throw new Error(`خطای ${faDigits(response.status)} از سرور`);
  return payload;
}

/* ── اعلان‌ها ──────────────────────────────────────────── */
function toast(message, kind = 'info', ttl = 5200) {
  const node = el('div', `toast toast--${kind}`, message);
  $('toasts').appendChild(node);
  setTimeout(() => {
    node.style.opacity = '0';
    setTimeout(() => node.remove(), 250);
  }, ttl);
}

function busy(on, text = 'در حال دریافت داده‌ها…') {
  state.busy = on;
  $('overlayText').textContent = text;
  $('overlay').hidden = !on;
  ['fetchBtn', 'refreshBtn', 'crawlBtn'].forEach((id) => {
    const button = $(id);
    if (button) button.disabled = on;
  });
}

/* ── فهرست نمادها ──────────────────────────────────────── */
function renderGroupFilters() {
  const groups = ['all', ...new Set(state.symbols.map((s) => s.group))];
  const labels = { all: 'همه' };
  const box = $('groupFilters');
  box.replaceChildren();

  groups.forEach((group) => {
    const chip = el('button', 'chip', labels[group] || group);
    chip.type = 'button';
    chip.setAttribute('role', 'tab');
    chip.setAttribute('aria-selected', String(state.group === group));
    chip.onclick = () => {
      state.group = group;
      renderGroupFilters();
      renderSymbolList();
    };
    box.appendChild(chip);
  });
}

function renderSymbolList() {
  const list = $('symbolList');
  const query = state.query.trim().toLowerCase();
  const matches = state.symbols.filter((symbol) => {
    if (state.group !== 'all' && symbol.group !== state.group) return false;
    if (!query) return true;
    return (
      symbol.name.toLowerCase().includes(query) ||
      symbol.key.toLowerCase().includes(query) ||
      symbol.group.toLowerCase().includes(query)
    );
  });

  list.replaceChildren();
  if (!matches.length) {
    list.appendChild(el('div', 'symbol-list__empty', 'نمادی با این نام پیدا نشد.'));
    return;
  }

  let lastGroup = null;
  matches.forEach((symbol) => {
    if (symbol.group !== lastGroup) {
      lastGroup = symbol.group;
      list.appendChild(el('div', 'symbol-group', symbol.group));
    }

    const row = el('label', 'symbol' + (state.selected.has(symbol.key) ? ' is-on' : ''));
    const checkbox = el('input');
    checkbox.type = 'checkbox';
    checkbox.checked = state.selected.has(symbol.key);
    checkbox.onchange = () => {
      if (checkbox.checked) state.selected.add(symbol.key);
      else state.selected.delete(symbol.key);
      row.classList.toggle('is-on', checkbox.checked);
      updateSymbolCount();
      persistSettings();
    };

    const name = el('span', 'symbol__name');
    name.appendChild(document.createTextNode(symbol.name + ' '));
    name.appendChild(el('span', 'symbol__key', symbol.key));

    row.append(checkbox, name, el('span', 'symbol__unit', symbol.unit));
    list.appendChild(row);
  });
}

function updateSymbolCount() {
  $('symbolCount').textContent = faDigits(state.selected.size);
}

/* ── بازه زمانی ────────────────────────────────────────── */
function renderPresets() {
  const box = $('rangePresets');
  box.replaceChildren();

  state.presets.forEach((preset) => {
    const chip = el('button', 'chip', preset.label);
    chip.type = 'button';
    chip.classList.toggle('is-active', state.preset === preset.id);
    chip.onclick = () => {
      state.preset = preset.id;
      $('startDate').value = faDigits(preset.start);
      $('endDate').value = faDigits(preset.end);
      renderPresets();
      persistSettings();
    };
    box.appendChild(chip);
  });
}

const toLatinDigits = (text) =>
  String(text)
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

function readDates() {
  const start = toLatinDigits($('startDate').value).trim().replace(/[-.]/g, '/');
  const end = toLatinDigits($('endDate').value).trim().replace(/[-.]/g, '/');
  const pattern = /^1[2-5]\d{2}\/\d{1,2}\/\d{1,2}$/;
  const error = $('dateError');

  if (!pattern.test(start) || !pattern.test(end)) {
    error.textContent = 'تاریخ‌ها را با قالب ۱۴۰۴/۰۱/۰۱ وارد کنید.';
    error.hidden = false;
    return null;
  }
  error.hidden = true;
  return { start, end };
}

/* ── کارت‌های آمار ─────────────────────────────────────── */
function renderStats() {
  const grid = $('statGrid');
  grid.replaceChildren();

  state.result.series.forEach((series, index) => {
    const { stats, symbol } = series;
    const card = el('div', 'stat');
    card.style.setProperty('--dot', PALETTE[index % PALETTE.length]);

    const head = el('div', 'stat__head');
    head.append(el('span', 'stat__name', symbol.name), el('span', 'stat__unit', stats.unit));

    const direction = stats.change > 0 ? 'up' : stats.change < 0 ? 'down' : 'flat';
    const arrow = direction === 'up' ? '▲' : direction === 'down' ? '▼' : '■';
    const delta = el(
      'div',
      `stat__delta ${direction}`,
      `${arrow} ${faNumber(Math.abs(stats.change ?? 0), symbol.decimals)} (${faPercent(stats.change_pct)})`,
    );

    const meta = el('div', 'stat__meta');
    [
      ['کمترین', faNumber(stats.min, symbol.decimals)],
      ['بیشترین', faNumber(stats.max, symbol.decimals)],
      ['میانگین', faNumber(stats.mean, symbol.decimals)],
      ['روز معاملاتی', faDigits(stats.trading_days)],
    ].forEach(([label, value]) => {
      const cell = el('div');
      cell.append(el('b', 'num', value), document.createTextNode(label));
      meta.appendChild(cell);
    });

    card.append(head, el('div', 'stat__value num', faNumber(stats.last, symbol.decimals)), delta, meta);
    grid.appendChild(card);
  });
}

/* ── نمودار ────────────────────────────────────────────── */
function chartData() {
  const dates = [...new Set(state.result.series.flatMap((s) => s.rows.map((r) => r.date)))].sort();
  const lines = state.result.series.map((series, index) => {
    const byDate = new Map(series.rows.map((row) => [row.date, row.close]));
    return {
      name: series.symbol.name,
      decimals: series.symbol.decimals,
      unit: series.stats.unit,
      color: PALETTE[index % PALETTE.length],
      index,
      values: dates.map((date) => {
        const value = byDate.get(date);
        return value === undefined || value === null ? null : value;
      }),
    };
  });
  return { dates, lines };
}

function renderChartModes() {
  const box = $('chartModes');
  box.replaceChildren();
  [
    ['percent', 'درصد تغییر'],
    ['absolute', 'قیمت مطلق'],
  ].forEach(([id, label]) => {
    const chip = el('button', 'chip', label);
    chip.type = 'button';
    chip.classList.toggle('is-active', state.chartMode === id);
    chip.onclick = () => {
      state.chartMode = id;
      renderChartModes();
      renderChart();
    };
    box.appendChild(chip);
  });
}

function renderChart() {
  const svg = $('chart');
  const { dates, lines } = chartData();
  const visible = lines.filter((line) => !state.hidden.has(line.index));
  const NS = 'http://www.w3.org/2000/svg';
  const make = (tag, attrs) => {
    const node = document.createElementNS(NS, tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    return node;
  };

  svg.replaceChildren();
  $('chartTip').hidden = true;
  if (!dates.length || !visible.length) return;

  const width = svg.clientWidth || svg.parentElement.clientWidth || 760;
  const height = 320;
  // In an RTL layout the value axis belongs on the right; time still runs left to right.
  const pad = { top: 16, right: 76, bottom: 30, left: 18 };
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);

  const percent = state.chartMode === 'percent' && visible.length > 1;
  const transform = (line) => {
    if (!percent) return line.values;
    const base = line.values.find((value) => value !== null && value !== 0);
    if (!base) return line.values.map(() => null);
    return line.values.map((value) => (value === null ? null : ((value - base) / base) * 100));
  };

  const plots = visible.map((line) => ({ ...line, points: transform(line) }));
  const flat = plots.flatMap((plot) => plot.points).filter((value) => value !== null);
  if (!flat.length) return;

  let min = Math.min(...flat);
  let max = Math.max(...flat);
  if (min === max) { min -= 1; max += 1; }
  const margin = (max - min) * 0.08;
  min -= margin;
  max += margin;

  const innerW = width - pad.left - pad.right;
  const innerH = height - pad.top - pad.bottom;
  const x = (i) => pad.left + (dates.length === 1 ? innerW / 2 : (i / (dates.length - 1)) * innerW);
  const y = (v) => pad.top + innerH - ((v - min) / (max - min)) * innerH;

  // خطوط راهنما و محور عمودی
  const grid = make('g', { class: 'chart-grid' });
  const axis = make('g', { class: 'chart-axis' });
  for (let step = 0; step <= 4; step += 1) {
    const value = min + ((max - min) * step) / 4;
    const py = y(value);
    grid.appendChild(make('line', { x1: pad.left, x2: width - pad.right, y1: py, y2: py }));
    const label = make('text', { x: width - pad.right + 14, y: py + 3.5, 'text-anchor': 'start' });
    label.textContent = percent
      ? `${faNumber(value, 1)}٪`
      : faNumber(value, value < 100 ? 2 : 0);
    axis.appendChild(label);
  }

  // محور افقی
  const ticks = Math.min(6, dates.length);
  for (let step = 0; step < ticks; step += 1) {
    const index = Math.round((step / Math.max(1, ticks - 1)) * (dates.length - 1));
    const label = make('text', {
      x: x(index),
      y: height - pad.bottom + 18,
      'text-anchor': 'middle',
    });
    label.textContent = faDigits(dates[index].slice(5));
    axis.appendChild(label);
  }
  svg.append(grid, axis);

  // خط هر سری (با پرش روی داده‌های خالی)
  plots.forEach((plot) => {
    let path = '';
    let open = false;
    plot.points.forEach((value, index) => {
      if (value === null) { open = false; return; }
      path += `${open ? 'L' : 'M'}${x(index).toFixed(1)} ${y(value).toFixed(1)} `;
      open = true;
    });
    if (!path) return;

    if (plots.length === 1) {
      const first = plot.points.findIndex((value) => value !== null);
      let last = plot.points.length - 1;
      while (last > 0 && plot.points[last] === null) last -= 1;
      const area = `${path}L${x(last).toFixed(1)} ${y(min)} L${x(first).toFixed(1)} ${y(min)} Z`;
      svg.appendChild(make('path', { d: area, class: 'chart-area', fill: plot.color }));
    }
    svg.appendChild(make('path', { d: path.trim(), class: 'chart-line', stroke: plot.color }));
  });

  // تعامل با موس
  const cursor = make('line', {
    class: 'chart-cursor', y1: pad.top, y2: pad.top + innerH, x1: 0, x2: 0, opacity: 0,
  });
  const dots = make('g', {});
  svg.append(cursor, dots);

  const tip = $('chartTip');
  const overlay = make('rect', {
    x: pad.left, y: pad.top, width: innerW, height: innerH, fill: 'transparent',
  });

  overlay.addEventListener('pointermove', (event) => {
    const box = svg.getBoundingClientRect();
    const scale = width / box.width;
    const px = (event.clientX - box.left) * scale;
    const ratio = (px - pad.left) / innerW;
    const index = Math.max(0, Math.min(dates.length - 1, Math.round(ratio * (dates.length - 1))));

    cursor.setAttribute('x1', x(index));
    cursor.setAttribute('x2', x(index));
    cursor.setAttribute('opacity', 1);

    dots.replaceChildren();
    const rows = [];
    plots.forEach((plot) => {
      const value = plot.points[index];
      if (value === null) return;
      dots.appendChild(make('circle', {
        cx: x(index), cy: y(value), r: 4, fill: plot.color, class: 'chart-dot',
      }));
      const raw = plot.values[index];
      rows.push({
        color: plot.color,
        name: plot.name,
        text: percent
          ? `${value >= 0 ? '+' : '−'}${faNumber(Math.abs(value), 2)}٪`
          : `${faNumber(raw, plot.decimals)} ${plot.unit}`,
      });
    });

    tip.replaceChildren(el('div', 'chart-tip__date', faDate(dates[index])));
    rows.forEach((row) => {
      const line = el('div', 'chart-tip__row');
      const left = el('span');
      const dot = el('span', 'dot');
      dot.style.setProperty('--dot', row.color);
      left.append(dot, document.createTextNode(row.name));
      line.append(left, el('b', 'num', row.text));
      tip.appendChild(line);
    });

    // `left` is a physical coordinate, so it must not be mirrored by the RTL layout.
    const wrapWidth = svg.parentElement.clientWidth;
    const half = tip.offsetWidth / 2 || 80;
    tip.hidden = false;
    tip.style.right = 'auto';
    tip.style.left = `${Math.min(Math.max((x(index) / width) * wrapWidth, half + 4), wrapWidth - half - 4)}px`;
    tip.style.top = '14px';
  });

  overlay.addEventListener('pointerleave', () => {
    cursor.setAttribute('opacity', 0);
    dots.replaceChildren();
    tip.hidden = true;
  });
  svg.appendChild(overlay);

  // راهنما
  const legend = $('chartLegend');
  legend.replaceChildren();
  lines.forEach((line) => {
    const item = el('span', 'legend__item' + (state.hidden.has(line.index) ? ' is-off' : ''));
    const dot = el('span', 'dot');
    dot.style.setProperty('--dot', line.color);
    item.append(dot, document.createTextNode(line.name));
    item.onclick = () => {
      if (state.hidden.has(line.index)) state.hidden.delete(line.index);
      else if (state.hidden.size < lines.length - 1) state.hidden.add(line.index);
      renderChart();
    };
    legend.appendChild(item);
  });

  $('chartSub').textContent = percent
    ? 'درصد تغییر نسبت به اولین روز بازه — برای مقایسه نمادها با مقیاس‌های متفاوت'
    : `قیمت پایانی روزانه (${plots[0].unit})`;
}

/* ── جدول ──────────────────────────────────────────────── */
function renderTableTabs() {
  const box = $('tableTabs');
  box.replaceChildren();
  state.result.series.forEach((series, index) => {
    const chip = el('button', 'chip', series.symbol.name);
    chip.type = 'button';
    chip.setAttribute('role', 'tab');
    chip.setAttribute('aria-selected', String(state.activeTab === index));
    chip.onclick = () => {
      state.activeTab = index;
      renderTableTabs();
      renderTable();
    };
    box.appendChild(chip);
  });
}

function renderTable() {
  const series = state.result.series[state.activeTab];
  if (!series) return;
  const decimals = series.symbol.decimals;
  const headers = ['تاریخ شمسی', 'روز هفته', 'کمترین', 'بیشترین', 'پایانی', 'میانگین معاملاتی', 'وضعیت'];

  const thead = $('dataTable').tHead;
  thead.replaceChildren();
  const headRow = thead.insertRow();
  headers.forEach((title) => headRow.appendChild(el('th', null, title)));

  const tbody = $('dataTable').tBodies[0];
  const fragment = document.createDocumentFragment();
  series.rows.forEach((row) => {
    const tr = el('tr', row.live ? '' : 'is-filled');
    tr.appendChild(el('td', 'num', faDate(row.date)));
    tr.appendChild(el('td', null, row.weekday));
    [row.low, row.high, row.close, row.average].forEach((value) => {
      tr.appendChild(el('td', 'num', faNumber(value, decimals)));
    });

    const cell = el('td');
    const kind = row.live ? '' : row.close === null ? ' tag--none' : ' tag--filled';
    cell.appendChild(el('span', `tag${kind}`, row.live ? 'معامله شده' : row.close === null ? 'بدون داده' : 'قیمت روز قبل'));
    tr.appendChild(cell);
    fragment.appendChild(tr);
  });
  tbody.replaceChildren(fragment);

  $('tableSub').textContent =
    `${series.symbol.name} — ${faDigits(series.stats.days)} روز، ` +
    `${faDigits(series.stats.trading_days)} روز معاملاتی (واحد: ${series.stats.unit})`;
  $('tableFoot').textContent = state.range
    ? `بازه گزارش: ${faDate(state.range.start)} تا ${faDate(state.range.end)}`
    : '';
}

/* ── آرشیو ─────────────────────────────────────────────── */
function renderArchive(rows) {
  const box = $('archiveList');
  box.replaceChildren();
  const names = new Map(state.symbols.map((symbol) => [symbol.key, symbol.name]));

  if (!rows || !rows.length) {
    box.appendChild(el('div', 'archive__empty', 'هنوز داده‌ای آرشیو نشده است. یک گزارش بسازید یا «کراول همین حالا» را بزنید.'));
    return;
  }

  rows.forEach((row) => {
    const item = el('div', 'archive__item');
    item.append(
      el('div', 'archive__name', names.get(row.key) || row.key),
      el('div', 'archive__meta', `${faDigits(row.days)} روز — تا ${faDate(row.last)}`),
    );
    box.appendChild(item);
  });
}

/* ── عملیات ────────────────────────────────────────────── */
async function persistSettings() {
  const dates = {
    start: toLatinDigits($('startDate').value).trim(),
    end: toLatinDigits($('endDate').value).trim(),
  };
  try {
    await api('settings', {
      method: 'POST',
      body: {
        symbols: [...state.selected],
        range_preset: state.preset,
        start: dates.start,
        end: dates.end,
        fill_gaps: $('fillGaps').checked,
        auto_crawl: $('autoCrawl').checked,
        theme: ROOT.dataset.theme,
      },
    });
  } catch {
    /* ذخیره تنظیمات حیاتی نیست */
  }
}

async function fetchSeries(force = false) {
  if (state.busy) return;
  if (!state.selected.size) {
    toast('حداقل یک نماد را انتخاب کنید.', 'warn');
    return;
  }
  const dates = readDates();
  if (!dates) {
    toast('قالب تاریخ نامعتبر است.', 'error');
    return;
  }

  busy(true, force ? 'دریافت تازه از TGJU…' : 'در حال دریافت داده‌ها…');
  try {
    const payload = await api('series', {
      method: 'POST',
      body: {
        symbols: [...state.selected],
        start: dates.start,
        end: dates.end,
        fillGaps: $('fillGaps').checked,
        force,
      },
    });

    if (!payload.ok && !(payload.series || []).length) {
      throw new Error(payload.error || 'دریافت داده ناموفق بود.');
    }

    state.result = payload;
    state.range = payload.range || dates;
    state.activeTab = 0;
    state.hidden.clear();
    state.chartMode = payload.series.length > 1 ? 'percent' : 'absolute';

    $('emptyState').hidden = true;
    $('results').hidden = false;
    renderStats();
    renderChartModes();
    renderChart();
    renderTableTabs();
    renderTable();

    (payload.errors || []).forEach((error) => toast(error.message, 'error', 8000));
    if ((payload.fromCache || []).length && !force) {
      toast('بخشی از داده‌ها از حافظه موقت خوانده شد. برای دریافت تازه «دریافت تازه» را بزنید.', 'info');
    } else if (!payload.errors.length) {
      toast('داده‌ها با موفقیت دریافت شد.', 'ok', 3000);
    }
    refreshArchive();
    persistSettings();
  } catch (error) {
    toast(error.message, 'error', 9000);
  } finally {
    busy(false);
  }
}

async function exportAs(format) {
  if (!state.selected.size) {
    toast('حداقل یک نماد را انتخاب کنید.', 'warn');
    return;
  }
  const dates = readDates();
  if (!dates) return;

  busy(true, 'در حال ساخت فایل خروجی…');
  try {
    const response = await api('export', {
      method: 'POST',
      raw: true,
      body: {
        symbols: [...state.selected],
        start: dates.start,
        end: dates.end,
        fillGaps: $('fillGaps').checked,
        format,
      },
    });

    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = el('a');
    anchor.href = url;
    anchor.download = match ? match[1] : `TGJU.${format}`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);

    try {
      const warnings = JSON.parse(decodeURIComponent(response.headers.get('X-Export-Warnings') || '[]'));
      warnings.forEach((message) => toast(message, 'warn', 8000));
    } catch { /* بی‌اهمیت */ }

    toast('فایل خروجی ساخته شد.', 'ok', 3500);
  } catch (error) {
    toast(error.message, 'error', 9000);
  } finally {
    busy(false);
  }
}

async function refreshArchive() {
  try {
    const payload = await api('archive');
    renderArchive(payload.archive);
  } catch { /* بی‌اهمیت */ }
}

async function crawlNow() {
  if (state.busy) return;
  busy(true, 'در حال کراول روزانه…');
  try {
    const payload = await api('crawl', { method: 'POST', body: { symbols: [...state.selected] } });
    renderArchive(payload.archive);
    const added = Object.values(payload.added || {}).reduce((sum, n) => sum + n, 0);
    (payload.errors || []).forEach((error) => toast(error.message, 'error', 8000));
    toast(`کراول ${faDate(payload.date)} انجام شد — ${faDigits(added)} روز تازه ذخیره شد.`, 'ok');
  } catch (error) {
    toast(error.message, 'error', 9000);
  } finally {
    busy(false);
  }
}

async function addCustomSymbol() {
  const key = $('customKey').value.trim();
  if (!key) {
    toast('شناسه نماد را وارد کنید.', 'warn');
    return;
  }
  try {
    const payload = await api('symbols', {
      method: 'POST',
      body: { key, name: $('customName').value.trim(), currency: $('customCurrency').value },
    });
    state.symbols = payload.symbols;
    state.selected.add(key);
    $('customKey').value = '';
    $('customName').value = '';
    renderGroupFilters();
    renderSymbolList();
    updateSymbolCount();
    persistSettings();
    toast('نماد افزوده و انتخاب شد.', 'ok', 3500);
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* ── راه‌اندازی ────────────────────────────────────────── */
function applyTheme(theme) {
  ROOT.dataset.theme = theme;
}

async function init() {
  $('versionPill').textContent = `v${GC_CONFIG.version || ''}`;

  let meta;
  try {
    meta = await api('meta');
  } catch (error) {
    toast('ارتباط با سرور برقرار نشد. برنامه را دوباره اجرا کنید.', 'error', 12000);
    return;
  }

  state.meta = meta;
  state.symbols = meta.symbols;
  state.presets = meta.presets || [];

  const settings = meta.settings || {};
  applyTheme(settings.theme === 'dark' ? 'dark' : 'light');
  $('todayPill').textContent = meta.todayLong;
  $('fillGaps').checked = settings.fill_gaps !== false;
  $('autoCrawl').checked = settings.auto_crawl !== false;

  (settings.symbols || []).forEach((key) => state.selected.add(key));
  state.preset = settings.range_preset || '30';

  const fallback = state.presets.find((p) => p.id === state.preset) || state.presets[0];
  $('startDate').value = faDigits(settings.start || (fallback ? fallback.start : ''));
  $('endDate').value = faDigits(settings.end || (fallback ? fallback.end : meta.today));

  renderGroupFilters();
  renderSymbolList();
  updateSymbolCount();
  renderPresets();
  renderArchive(meta.archive);

  // رویدادها
  $('symbolSearch').addEventListener('input', (event) => {
    state.query = event.target.value;
    renderSymbolList();
  });
  $('fetchBtn').onclick = () => fetchSeries(false);
  $('refreshBtn').onclick = () => fetchSeries(true);
  $('crawlBtn').onclick = crawlNow;
  $('addSymbolBtn').onclick = addCustomSymbol;
  $('fillGaps').onchange = persistSettings;
  $('autoCrawl').onchange = persistSettings;
  ['startDate', 'endDate'].forEach((id) => {
    $(id).addEventListener('change', () => {
      state.preset = 'custom';
      renderPresets();
      persistSettings();
    });
  });
  document.querySelectorAll('.export .btn').forEach((button) => {
    button.onclick = () => exportAs(button.dataset.format);
  });
  $('themeBtn').onclick = () => {
    applyTheme(ROOT.dataset.theme === 'dark' ? 'light' : 'dark');
    persistSettings();
    if (state.result) renderChart();
  };

  window.addEventListener('resize', () => {
    if (state.result) renderChart();
  });
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') fetchSeries(false);
  });
}

document.addEventListener('DOMContentLoaded', init);
