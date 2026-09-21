/**
 * CYBERGUARD CAMPUS — shared chart builders (plain HTML via createElement, no library, no HTML strings).
 * Every chart is built only from values passed in by a page (API data), ships a
 * hover + keyboard tooltip, and a "View as table" twin so no value is gated.
 *
 * Colors (validated with the dataviz validator against the dark chart surface #0c1522):
 *  - one-series columns/bars use the accent (a single series needs no legend);
 *  - magnitude grids use HEAT_RAMP, a one-hue ordinal ramp (all ordinal checks pass).
 */

import { clearChildren, el, formatCount, parseApiDate } from './format.js';

const LOCALE = 'en-GB';

/** Ordinal cyan ramp, low → high magnitude. Zero is never filled. */
export const HEAT_RAMP = Object.freeze(['#205d8c', '#2276b3', '#2e93d6', '#5ab8f2', '#a3dbfb']);

/** Ramp step for a value (−1 = zero / no fill). */
export function heatStep(value, max) {
  if (!(value > 0) || !(max > 0)) {
    return -1;
  }

  return Math.min(HEAT_RAMP.length - 1, Math.floor((value / max) * HEAT_RAMP.length - 1e-9));
}

/* Time bins ----------------------------------------------------------------------- */

function dayKey(date) {
  const pad = (n) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Buckets items into the last `days` local calendar days (oldest first).
 * `getDate(item)` returns the raw API date. Items outside the window are counted in `outside`.
 */
export function binByDay(items, getDate, days, now = new Date()) {
  const bins = [];
  const index = new Map();

  for (let offset = days - 1; offset >= 0; offset -= 1) {
    const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() - offset);
    const bin = { key: dayKey(date), date, count: 0, items: [] };
    bins.push(bin);
    index.set(bin.key, bin);
  }

  let outside = 0;
  let undated = 0;

  items.forEach((item) => {
    const date = parseApiDate(getDate(item));

    if (!date) {
      undated += 1;
      return;
    }

    const bin = index.get(dayKey(date));

    if (bin) {
      bin.count += 1;
      bin.items.push(item);
    } else {
      outside += 1;
    }
  });

  return { bins, outside, undated };
}

/**
 * Buckets items into the last `hours` clock hours, current hour included (oldest first).
 * Same contract as binByDay: items outside the window → `outside`, invalid dates → `undated`.
 */
export function binByHour(items, getDate, hours, now = new Date()) {
  const currentHour = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours());
  const bins = [];
  const index = new Map();

  for (let offset = hours - 1; offset >= 0; offset -= 1) {
    const date = new Date(currentHour.getTime() - offset * 3600 * 1000);
    const bin = { key: date.getTime(), date, count: 0, items: [] };
    bins.push(bin);
    index.set(bin.key, bin);
  }

  let outside = 0;
  let undated = 0;

  items.forEach((item) => {
    const date = parseApiDate(getDate(item));

    if (!date) {
      undated += 1;
      return;
    }

    const hourStart = new Date(date.getFullYear(), date.getMonth(), date.getDate(), date.getHours()).getTime();
    const bin = index.get(hourStart);

    if (bin) {
      bin.count += 1;
      bin.items.push(item);
    } else {
      outside += 1;
    }
  });

  return { bins, outside, undated };
}

export function formatDay(date, options = { day: '2-digit', month: 'short' }) {
  return new Intl.DateTimeFormat(LOCALE, options).format(date);
}

/** Clean axis maximum and ticks (1, 2, 5 × 10^n steps, ~4 ticks). */
function niceScale(max) {
  if (max <= 0) {
    return { top: 4, ticks: [0, 1, 2, 3, 4] };
  }

  const rough = max / 4;
  const power = 10 ** Math.floor(Math.log10(rough));
  const step = [1, 2, 5, 10].map((m) => m * power).find((s) => s >= rough) || power * 10;
  const safeStep = Math.max(1, step);
  const top = Math.ceil(max / safeStep) * safeStep;
  const ticks = [];

  for (let value = 0; value <= top; value += safeStep) {
    ticks.push(value);
  }

  return { top, ticks };
}

/* Tooltip ------------------------------------------------------------------------------- */

function createTooltip() {
  return el('div', { className: 'chart-tooltip', attrs: { role: 'presentation', 'aria-hidden': 'true' } });
}

/** lines: [{ value, label }] — values lead, labels follow. */
function showTooltip(tooltip, host, target, heading, lines) {
  clearChildren(tooltip);
  tooltip.append(el('p', { className: 'chart-tooltip-heading', text: heading }));

  lines.forEach(({ value, label }) => {
    tooltip.append(el('p', { className: 'chart-tooltip-row' }, [
      el('strong', { text: value }),
      el('span', { text: label }),
    ]));
  });

  tooltip.classList.add('is-visible');

  const hostBox = host.getBoundingClientRect();
  const box = target.getBoundingClientRect();
  const width = tooltip.offsetWidth;
  const left = Math.min(Math.max(0, box.left - hostBox.left + box.width / 2 - width / 2), hostBox.width - width);

  tooltip.style.left = `${Math.max(0, left)}px`;
  tooltip.style.top = `${Math.max(0, box.top - hostBox.top - tooltip.offsetHeight - 8)}px`;
}

function hideTooltip(tooltip) {
  tooltip.classList.remove('is-visible');
}

function bindTooltip(target, tooltip, host, heading, lines) {
  const show = () => showTooltip(tooltip, host, target, heading, lines);
  const hide = () => hideTooltip(tooltip);

  target.addEventListener('pointerenter', show);
  target.addEventListener('pointerleave', hide);
  target.addEventListener('focus', show);
  target.addEventListener('blur', hide);
}

/* Table twin ------------------------------------------------------------------------------ */

function tableView(caption, headers, rows) {
  return el('details', { className: 'chart-table' }, [
    el('summary', { text: 'View as table' }),
    el('div', { className: 'table-scroll' }, [
      el('table', { className: 'table' }, [
        el('caption', { className: 'visually-hidden', text: caption }),
        el('thead', {}, [el('tr', {}, headers.map((h) => el('th', { text: h, attrs: { scope: 'col' } })))]),
        el('tbody', {}, rows.map((cells) => el('tr', {}, cells.map((c, i) => (i === 0
          ? el('th', { text: c, attrs: { scope: 'row' } })
          : el('td', { className: 'mono', text: c })))))),
      ]),
    ]),
  ]);
}

/* Column chart (one series) ---------------------------------------------------------------- */

/**
 * bins: [{ label, shortLabel, value, lines: [{ value, label }] }]
 * Returns a <figure>. `unit` is the singular noun ("alert").
 */
export function columnChart({ caption, bins, unit, labelEvery = 1 }) {
  const max = Math.max(0, ...bins.map((bin) => bin.value));
  const { top, ticks } = niceScale(max);
  const plural = (n) => `${formatCount(n)} ${n === 1 ? unit : `${unit}s`}`;

  const host = el('div', { className: 'chart-host' });
  const tooltip = createTooltip();

  const grid = el('div', { className: 'chart-grid', attrs: { 'aria-hidden': 'true' } }, ticks.slice().reverse().map((tick) => (
    el('span', { className: 'chart-gridline' }, [el('span', { className: 'chart-tick', text: formatCount(tick) })])
  )));

  const columns = el('ol', { className: 'chart-columns', attrs: { 'aria-label': caption } }, bins.map((bin, index) => {
    const bar = el('span', { className: 'chart-column-bar' });
    bar.style.height = `${top > 0 ? (bin.value / top) * 100 : 0}%`;

    const mark = el('span', {
      className: `chart-column${bin.value === 0 ? ' is-zero' : ''}`,
      attrs: { tabindex: '0', role: 'img', 'aria-label': `${bin.label}: ${plural(bin.value)}` },
    }, [bar]);

    bindTooltip(mark, tooltip, host, bin.label, [{ value: plural(bin.value), label: '' }, ...(bin.lines || [])]);

    const showLabel = index % labelEvery === 0 || index === bins.length - 1;

    return el('li', { className: 'chart-slot' }, [
      mark,
      el('span', { className: `chart-x${showLabel ? '' : ' is-quiet'}`, text: bin.shortLabel, attrs: { 'aria-hidden': 'true' } }),
    ]);
  }));

  host.append(grid, columns, tooltip);

  return el('figure', { className: 'chart' }, [
    host,
    tableView(caption, ['Day', `${unit[0].toUpperCase()}${unit.slice(1)}s`], bins.map((bin) => [bin.label, formatCount(bin.value)])),
  ]);
}

/* Heat grid (rows × columns, roving keyboard focus) ----------------------------------------- */

/**
 * rows / cols: label arrays; values[r][c]: counts.
 * describe(r, c, value) → tooltip heading; colTicks: indices of column labels to show.
 */
export function heatGrid({ caption, rows, cols, values, unit, describe, colTicks }) {
  const max = Math.max(0, ...values.flat());
  const host = el('div', { className: 'chart-host heat-host' });
  const tooltip = createTooltip();
  const plural = (n) => `${formatCount(n)} ${n === 1 ? unit : `${unit}s`}`;
  const cells = [];

  const grid = el('div', {
    className: 'heat-grid',
    attrs: { role: 'grid', 'aria-label': caption },
  });
  grid.style.setProperty('--heat-cols', String(cols.length));

  rows.forEach((rowLabel, r) => {
    const row = el('div', { className: 'heat-row', attrs: { role: 'row' } }, [
      el('span', { className: 'heat-row-label', text: rowLabel, attrs: { role: 'rowheader' } }),
    ]);

    cols.forEach((colLabel, c) => {
      const value = values[r][c];
      const step = heatStep(value, max);
      const cell = el('span', {
        className: `heat-cell${step < 0 ? ' is-zero' : ''}`,
        attrs: { role: 'gridcell', tabindex: '-1', 'aria-label': `${describe(r, c)}: ${plural(value)}` },
      });

      if (step >= 0) {
        cell.style.setProperty('--heat', HEAT_RAMP[step]);
      }

      bindTooltip(cell, tooltip, host, describe(r, c), [{ value: plural(value), label: '' }]);
      cells.push({ cell, r, c });
      row.append(cell);
    });

    grid.append(row);
  });

  // One tab stop for the whole grid; arrow keys move between cells.
  if (cells.length) {
    cells[0].cell.tabIndex = 0;
  }

  grid.addEventListener('keydown', (event) => {
    const moves = { ArrowRight: [0, 1], ArrowLeft: [0, -1], ArrowDown: [1, 0], ArrowUp: [-1, 0] };
    const move = moves[event.key];
    const current = cells.find((entry) => entry.cell === document.activeElement);

    if (!move || !current) {
      return;
    }

    event.preventDefault();
    const r = Math.min(rows.length - 1, Math.max(0, current.r + move[0]));
    const c = Math.min(cols.length - 1, Math.max(0, current.c + move[1]));
    const next = cells.find((entry) => entry.r === r && entry.c === c);

    current.cell.tabIndex = -1;
    next.cell.tabIndex = 0;
    next.cell.focus();
  });

  const axis = el('div', { className: 'heat-axis', attrs: { 'aria-hidden': 'true' } }, [
    el('span', { className: 'heat-row-label' }),
    ...cols.map((label, c) => el('span', { className: 'heat-axis-label', text: colTicks.includes(c) ? label : '' })),
  ]);
  axis.style.setProperty('--heat-cols', String(cols.length));

  host.append(grid, axis, tooltip);

  return el('figure', { className: 'chart' }, [
    host,
    scaleLegend(max, unit),
    tableView(caption, ['', ...cols], rows.map((label, r) => [label, ...values[r].map((v) => formatCount(v))])),
  ]);
}

/** Sequential scale legend: "0" swatch (empty) then the ramp from low to max. */
export function scaleLegend(max, unit) {
  return el('div', { className: 'scale-legend', attrs: { 'aria-hidden': 'true' } }, [
    el('span', { className: 'scale-legend-label', text: '0' }),
    el('span', { className: 'scale-swatch is-zero' }),
    ...HEAT_RAMP.map((color) => {
      const swatch = el('span', { className: 'scale-swatch' });
      swatch.style.setProperty('--heat', color);
      return swatch;
    }),
    el('span', { className: 'scale-legend-label', text: max > 0 ? `${formatCount(max)} ${max === 1 ? unit : `${unit}s`}` : 'none yet' }),
  ]);
}

/* Horizontal bar list (one series, every value labelled at the tip) ------------------------ */

/** items: [{ label, value, note }] */
export function barList({ caption, items, unit }) {
  const max = Math.max(0, ...items.map((item) => item.value));
  const plural = (n) => `${formatCount(n)} ${n === 1 ? unit : `${unit}s`}`;

  const list = el('ol', { className: 'bar-list', attrs: { 'aria-label': caption } }, items.map((item) => {
    const fill = el('span', { className: 'bar-list-fill' });
    fill.style.width = `${max > 0 ? (item.value / max) * 100 : 0}%`;

    return el('li', { className: `bar-list-item${item.value === 0 ? ' is-zero' : ''}` }, [
      el('span', { className: 'bar-list-label' }, [
        el('span', { text: item.label }),
        item.note ? el('span', { className: 'bar-list-note', text: item.note }) : el('span'),
      ]),
      el('span', { className: 'bar-list-track', attrs: { 'aria-hidden': 'true' } }, [fill]),
      el('span', { className: 'bar-list-value', text: plural(item.value) }),
    ]);
  }));

  return el('figure', { className: 'chart' }, [list]);
}
