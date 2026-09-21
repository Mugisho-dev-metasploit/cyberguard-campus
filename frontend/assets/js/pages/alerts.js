/**
 * CYBERGUARD CAMPUS — Alerts.
 * Data: GET /api/alerts (read-only; the backend offers no pagination, filters or actions).
 * Filtering, sorting and paging are applied client-side to the list the API returns.
 * When the queue is empty, GET /api/metrics explains why (events recorded, alerts raised).
 *
 * The queue is always in exactly one view: loading | table | no match | empty | error.
 * Search and filters stay available whenever the API answered — including an empty queue,
 * where they apply as soon as alerts arrive. They are only removed when the API failed.
 */

import { ApiError, getAlerts, getMetrics } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { heatGrid } from '../charts.js';
import {
  ALERT_STATUSES,
  clearChildren,
  describeError,
  el,
  formatClockTime,
  formatCount,
  formatDateTime,
  formatSeverity,
  formatStatus,
  icon,
  parseApiDate,
  severityClass,
  severityLevel,
  severityTag,
  stateBlock,
  statusTag,
  toCount,
  toDateTimeAttribute,
} from '../format.js';

const PAGE_SIZE = 50;
const LOADING_ROWS = 6;
const COLUMN_COUNT = 5;
const SEARCH_DELAY_MS = 150;
const ANNOUNCE_DELAY_MS = 450;
const SEVERITY_OPTIONS = [4, 3, 2, 1];
const SORT_OPTIONS = ['newest', 'oldest', 'severity'];
const HOUR_MS = 60 * 60 * 1000;
const RANGE_OPTIONS = [
  { value: '24h', label: 'Last 24 hours', ms: 24 * HOUR_MS },
  { value: '7d', label: 'Last 7 days', ms: 7 * 24 * HOUR_MS },
  { value: '30d', label: 'Last 30 days', ms: 30 * 24 * HOUR_MS },
];
const NO_CATEGORY = '__none__';
const MAX_PARAM_LENGTH = 120;
const EVENTS_PAGE = 'events.html';

const HINT_READY = 'Select a stage to show only its alerts.';
const HINT_WAITING = 'Stages become selectable once alerts arrive.';

const dom = {
  refresh: document.getElementById('alerts-refresh'),
  syncStatus: document.getElementById('sync-status'),
  syncText: document.getElementById('sync-status-text'),
  announcer: document.getElementById('alerts-announcer'),
  stages: Array.from(document.querySelectorAll('.stage[data-status]')),
  hint: document.getElementById('lifecycle-hint'),
  summary: document.getElementById('queue-summary'),
  count: document.getElementById('queue-count'),
  form: document.getElementById('queue-filters'),
  fieldset: document.getElementById('queue-filters-set'),
  search: document.getElementById('filter-search'),
  severity: document.getElementById('filter-severity'),
  status: document.getElementById('filter-status'),
  detected: document.getElementById('filter-range'),
  category: document.getElementById('filter-category'),
  source: document.getElementById('filter-source'),
  sort: document.getElementById('filter-sort'),
  chips: document.getElementById('filter-chips'),
  chipList: document.getElementById('chip-list'),
  clear: document.getElementById('filters-clear'),
  table: document.getElementById('queue-table'),
  body: document.getElementById('queue-body'),
  empty: document.getElementById('queue-empty'),
  footer: document.getElementById('queue-footer'),
  range: document.getElementById('queue-range'),
  more: document.getElementById('queue-more'),
  rhythm: document.getElementById('rhythm-body'),
};

const filters = {
  search: '',
  severity: '',
  category: '',
  source: '',
  status: '',
  range: '',
  sort: 'newest',
};

let alerts = [];
let limit = PAGE_SIZE;
let isLoading = false;
let searchTimer = 0;
let announceTimer = 0;

/* Helpers ----------------------------------------------------------------------- */

function plural(count, word) {
  return `${formatCount(count)} ${count === 1 ? word : `${word}s`}`;
}

function text(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function announce(message, { delayed = false } = {}) {
  window.clearTimeout(announceTimer);

  if (delayed) {
    announceTimer = window.setTimeout(() => {
      dom.announcer.textContent = message;
    }, ANNOUNCE_DELAY_MS);
  } else {
    dom.announcer.textContent = message;
  }
}

function setSyncStatus(message, state) {
  dom.syncText.textContent = message;
  dom.syncStatus.dataset.state = state;
}

// aria-disabled (not `disabled`) keeps keyboard focus on the button while loading.
function setBusy(busy) {
  isLoading = busy;
  dom.refresh.setAttribute('aria-disabled', String(busy));
  dom.refresh.setAttribute('aria-busy', String(busy));
}

/**
 * Filter bar visibility:
 * 'hidden' — the API failed, or the very first load is still running;
 * 'paused' — a refresh is running;
 * 'ready'  — the API answered (with or without alerts): search and filters work.
 */
function setFilterBar(state) {
  dom.form.hidden = state === 'hidden';
  dom.fieldset.disabled = state !== 'ready';

  if (state === 'hidden') {
    dom.chips.hidden = true;
  }
}

function setStagesEnabled(enabled) {
  dom.stages.forEach((stage) => {
    stage.disabled = !enabled;
  });
  dom.hint.textContent = enabled ? HINT_READY : HINT_WAITING;
}

/** 'table' shows the queue table (rows, loading, no match, error); 'empty' the intake view. */
function showView(view) {
  dom.table.hidden = view !== 'table';
  dom.empty.hidden = view !== 'empty';

  if (view !== 'table') {
    dom.footer.hidden = true;
  }
}

function hasActiveFilters() {
  return Boolean(filters.search || filters.severity || filters.category || filters.source
    || filters.status || filters.range);
}

/* Shareable view: filters mirrored in the URL (validated, never trusted) ----------- */

function readFiltersFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const clip = (value) => (value || '').trim().slice(0, MAX_PARAM_LENGTH);

  const status = params.get('status');
  const severity = params.get('severity');
  const sort = params.get('sort');

  filters.status = ALERT_STATUSES.includes(status) ? status : '';
  filters.severity = /^[1-4]$/.test(severity || '') ? severity : '';
  filters.sort = SORT_OPTIONS.includes(sort) ? sort : 'newest';
  filters.range = RANGE_OPTIONS.some((option) => option.value === params.get('range')) ? params.get('range') : '';
  filters.search = clip(params.get('q'));
  // Category and source are kept only if they exist in the loaded data (see fillSelect).
  filters.category = clip(params.get('category'));
  filters.source = clip(params.get('source'));
}

function writeFiltersToUrl() {
  const params = new URLSearchParams();

  if (filters.search) params.set('q', filters.search);
  if (filters.status) params.set('status', filters.status);
  if (filters.severity) params.set('severity', filters.severity);
  if (filters.range) params.set('range', filters.range);
  if (filters.category) params.set('category', filters.category);
  if (filters.source) params.set('source', filters.source);
  if (filters.sort !== 'newest') params.set('sort', filters.sort);

  const query = params.toString();
  window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
}

function syncControls() {
  dom.search.value = filters.search;
  dom.severity.value = filters.severity;
  dom.status.value = filters.status;
  dom.detected.value = filters.range;
  dom.category.value = filters.category;
  dom.source.value = filters.source;
  dom.sort.value = filters.sort;
}

/* Filtering ----------------------------------------------------------------------- */

function searchableText(alert) {
  return [alert.title, alert.source, alert.alert_type, alert.category, alert.signature, alert.description, alert.alert_uuid]
    .map(text)
    .join('\n')
    .toLowerCase();
}

function matches(alert) {
  if (filters.status && alert.status !== filters.status) {
    return false;
  }

  if (filters.severity && severityLevel(alert.severity) !== Number(filters.severity)) {
    return false;
  }

  if (filters.category) {
    const category = text(alert.category);

    if (filters.category === NO_CATEGORY ? category !== '' : category !== filters.category) {
      return false;
    }
  }

  if (filters.source && text(alert.source) !== filters.source) {
    return false;
  }

  if (filters.range && !withinRange(alert, filters.range)) {
    return false;
  }

  if (filters.search && !searchableText(alert).includes(filters.search.toLowerCase())) {
    return false;
  }

  return true;
}

function detectedTime(alert) {
  const date = parseApiDate(alert.detected_at);

  return date ? date.getTime() : Number.NEGATIVE_INFINITY;
}

/** Alerts without a valid detection time are excluded from any time range. */
function withinRange(alert, range) {
  const option = RANGE_OPTIONS.find((entry) => entry.value === range);
  const time = detectedTime(alert);

  return Boolean(option) && Number.isFinite(time) && time >= Date.now() - option.ms;
}

function sortAlerts(list) {
  // The API already returns newest first; keep that order as the stable base.
  const indexed = list.map((alert, index) => ({ alert, index }));

  if (filters.sort === 'oldest') {
    indexed.sort((a, b) => detectedTime(a.alert) - detectedTime(b.alert) || b.index - a.index);
  } else if (filters.sort === 'severity') {
    indexed.sort((a, b) => (severityLevel(b.alert.severity) ?? 0) - (severityLevel(a.alert.severity) ?? 0)
      || a.index - b.index);
  }

  return indexed.map(({ alert }) => alert);
}

/* Filter controls ------------------------------------------------------------------- */

function fillSelect(select, entries, selected, allLabel = 'All') {
  clearChildren(select);
  select.append(el('option', { text: allLabel, attrs: { value: '' } }));

  entries.forEach(({ value, label, count }) => {
    const option = el('option', { text: `${label} (${formatCount(count)})` });
    option.value = value;
    select.append(option);
  });

  // Keep the previous choice only if it still exists in the refreshed data.
  const exists = entries.some((entry) => entry.value === selected);
  select.value = exists ? selected : '';

  return select.value;
}

function tally(values) {
  const counts = new Map();

  values.forEach((value) => {
    counts.set(value, (counts.get(value) || 0) + 1);
  });

  return counts;
}

function categoryLabel(value) {
  return value === NO_CATEGORY ? 'Uncategorized' : value;
}

function populateFilterOptions() {
  // The four severity levels are the backend contract: always offered, with their count.
  const severityCounts = tally(alerts.map((alert) => severityLevel(alert.severity)));
  filters.severity = fillSelect(dom.severity, SEVERITY_OPTIONS.map((level) => ({
    value: String(level),
    label: formatSeverity(level),
    count: severityCounts.get(level) || 0,
  })), filters.severity);

  // Statuses are the backend ENUM: always offered, with their count.
  const statusCounts = tally(alerts.map((alert) => alert.status));
  filters.status = fillSelect(dom.status, ALERT_STATUSES.map((status) => ({
    value: status,
    label: formatStatus(status),
    count: statusCounts.get(status) || 0,
  })), filters.status);

  filters.range = fillSelect(dom.detected, RANGE_OPTIONS.map((option) => ({
    value: option.value,
    label: option.label,
    count: alerts.filter((alert) => withinRange(alert, option.value)).length,
  })), filters.range, 'All time');

  const categoryCounts = tally(alerts.map((alert) => text(alert.category) || NO_CATEGORY));
  const categories = Array.from(categoryCounts.keys())
    .sort((a, b) => (a === NO_CATEGORY) - (b === NO_CATEGORY) || a.localeCompare(b))
    .map((value) => ({ value, label: categoryLabel(value), count: categoryCounts.get(value) }));
  filters.category = fillSelect(dom.category, categories, filters.category);

  const sourceCounts = tally(alerts.map((alert) => text(alert.source)).filter(Boolean));
  const sources = Array.from(sourceCounts.keys())
    .sort((a, b) => a.localeCompare(b))
    .map((value) => ({ value, label: value, count: sourceCounts.get(value) }));
  filters.source = fillSelect(dom.source, sources, filters.source);

  dom.sort.value = filters.sort;
}

function clearFilters() {
  filters.search = '';
  filters.severity = '';
  filters.category = '';
  filters.source = '';
  filters.status = '';
  filters.range = '';
  syncControls();
  applyFilters({ announceResult: true });
  dom.search.focus();
}

/* Active filter chips ------------------------------------------------------------------ */

function activeFilterEntries() {
  const entries = [];

  if (filters.search) entries.push({ key: 'search', label: 'Search', value: `“${filters.search}”` });
  if (filters.status) entries.push({ key: 'status', label: 'Status', value: formatStatus(filters.status) });
  if (filters.severity) entries.push({ key: 'severity', label: 'Severity', value: formatSeverity(filters.severity) });
  if (filters.range) {
    const option = RANGE_OPTIONS.find((entry) => entry.value === filters.range);
    entries.push({ key: 'range', label: 'Detected', value: option ? option.label : filters.range });
  }
  if (filters.category) entries.push({ key: 'category', label: 'Category', value: categoryLabel(filters.category) });
  if (filters.source) entries.push({ key: 'source', label: 'Source', value: filters.source });

  return entries;
}

function removeFilter(key, position) {
  filters[key] = '';
  syncControls();
  applyFilters({ announceResult: true });

  // Keep keyboard users in the chip row: focus the chip now at that position, else the search.
  const chips = dom.chipList.querySelectorAll('.chip');
  (chips[Math.min(position, chips.length - 1)] || dom.search).focus();
}

function renderChips() {
  const entries = activeFilterEntries();

  clearChildren(dom.chipList);
  dom.chips.hidden = entries.length === 0 || dom.form.hidden;

  entries.forEach(({ key, label, value }, position) => {
    const chip = el('button', {
      className: 'chip',
      attrs: { type: 'button', 'aria-label': `Remove filter ${label}: ${value}` },
    }, [
      el('span', { className: 'chip-key', text: label }),
      el('span', { className: 'chip-value', text: value }),
      icon('close', 'icon chip-icon'),
    ]);

    chip.addEventListener('click', () => removeFilter(key, position));
    dom.chipList.append(el('li', {}, [chip]));
  });
}

/* Lifecycle stages ---------------------------------------------------------------------- */

function renderStages() {
  const counts = tally(alerts.map((alert) => alert.status));

  dom.stages.forEach((stage) => {
    const status = stage.dataset.status;
    stage.querySelector('[data-stage-count]').textContent = formatCount(counts.get(status) || 0);
    stage.setAttribute('aria-pressed', String(filters.status === status));
  });
}

function resetStages(placeholder) {
  dom.stages.forEach((stage) => {
    stage.querySelector('[data-stage-count]').textContent = placeholder;
    stage.setAttribute('aria-pressed', 'false');
  });
}

/* Queue rows ------------------------------------------------------------------------------ */

function timeNode(value, fallback) {
  const datetime = toDateTimeAttribute(value);

  return datetime
    ? el('time', { text: formatDateTime(value), attrs: { datetime } })
    : el('span', { className: 'is-missing', text: fallback });
}

function detailBlock(title, children) {
  return el('section', { className: 'detail-block' }, [
    el('h3', { className: 'detail-title', text: title }),
    ...children,
  ]);
}

function lifecycleStep(label, value, missingLabel) {
  const reached = Boolean(toDateTimeAttribute(value));

  return el('li', { className: `timeline-step${reached ? ' is-reached' : ''}` }, [
    el('span', { className: 'timeline-label', text: label }),
    timeNode(value, missingLabel),
  ]);
}

function identifier(label, value) {
  return [
    el('dt', { text: label }),
    el('dd', { className: value ? 'mono' : 'is-missing', text: value || '—' }),
  ];
}

function createDetail(alert, detailId) {
  const description = text(alert.description);
  const signature = text(alert.signature);
  const assigned = Number.isInteger(alert.assigned_to) ? `User #${alert.assigned_to}` : '';

  const content = el('div', { className: 'alert-detail' }, [
    el('div', { className: 'detail-column' }, [
      detailBlock('Description', [
        el('p', { className: description ? 'detail-text' : 'detail-text is-missing', text: description || 'No description provided.' }),
      ]),
      detailBlock('Signature', [
        el('p', { className: signature ? 'detail-code' : 'detail-text is-missing', text: signature || 'No signature recorded.' }),
      ]),
    ]),
    el('div', { className: 'detail-column' }, [
      detailBlock('Lifecycle', [
        el('ol', { className: 'timeline' }, [
          lifecycleStep('Detected', alert.detected_at, 'Time unknown'),
          lifecycleStep('Acknowledged', alert.acknowledged_at, 'Not acknowledged'),
          lifecycleStep('Resolved', alert.resolved_at, 'Not resolved'),
        ]),
      ]),
      detailBlock('Identifiers', [
        el('dl', { className: 'identifiers' }, [
          ...identifier('Alert ID', text(alert.alert_uuid)),
          ...identifier('Alert type', text(alert.alert_type)),
          ...identifier('Linked event', Number.isInteger(alert.event_id) ? `#${alert.event_id}` : ''),
          ...identifier('Linked device', Number.isInteger(alert.device_id) ? `#${alert.device_id}` : ''),
          ...identifier('Assigned to', assigned),
        ]),
      ]),
    ]),
  ]);

  const row = el('tr', {
    className: 'alert-detail-row',
    attrs: { id: detailId, role: 'row' },
  }, [
    el('td', { attrs: { colspan: COLUMN_COUNT, role: 'cell' } }, [content]),
  ]);
  row.hidden = true;

  return row;
}

function toggleDetail(button, row, detailRow) {
  const expanded = button.getAttribute('aria-expanded') !== 'true';

  button.setAttribute('aria-expanded', String(expanded));
  row.classList.toggle('is-expanded', expanded);
  detailRow.hidden = !expanded;
}

function createRows(alert, index) {
  // Ids come from the render position, never from API values.
  const detailId = `alert-detail-${index}`;
  const title = text(alert.title) || 'Untitled alert';
  const source = text(alert.source);
  const alertType = text(alert.alert_type);
  const category = text(alert.category);

  const toggle = el('button', {
    className: 'alert-toggle',
    attrs: { type: 'button', 'aria-expanded': 'false', 'aria-controls': detailId },
  }, [
    icon('chevron', 'icon alert-chevron'),
    el('span', { className: 'alert-title', text: title }),
  ]);

  const origin = el('p', { className: 'alert-origin' });

  if (source) {
    origin.append(el('span', { className: 'alert-source', text: source }));
  }

  if (alertType) {
    origin.append(el('span', { className: 'alert-type', text: alertType }));
  }

  const row = el('tr', {
    className: `alert-row ${severityClass(alert.severity)}`,
    attrs: { role: 'row' },
  }, [
    el('td', { className: 'cell-severity', attrs: { role: 'cell' } }, [severityTag(alert.severity)]),
    el('td', { className: 'cell-alert', attrs: { role: 'cell' } }, [toggle, origin]),
    el('td', {
      className: category ? 'cell-category' : 'cell-category is-missing',
      text: category || 'Uncategorized',
      attrs: { role: 'cell' },
    }),
    el('td', { className: 'cell-status', attrs: { role: 'cell' } }, [statusTag(alert.status)]),
    el('td', { className: 'cell-detected', attrs: { role: 'cell' } }, [timeNode(alert.detected_at, 'Time unknown')]),
  ]);

  const detailRow = createDetail(alert, detailId);
  toggle.addEventListener('click', () => toggleDetail(toggle, row, detailRow));

  return [row, detailRow];
}

function stateRow(content) {
  return el('tr', { attrs: { role: 'row' } }, [
    el('td', { className: 'cell-state', attrs: { colspan: COLUMN_COUNT, role: 'cell' } }, [content]),
  ]);
}

/* Queue views ------------------------------------------------------------------------------ */

function renderLoading() {
  showView('table');
  dom.body.setAttribute('aria-busy', 'true');
  dom.count.textContent = '—';
  dom.summary.textContent = 'Loading alerts…';
  clearChildren(dom.body);

  for (let index = 0; index < LOADING_ROWS; index += 1) {
    dom.body.append(el('tr', { className: 'alert-row is-skeleton', attrs: { 'aria-hidden': 'true' } }, [
      el('td', { className: 'cell-severity' }, [el('span', { className: 'skeleton skeleton-tag' })]),
      el('td', { className: 'cell-alert' }, [
        el('span', { className: 'skeleton skeleton-title' }),
        el('span', { className: 'skeleton skeleton-line' }),
      ]),
      el('td', { className: 'cell-category' }, [el('span', { className: 'skeleton skeleton-line' })]),
      el('td', { className: 'cell-status' }, [el('span', { className: 'skeleton skeleton-line' })]),
      el('td', { className: 'cell-detected' }, [el('span', { className: 'skeleton skeleton-line' })]),
    ]));
  }
}

function renderQueue() {
  const matching = sortAlerts(alerts.filter(matches));
  const shown = matching.slice(0, limit);
  const total = alerts.length;

  showView('table');
  dom.body.setAttribute('aria-busy', 'false');
  clearChildren(dom.body);
  renderChips();

  dom.count.textContent = matching.length === total
    ? plural(total, 'alert')
    : `${formatCount(matching.length)} of ${formatCount(total)}`;

  dom.summary.textContent = hasActiveFilters()
    ? `${plural(matching.length, 'alert')} match the current filters.`
    : `All ${plural(total, 'alert')} returned by the API.`;

  if (matching.length === 0) {
    dom.footer.hidden = true;
    dom.body.append(stateRow(stateBlock({
      variant: 'empty',
      iconName: 'search',
      title: 'No alerts match these filters',
      message: `None of the ${plural(total, 'alert')} returned by the API match. Change or clear the filters to see them.`,
      action: { label: 'Clear filters', onClick: clearFilters, icon: null },
    })));
    return 0;
  }

  shown.forEach((alert, index) => dom.body.append(...createRows(alert, index)));

  const remaining = matching.length - shown.length;
  dom.footer.hidden = remaining <= 0 && matching.length <= PAGE_SIZE;
  dom.range.textContent = `Showing ${formatCount(shown.length)} of ${plural(matching.length, 'alert')}`;
  dom.more.hidden = remaining <= 0;
  dom.more.textContent = `Show ${formatCount(Math.min(PAGE_SIZE, remaining))} more`;

  return matching.length;
}

/**
 * Empty queue: explains where alerts come from, with the real totals from /api/metrics.
 * `metrics` is null when the totals could not be loaded; nothing is guessed in that case.
 */
function intakeContext(metrics) {
  if (!metrics) {
    return 'Event and alert totals are unavailable right now, so the reason cannot be shown.';
  }

  if (metrics.events === 0) {
    return 'No security events have been recorded yet, so there is nothing to raise alerts from.';
  }

  if (metrics.alerts === 0) {
    return `${plural(metrics.events, 'security event')} recorded so far, and none has raised an alert.`;
  }

  return `Metrics report ${plural(metrics.alerts, 'alert')}, but none were returned to this page. Check again in a moment.`;
}

function intakeStep(count, label, note, reached) {
  return el('li', { className: `intake-step${reached ? ' is-reached' : ''}` }, [
    el('span', { className: 'intake-node', attrs: { 'aria-hidden': 'true' } }),
    el('span', { className: 'intake-count', text: count === null ? '—' : formatCount(count) }),
    el('span', { className: 'intake-label', text: label }),
    el('span', { className: 'intake-note', text: note }),
  ]);
}

function setEmptySummary() {
  dom.summary.textContent = hasActiveFilters()
    ? 'The API returned no alerts. Your filters will apply as soon as alerts arrive.'
    : 'The API returned no alerts.';
}

function renderEmpty(metrics) {
  showView('empty');
  clearChildren(dom.empty);

  dom.count.textContent = '0 alerts';
  setEmptySummary();
  renderChips();

  const checkAgain = el('button', { className: 'button', attrs: { type: 'button' } }, [
    icon('refresh'),
    el('span', { text: 'Check again' }),
  ]);
  checkAgain.addEventListener('click', retryFromView);

  const events = metrics ? metrics.events : null;
  const raised = metrics ? metrics.alerts : null;

  dom.empty.append(
    el('div', { className: 'intake' }, [
      el('div', { className: 'intake-head' }, [
        el('span', { className: 'state-icon' }, [icon('orbit')]),
        el('h3', { className: 'intake-title', text: 'No alerts yet' }),
        el('p', {
          className: 'intake-text',
          text: 'Alerts are raised from recorded security events by the campus detection sources. They land in this queue for review as soon as the API reports them.',
        }),
      ]),
      el('ol', { className: 'intake-flow', attrs: { 'aria-label': 'How alerts reach this queue' } }, [
        intakeStep(events, 'Security events recorded', 'Stored by CYBERGUARD CAMPUS', events !== null && events > 0),
        intakeStep(raised, 'Alerts raised', 'From events matched by detection', raised !== null && raised > 0),
        intakeStep(0, 'In this queue', 'Ready for review', false),
      ]),
      el('p', { className: 'intake-context', text: intakeContext(metrics) }),
      el('div', { className: 'intake-actions' }, [
        checkAgain,
        el('a', { className: 'button', attrs: { href: EVENTS_PAGE } }, [
          icon('events'),
          el('span', { text: 'Open security events' }),
        ]),
      ]),
    ]),
  );
}

function renderError(error) {
  const description = describeError(error, 'alerts');
  let action;

  if (description.kind === 'unauthorized') {
    action = { label: 'Sign in', href: LOGIN_PAGE };
  } else if (description.kind !== 'forbidden') {
    // Retrying cannot change a permission decision, so 403 offers no retry.
    action = { label: 'Retry', onClick: retryFromView };
  }

  showView('table');
  dom.body.setAttribute('aria-busy', 'false');
  dom.count.textContent = '—';
  dom.summary.textContent = description.title;
  clearChildren(dom.body);
  dom.body.append(stateRow(stateBlock({
    variant: 'error',
    iconName: description.kind === 'unauthorized' || description.kind === 'forbidden' ? 'lock' : 'warning',
    title: description.title,
    message: description.message,
    action,
  })));
}

/* Detection rhythm (weekday × hour, from the alerts in the current view) -------------------- */

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const WEEKDAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const HOURS = Array.from({ length: 24 }, (_, hour) => String(hour).padStart(2, '0'));

function renderRhythm(list) {
  const values = WEEKDAYS.map(() => HOURS.map(() => 0));
  let plotted = 0;

  list.forEach((alert) => {
    const date = parseApiDate(alert.detected_at);

    if (date) {
      values[(date.getDay() + 6) % 7][date.getHours()] += 1;
      plotted += 1;
    }
  });

  const undated = list.length - plotted;
  const notes = [plotted === 0
    ? (alerts.length === 0 ? 'No alerts yet: the grid fills in as alerts are detected.' : 'No alerts in the current view.')
    : `${plural(plotted, 'alert')} plotted${hasActiveFilters() ? ' for the current filters' : ''}.`];

  if (undated > 0) {
    notes.push(`${plural(undated, 'alert')} without a valid detection time ${undated === 1 ? 'is' : 'are'} not plotted.`);
  }

  dom.rhythm.setAttribute('aria-busy', 'false');
  clearChildren(dom.rhythm);
  dom.rhythm.append(
    el('p', { className: 'chart-note', text: notes.join(' ') }),
    heatGrid({
      caption: 'Alert detections by weekday and hour, local time',
      rows: WEEKDAYS,
      cols: HOURS,
      values,
      unit: 'alert',
      colTicks: [0, 6, 12, 18],
      describe: (r, c) => `${WEEKDAY_NAMES[r]}, ${HOURS[c]}:00–${HOURS[c]}:59`,
    }),
  );
}

function renderRhythmState(mode) {
  dom.rhythm.setAttribute('aria-busy', String(mode === 'loading'));
  clearChildren(dom.rhythm);
  dom.rhythm.append(mode === 'loading'
    ? el('span', { className: 'skeleton chart-skeleton', attrs: { 'aria-hidden': 'true' } })
    : el('p', { className: 'chart-note', text: 'Unavailable until alerts can be loaded.' }));
}

/* Interaction -------------------------------------------------------------------------------- */

function applyFilters({ announceResult = false } = {}) {
  limit = PAGE_SIZE;
  writeFiltersToUrl();
  renderStages();

  renderRhythm(alerts.filter(matches));

  if (alerts.length === 0) {
    // Nothing to filter yet: keep the intake view, reflect the filters, say so.
    renderChips();
    setEmptySummary();

    if (announceResult) {
      announce('No alerts to filter yet. The filters will apply as soon as alerts arrive.', { delayed: true });
    }

    return;
  }

  const count = renderQueue();

  if (announceResult) {
    announce(`${plural(count, 'alert')} shown.`, { delayed: true });
  }
}

function retryFromView() {
  // The button that triggered this is removed by the reload: keep focus on a stable control.
  dom.refresh.focus();
  loadAlerts();
}

dom.form.addEventListener('submit', (event) => event.preventDefault());

dom.search.addEventListener('input', () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    filters.search = dom.search.value.trim().slice(0, MAX_PARAM_LENGTH);
    applyFilters({ announceResult: true });
  }, SEARCH_DELAY_MS);
});

[
  [dom.severity, 'severity'],
  [dom.status, 'status'],
  [dom.detected, 'range'],
  [dom.category, 'category'],
  [dom.source, 'source'],
  [dom.sort, 'sort'],
].forEach(([select, key]) => {
  select.addEventListener('change', () => {
    filters[key] = select.value;
    applyFilters({ announceResult: true });
  });
});

dom.clear.addEventListener('click', clearFilters);

dom.stages.forEach((stage) => {
  stage.addEventListener('click', () => {
    const status = stage.dataset.status;

    if (!ALERT_STATUSES.includes(status)) {
      return;
    }

    filters.status = filters.status === status ? '' : status;
    dom.status.value = filters.status;
    applyFilters({ announceResult: true });
  });
});

dom.more.addEventListener('click', () => {
  limit += PAGE_SIZE;
  renderQueue();
  announce(dom.range.textContent);
});

// "/" jumps to the search field, unless the user is already typing somewhere.
document.addEventListener('keydown', (event) => {
  const target = event.target;
  const typing = target instanceof HTMLElement
    && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

  if (event.key !== '/' || typing || event.ctrlKey || event.metaKey || event.altKey) {
    return;
  }

  if (!dom.form.hidden && !dom.fieldset.disabled) {
    event.preventDefault();
    dom.search.focus();
    dom.search.select();
  }
});

/* Load cycle ------------------------------------------------------------------------------------ */

async function loadMetricsSummary() {
  try {
    const metrics = await getMetrics();
    const events = toCount(metrics && metrics.total_events);
    const raised = toCount(metrics && metrics.total_alerts);

    return events === null || raised === null ? null : { events, alerts: raised };
  } catch {
    // The totals only add context to the empty queue; their absence is stated, not hidden.
    return null;
  }
}

async function loadAlerts() {
  if (isLoading) {
    return;
  }

  const hadAlerts = alerts.length > 0;

  setBusy(true);
  setFilterBar(hadAlerts ? 'paused' : 'hidden');
  setStagesEnabled(false);
  setSyncStatus('Loading…', 'loading');
  announce('Loading alerts.');
  resetStages('—');
  renderLoading();
  renderRhythmState('loading');

  let result;

  try {
    result = await getAlerts();

    if (!Array.isArray(result)) {
      throw new ApiError('The API returned alerts in an unexpected format.', { status: 200, kind: 'invalid-response' });
    }
  } catch (error) {
    alerts = [];
    setFilterBar('hidden');
    renderError(error);
    renderRhythmState('error');
    setSyncStatus(`Update failed ${formatClockTime(new Date())}`, 'error');
    announce(describeError(error, 'alerts').title);
    setBusy(false);
    return;
  }

  alerts = result;
  limit = PAGE_SIZE;

  populateFilterOptions();
  syncControls();
  setFilterBar('ready');
  setStagesEnabled(true);
  writeFiltersToUrl();

  renderRhythm(alerts.filter(matches));

  if (alerts.length === 0) {
    const metrics = await loadMetricsSummary();
    renderStages();
    renderEmpty(metrics);
    setSyncStatus(`Updated ${formatClockTime(new Date())}`, 'ready');
    announce('No alerts returned by the API.');
    setBusy(false);
    return;
  }

  renderStages();
  renderQueue();
  setSyncStatus(`Updated ${formatClockTime(new Date())}`, 'ready');
  announce(`${plural(alerts.length, 'alert')} loaded.`);
  setBusy(false);
}

dom.refresh.addEventListener('click', loadAlerts);

readFiltersFromUrl();
loadAlerts();
