/**
 * CYBERGUARD CAMPUS — Devices (Network Asset Visibility).
 * Data: GET /api/devices only (viewer, analyst, admin). No detail, stats or write endpoint exists.
 * Search, filters, sorting and pagination are client-side over the list the API returns.
 *
 * Two facts are kept apart on purpose:
 *  - `status`       — the status recorded in the inventory (online / degraded / offline / unknown);
 *  - `last_seen_at` — the most recent observation. Neither is a live connection check.
 *
 * Pipeline: loadDevices → normalizeDevice → filterDevices → sortDevices → paginate → render*.
 */

import { ApiError, getDevices } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { HEAT_RAMP, heatStep, scaleLegend } from '../charts.js';
import {
  DEVICE_STATUSES,
  clearChildren,
  describeError,
  deviceStatusKey,
  deviceStatusTag,
  el,
  formatClockTime,
  formatCount,
  formatDateTime,
  formatDeviceStatus,
  formatRelativeTime,
  humanize,
  icon,
  parseApiDate,
  stateBlock,
  toDateTimeAttribute,
} from '../format.js';

const PAGE_SIZE = 50;
const LOADING_ROWS = 6;
const COLUMN_COUNT = 7;
const SEARCH_DELAY_MS = 150;
const ANNOUNCE_DELAY_MS = 450;
const MAX_PARAM_LENGTH = 120;
const DAY_MS = 24 * 60 * 60 * 1000;
const NOT_RECORDED = 'Not recorded';

const SORTS = ['recent', 'oldest', 'name-asc', 'name-desc', 'ip', 'status'];
// "Status" sort puts what needs attention first.
const STATUS_ORDER = ['offline', 'degraded', 'unknown', 'online'];

const SEEN_BUCKETS = [
  { key: 'day', label: 'Within 24 hours' },
  { key: 'week', label: '1 to 7 days' },
  { key: 'older', label: 'Over 7 days' },
  { key: 'never', label: 'Not recorded' },
];

// Details beside the inventory on wide screens, in a modal dialog otherwise.
const SPLIT_QUERY = window.matchMedia('(min-width: 1280px)');

const dom = {
  refresh: document.getElementById('devices-refresh'),
  syncStatus: document.getElementById('sync-status'),
  syncText: document.getElementById('sync-status-text'),
  announcer: document.getElementById('devices-announcer'),
  total: document.getElementById('devices-total'),
  bands: {
    status: document.getElementById('band-status'),
    seen: document.getElementById('band-seen'),
  },
  bandItems: Array.from(document.querySelectorAll('.band-item[data-filter]')),
  grid: document.getElementById('inventory-grid'),
  title: document.getElementById('inventory-title'),
  summary: document.getElementById('inventory-summary'),
  count: document.getElementById('inventory-count'),
  form: document.getElementById('inventory-filters'),
  fieldset: document.getElementById('inventory-filters-set'),
  search: document.getElementById('filter-search'),
  status: document.getElementById('filter-status'),
  type: document.getElementById('filter-type'),
  environment: document.getElementById('filter-environment'),
  sort: document.getElementById('filter-sort'),
  filtersFooter: document.getElementById('filters-footer'),
  clear: document.getElementById('filters-clear'),
  body: document.getElementById('inventory-body'),
  pagination: document.getElementById('pagination'),
  range: document.getElementById('pagination-range'),
  pages: document.getElementById('pagination-pages'),
  prev: document.getElementById('page-prev'),
  next: document.getElementById('page-next'),
  panel: document.getElementById('device-panel'),
  dialog: document.getElementById('device-dialog'),
  dialogBody: document.getElementById('device-dialog-body'),
  dialogClose: document.getElementById('device-dialog-close'),
  composition: document.getElementById('composition-body'),
};

/** All page state lives here. */
const state = {
  devices: [],
  skipped: 0,
  answered: false,
  loading: false,
  now: Date.now(),
  page: 1,
  selectedId: null,
  filters: { q: '', status: '', type: '', environment: '', seen: '', sort: 'recent' },
  timers: { search: 0, announce: 0 },
};

/* Helpers -------------------------------------------------------------------------- */

function text(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function plural(count, word) {
  return `${formatCount(count)} ${count === 1 ? word : `${word}s`}`;
}

function announce(message, { delayed = false } = {}) {
  window.clearTimeout(state.timers.announce);

  if (delayed) {
    state.timers.announce = window.setTimeout(() => {
      dom.announcer.textContent = message;
    }, ANNOUNCE_DELAY_MS);
  } else {
    dom.announcer.textContent = message;
  }
}

function setSyncStatus(message, syncState) {
  dom.syncText.textContent = message;
  dom.syncStatus.dataset.state = syncState;
}

// aria-disabled (not `disabled`) keeps keyboard focus on the button while loading.
function setBusy(busy) {
  state.loading = busy;
  dom.refresh.setAttribute('aria-disabled', String(busy));
  dom.refresh.setAttribute('aria-busy', String(busy));
}

function valueOrMissing(value, { mono = false } = {}) {
  const content = text(value);

  return el('span', {
    className: content ? (mono ? 'mono' : '') : 'is-missing',
    text: content || NOT_RECORDED,
  });
}

function timeNode(date, raw, className = '') {
  const datetime = toDateTimeAttribute(raw);

  return date && datetime
    ? el('time', { className, text: formatDateTime(raw), attrs: { datetime } })
    : el('span', { className: 'is-missing', text: NOT_RECORDED });
}

/* Normalisation (display only — raw API values are never rewritten) -------------------- */

function seenBucket(lastSeen, now) {
  if (!lastSeen) {
    return 'never';
  }

  const age = now - lastSeen.getTime();

  // A date slightly in the future (clock skew) counts as recent.
  if (age <= DAY_MS) return 'day';
  if (age <= 7 * DAY_MS) return 'week';

  return 'older';
}

function normalizeDevice(raw, now) {
  const lastSeen = parseApiDate(raw.last_seen_at);
  const statusRaw = text(raw.status);

  const device = {
    id: raw.id,
    uuid: text(raw.uuid),
    hostname: text(raw.hostname),
    ip: text(raw.ip_address),
    mac: text(raw.mac_address),
    type: text(raw.device_type),
    vendor: text(raw.vendor),
    os: text(raw.operating_system),
    environment: text(raw.environment),
    statusKey: deviceStatusKey(raw.status),
    statusRaw,
    lastSeen,
    lastSeenRaw: raw.last_seen_at,
    createdRaw: raw.created_at,
    updatedRaw: raw.updated_at,
  };

  device.seen = seenBucket(lastSeen, now);
  device.haystack = [
    device.hostname, device.ip, device.mac, device.type, humanize(device.type), device.vendor, device.os,
    device.environment, statusRaw, formatDeviceStatus(raw.status), device.uuid, String(device.id), `#${device.id}`,
  ].join('\n').toLowerCase();

  return device;
}

function isDeviceRecord(value) {
  return Boolean(value) && typeof value === 'object' && Number.isSafeInteger(value.id) && value.id > 0;
}

/* Filtering ------------------------------------------------------------------------------- */

function searchTerms(query) {
  return query.toLowerCase().split(/\s+/).filter(Boolean);
}

function filterDevices(devices, filters) {
  const terms = searchTerms(filters.q);

  return devices.filter((device) => (
    (!filters.status || device.statusKey === filters.status)
    && (!filters.type || device.type === filters.type)
    && (!filters.environment || device.environment === filters.environment)
    && (!filters.seen || device.seen === filters.seen)
    && terms.every((term) => device.haystack.includes(term))
  ));
}

function hasActiveFilters(filters = state.filters) {
  return Boolean(filters.q || filters.status || filters.type || filters.environment || filters.seen);
}

/* Sorting ----------------------------------------------------------------------------------- */

const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

/** IPv4 sorts numerically (10.0.0.9 before 10.0.0.10), then IPv6/other as text, then missing. */
function ipSortKey(ip) {
  const match = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(ip);

  if (match) {
    const octets = match.slice(1).map(Number);

    if (octets.every((octet) => octet <= 255)) {
      return [0, octets.reduce((sum, octet) => sum * 256 + octet, 0), ''];
    }
  }

  return ip ? [1, 0, ip.toLowerCase()] : [2, 0, ''];
}

function compareIp(a, b) {
  const [groupA, numA, textA] = ipSortKey(a.ip);
  const [groupB, numB, textB] = ipSortKey(b.ip);

  return groupA - groupB || numA - numB || collator.compare(textA, textB);
}

function compareHostname(a, b) {
  // Devices without a hostname always go last.
  return (a.hostname === '') - (b.hostname === '') || collator.compare(a.hostname, b.hostname);
}

/**
 * Last-seen order. Devices never observed have nothing to compare, so they stay last
 * in both directions; ties fall back to the hostname.
 */
function compareSeen(a, b, direction) {
  if (!a.lastSeen || !b.lastSeen) {
    return (!a.lastSeen) - (!b.lastSeen) || compareHostname(a, b);
  }

  return direction * (a.lastSeen.getTime() - b.lastSeen.getTime()) || compareHostname(a, b);
}

function sortDevices(devices, sort) {
  const comparators = {
    recent: (a, b) => compareSeen(a, b, -1),
    oldest: (a, b) => compareSeen(a, b, 1),
    'name-asc': compareHostname,
    'name-desc': (a, b) => (a.hostname === '') - (b.hostname === '') || collator.compare(b.hostname, a.hostname),
    ip: (a, b) => compareIp(a, b) || compareHostname(a, b),
    status: (a, b) => STATUS_ORDER.indexOf(a.statusKey) - STATUS_ORDER.indexOf(b.statusKey) || compareHostname(a, b),
  };

  return [...devices].sort(comparators[sort] || comparators.recent);
}

/* Pagination ------------------------------------------------------------------------------- */

function paginate(devices, page) {
  const pageCount = Math.max(1, Math.ceil(devices.length / PAGE_SIZE));
  // A narrower filter can remove the current page: fall back to the last existing one.
  const current = Math.min(Math.max(1, page), pageCount);
  const start = (current - 1) * PAGE_SIZE;

  return { page: current, pageCount, start, items: devices.slice(start, start + PAGE_SIZE) };
}

/** Page numbers to show: first, last, current ±1, with gaps marked as null. */
function pageWindow(page, pageCount) {
  const wanted = new Set([1, pageCount, page - 1, page, page + 1]);
  const pages = Array.from(wanted).filter((n) => n >= 1 && n <= pageCount).sort((a, b) => a - b);
  const result = [];

  pages.forEach((n, index) => {
    if (index > 0 && n - pages[index - 1] > 1) {
      result.push(null);
    }
    result.push(n);
  });

  return result;
}

/* URL state (validated, never trusted) ------------------------------------------------------ */

function readUrlState() {
  const params = new URLSearchParams(window.location.search);
  const clip = (value) => (value || '').trim().slice(0, MAX_PARAM_LENGTH);
  const status = params.get('status');
  const seen = params.get('seen');
  const sort = params.get('sort');
  const page = params.get('page');
  const device = params.get('device');

  state.filters.q = clip(params.get('q'));
  state.filters.status = DEVICE_STATUSES.includes(status) ? status : '';
  state.filters.seen = SEEN_BUCKETS.some((bucket) => bucket.key === seen) ? seen : '';
  state.filters.sort = SORTS.includes(sort) ? sort : 'recent';
  // Type and environment are kept only if they exist in the loaded data (see fillSelect).
  state.filters.type = clip(params.get('type'));
  state.filters.environment = clip(params.get('env'));
  state.page = /^[1-9]\d{0,5}$/.test(page || '') ? Number(page) : 1;
  state.selectedId = /^[1-9]\d{0,15}$/.test(device || '') ? Number(device) : null;
}

function writeUrlState() {
  const { filters } = state;
  const params = new URLSearchParams();

  if (filters.q) params.set('q', filters.q);
  if (filters.status) params.set('status', filters.status);
  if (filters.type) params.set('type', filters.type);
  if (filters.environment) params.set('env', filters.environment);
  if (filters.seen) params.set('seen', filters.seen);
  if (filters.sort !== 'recent') params.set('sort', filters.sort);
  if (state.page > 1) params.set('page', String(state.page));
  if (state.selectedId !== null) params.set('device', String(state.selectedId));

  const query = params.toString();
  window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
}

/* Filter controls --------------------------------------------------------------------------- */

function tally(values) {
  const counts = new Map();

  values.forEach((value) => counts.set(value, (counts.get(value) || 0) + 1));

  return counts;
}

function fillSelect(select, entries, selected) {
  clearChildren(select);
  select.append(el('option', { text: 'All', attrs: { value: '' } }));

  entries.forEach(({ value, label, count }) => {
    const option = el('option', { text: `${label} (${formatCount(count)})` });
    option.value = value;
    select.append(option);
  });

  select.value = entries.some((entry) => entry.value === selected) ? selected : '';

  return select.value;
}

/** Options come from the data itself (types and environments), except the status contract. */
function populateFilterOptions() {
  const { devices, filters } = state;
  const byLabel = (a, b) => collator.compare(a.label, b.label);

  const statusCounts = tally(devices.map((device) => device.statusKey));
  filters.status = fillSelect(dom.status, DEVICE_STATUSES.map((status) => ({
    value: status, label: formatDeviceStatus(status), count: statusCounts.get(status) || 0,
  })), filters.status);

  const typeCounts = tally(devices.map((device) => device.type).filter(Boolean));
  filters.type = fillSelect(dom.type, Array.from(typeCounts, ([value, count]) => ({
    value, label: humanize(value), count,
  })).sort(byLabel), filters.type);

  const environmentCounts = tally(devices.map((device) => device.environment).filter(Boolean));
  filters.environment = fillSelect(dom.environment, Array.from(environmentCounts, ([value, count]) => ({
    value, label: humanize(value), count,
  })).sort(byLabel), filters.environment);

  dom.sort.value = filters.sort;
}

function syncControls() {
  const { filters } = state;
  dom.search.value = filters.q;
  dom.status.value = filters.status;
  dom.type.value = filters.type;
  dom.environment.value = filters.environment;
  dom.sort.value = filters.sort;
}

function setFilterBar(mode) {
  dom.form.hidden = mode === 'hidden';
  dom.fieldset.disabled = mode !== 'ready';
  dom.bandItems.forEach((item) => {
    item.disabled = mode !== 'ready';
  });
}

function clearFilters() {
  Object.assign(state.filters, { q: '', status: '', type: '', environment: '', seen: '' });
  state.page = 1;
  syncControls();
  update({ announceResult: true });
  dom.search.focus();
}

/* Overview bands ------------------------------------------------------------------------------ */

function renderBand(band, entries, name) {
  const bar = band.querySelector('[data-band-bar]');
  const total = entries.reduce((sum, entry) => sum + entry.count, 0);

  clearChildren(bar);
  entries.forEach((entry) => {
    if (entry.count > 0) {
      const segment = el('span', { className: 'band-segment', attrs: { 'data-value': entry.key } });
      segment.style.flexGrow = String(entry.count);
      bar.append(segment);
    }
  });

  bar.setAttribute('aria-label', total === 0
    ? `${name}: no devices`
    : `${name}: ${entries.map((entry) => `${entry.label} ${entry.count}`).join(', ')}`);
}

function renderOverview() {
  const { devices, filters } = state;
  const statusCounts = tally(devices.map((device) => device.statusKey));
  const seenCounts = tally(devices.map((device) => device.seen));

  dom.total.textContent = formatCount(devices.length);

  renderBand(dom.bands.status, DEVICE_STATUSES.map((status) => ({
    key: status, label: formatDeviceStatus(status), count: statusCounts.get(status) || 0,
  })), 'Recorded status');

  renderBand(dom.bands.seen, SEEN_BUCKETS.map((bucket) => ({
    key: bucket.key, label: bucket.label, count: seenCounts.get(bucket.key) || 0,
  })), 'Last observation');

  dom.bandItems.forEach((item) => {
    const counts = item.dataset.filter === 'status' ? statusCounts : seenCounts;
    item.querySelector('[data-count]').textContent = formatCount(counts.get(item.dataset.value) || 0);
    item.setAttribute('aria-pressed', String(filters[item.dataset.filter] === item.dataset.value));
  });

  renderComposition();
}

/* Inventory composition: type × environment matrix (counts from the loaded devices) ------- */

function renderComposition() {
  const { devices, filters } = state;
  const body = dom.composition;

  body.setAttribute('aria-busy', 'false');
  clearChildren(body);

  if (devices.length === 0) {
    body.append(el('p', { className: 'chart-note', text: 'No devices yet: the matrix fills in as devices become known.' }));
    return;
  }

  const typeTotals = tally(devices.map((device) => device.type || ''));
  const types = Array.from(typeTotals.keys()).sort((a, b) => typeTotals.get(b) - typeTotals.get(a) || collator.compare(a, b));
  const environments = Array.from(new Set(devices.map((device) => device.environment || ''))).sort((a, b) => collator.compare(a, b));
  const cellCount = (type, env) => devices.filter((device) => (device.type || '') === type && (device.environment || '') === env).length;
  const max = Math.max(...types.flatMap((type) => environments.map((env) => cellCount(type, env))));
  const label = (value) => humanize(value) || NOT_RECORDED;

  const head = el('tr', {}, [
    el('th', { className: 'composition-corner', text: 'Type', attrs: { scope: 'col' } }),
    ...environments.map((env) => el('th', { text: label(env), attrs: { scope: 'col' } })),
    el('th', { className: 'composition-total', text: 'Total', attrs: { scope: 'col' } }),
  ]);

  const rows = types.map((type) => el('tr', {}, [
    el('th', { text: label(type), attrs: { scope: 'row' } }),
    ...environments.map((env) => {
      const count = cellCount(type, env);
      const step = heatStep(count, max);

      if (count === 0) {
        return el('td', { className: 'composition-cell is-zero' }, [el('span', { text: '0', attrs: { 'aria-label': `No ${label(type)} devices in ${label(env)}` } })]);
      }

      const active = filters.type === type && filters.environment === env;
      const button = el('button', {
        className: `composition-button${step >= 2 ? ' is-light' : ''}`,
        text: formatCount(count),
        attrs: {
          type: 'button',
          'aria-pressed': String(active),
          'aria-label': `${plural(count, 'device')}: ${label(type)} in ${label(env)}. Filter the inventory`,
        },
      });
      button.style.setProperty('--heat', HEAT_RAMP[step]);
      button.addEventListener('click', () => {
        const same = state.filters.type === type && state.filters.environment === env;
        state.filters.type = same ? '' : type;
        state.filters.environment = same ? '' : env;
        state.page = 1;
        syncControls();
        update({ announceResult: true });
      });

      return el('td', { className: 'composition-cell' }, [button]);
    }),
    el('td', { className: 'composition-total mono', text: formatCount(typeTotals.get(type)) }),
  ]));

  body.append(
    el('div', { className: 'table-scroll composition-scroll' }, [
      el('table', { className: 'composition-table', attrs: { 'aria-labelledby': 'composition-title' } }, [
        el('thead', {}, [head]),
        el('tbody', {}, rows),
      ]),
    ]),
    scaleLegend(max, 'device'),
  );
}

function renderCompositionState(mode) {
  dom.composition.setAttribute('aria-busy', String(mode === 'loading'));
  clearChildren(dom.composition);
  dom.composition.append(mode === 'loading'
    ? el('span', { className: 'skeleton composition-skeleton', attrs: { 'aria-hidden': 'true' } })
    : el('p', { className: 'chart-note', text: 'Unavailable until devices can be loaded.' }));
}

function resetOverview() {
  dom.total.textContent = '—';
  Object.values(dom.bands).forEach((band) => clearChildren(band.querySelector('[data-band-bar]')));
  dom.bandItems.forEach((item) => {
    item.querySelector('[data-count]').textContent = '—';
    item.setAttribute('aria-pressed', 'false');
  });
}

/* Inventory ---------------------------------------------------------------------------------- */

function lastSeenCell(device) {
  const cell = el('td', { className: 'cell-seen', attrs: { role: 'cell' } }, [
    timeNode(device.lastSeen, device.lastSeenRaw, 'seen-absolute'),
  ]);
  const relative = formatRelativeTime(device.lastSeenRaw, state.now);

  if (device.lastSeen && relative) {
    cell.append(el('span', { className: 'seen-relative', text: relative }));
  }

  return cell;
}

function createRow(device) {
  const link = el('button', {
    className: 'device-link',
    attrs: { type: 'button', 'data-device-id': device.id },
  }, [
    el('span', { className: device.hostname ? 'device-name' : 'device-name is-missing', text: device.hostname || 'Unnamed device' }),
    el('span', { className: 'device-id', text: `#${device.id}` }),
  ]);
  link.addEventListener('click', () => selectDevice(device.id));

  const cell = (children, className = '') => el('td', { className, attrs: { role: 'cell' } }, children);

  return el('tr', { className: `device-row status-${device.statusKey}`, attrs: { role: 'row' } }, [
    cell([link], 'cell-device'),
    cell([valueOrMissing(device.ip, { mono: true })], 'cell-ip'),
    cell([
      el('span', { className: device.type ? '' : 'is-missing', text: humanize(device.type) || NOT_RECORDED }),
      el('span', { className: 'cell-sub', text: humanize(device.environment) }),
    ], 'cell-type'),
    cell([valueOrMissing(device.vendor)], 'cell-vendor col-secondary'),
    cell([valueOrMissing(device.os)], 'cell-os col-secondary'),
    cell([deviceStatusTag(device.statusRaw)], 'cell-status'),
    lastSeenCell(device),
  ]);
}

function inventoryTable(rows) {
  const headers = ['Device', 'IP address', 'Type', 'Vendor', 'Operating system', 'Status', 'Last seen'];
  const secondary = new Set(['Vendor', 'Operating system']);

  return el('div', { className: 'table-scroll' }, [
    el('table', { className: 'table inventory-table', attrs: { role: 'table', 'aria-labelledby': 'inventory-title' } }, [
      el('thead', { attrs: { role: 'rowgroup' } }, [
        el('tr', { attrs: { role: 'row' } }, headers.map((label) => el('th', {
          className: secondary.has(label) ? 'col-secondary' : '',
          text: label,
          attrs: { scope: 'col', role: 'columnheader' },
        }))),
      ]),
      el('tbody', { attrs: { role: 'rowgroup' } }, rows),
    ]),
  ]);
}

function renderLoading() {
  dom.body.setAttribute('aria-busy', 'true');
  dom.count.textContent = '—';
  dom.summary.textContent = 'Loading devices…';
  dom.pagination.hidden = true;
  clearChildren(dom.body);

  const rows = Array.from({ length: LOADING_ROWS }, () => el('tr', { className: 'device-row is-skeleton', attrs: { 'aria-hidden': 'true' } },
    Array.from({ length: COLUMN_COUNT }, (_, index) => el('td', { className: index === 3 || index === 4 ? 'col-secondary' : '' }, [
      el('span', { className: 'skeleton skeleton-cell' }),
    ]))));

  dom.body.append(inventoryTable(rows), el('p', { className: 'visually-hidden', text: 'Loading devices' }));
}

function renderPagination(result, total) {
  const { page, pageCount, start, items } = result;

  dom.pagination.hidden = total === 0;
  dom.range.textContent = `Showing ${formatCount(start + 1)}–${formatCount(start + items.length)} of ${plural(total, 'device')}`;
  dom.prev.disabled = page <= 1;
  dom.next.disabled = page >= pageCount;
  clearChildren(dom.pages);

  pageWindow(page, pageCount).forEach((n) => {
    if (n === null) {
      dom.pages.append(el('li', { className: 'pagination-gap', text: '…', attrs: { 'aria-hidden': 'true' } }));
      return;
    }

    const button = el('button', {
      className: 'pagination-page',
      text: formatCount(n),
      attrs: { type: 'button', 'aria-label': `Page ${n}` },
    });

    if (n === page) {
      button.setAttribute('aria-current', 'page');
    }

    button.addEventListener('click', () => goToPage(n));
    dom.pages.append(el('li', {}, [button]));
  });
}

function renderInventory() {
  const { devices, filters } = state;
  const matching = sortDevices(filterDevices(devices, filters), filters.sort);
  const result = paginate(matching, state.page);
  state.page = result.page;

  dom.body.setAttribute('aria-busy', 'false');
  dom.filtersFooter.hidden = !hasActiveFilters();
  clearChildren(dom.body);

  const skippedNote = state.skipped > 0
    ? ` ${formatCount(state.skipped)} ${state.skipped === 1 ? 'entry' : 'entries'} could not be displayed (unexpected format).`
    : '';

  if (devices.length === 0) {
    dom.count.textContent = '0 devices';
    dom.summary.textContent = (hasActiveFilters()
      ? 'The API returned no devices. Your filters will apply as soon as devices are known.'
      : 'The API returned no devices.') + skippedNote;
    dom.pagination.hidden = true;
    dom.body.append(stateBlock({
      variant: 'empty',
      iconName: 'devices',
      title: 'No devices discovered',
      message: 'The inventory is empty: no device has been registered or observed yet. Devices appear here once CYBERGUARD CAMPUS knows about them.',
      action: { label: 'Check again', onClick: retryFromView },
    }));
    return matching;
  }

  dom.count.textContent = matching.length === devices.length
    ? plural(devices.length, 'device')
    : `${formatCount(matching.length)} of ${formatCount(devices.length)}`;
  dom.summary.textContent = (hasActiveFilters()
    ? `${plural(matching.length, 'device')} match the current filters.`
    : `All ${plural(devices.length, 'device')} returned by the API.`) + skippedNote;

  if (matching.length === 0) {
    const searchOnly = filters.q && !filters.status && !filters.type && !filters.environment && !filters.seen;
    dom.pagination.hidden = true;
    dom.body.append(stateBlock({
      variant: 'empty',
      iconName: 'search',
      title: searchOnly ? 'No devices match your search.' : 'No devices match the current filters.',
      message: `None of the ${plural(devices.length, 'device')} in the inventory match. Change or clear the filters to see them.`,
      action: { label: 'Clear filters', onClick: clearFilters, icon: null },
    }));
    return matching;
  }

  dom.body.append(inventoryTable(result.items.map(createRow)));
  renderPagination(result, matching.length);
  updateSelectionMarkers();

  return matching;
}

function renderLoadError(error) {
  const base = describeError(error, 'the device inventory');
  let title = base.title;
  let message = base.message;
  let action;

  if (base.kind === 'unauthorized') {
    title = 'Your session has expired.';
    message = 'Sign in again to view the device inventory.';
    action = { label: 'Sign in', href: LOGIN_PAGE };
  } else if (base.kind === 'forbidden') {
    // Retrying cannot change a permission decision.
    message = 'Your account is not permitted to view the device inventory.';
  } else if (base.kind === 'network' || base.kind === 'timeout') {
    title = 'Unable to reach the API.';
    action = { label: 'Retry', onClick: retryFromView };
  } else if (error && error.status >= 500) {
    title = 'Server error';
    message = `The API could not return the device inventory (HTTP ${error.status}). Try again in a moment.`;
    action = { label: 'Retry', onClick: retryFromView };
  } else {
    action = { label: 'Retry', onClick: retryFromView };
  }

  dom.body.setAttribute('aria-busy', 'false');
  dom.count.textContent = '—';
  dom.summary.textContent = title;
  dom.pagination.hidden = true;
  clearChildren(dom.body);
  dom.body.append(stateBlock({
    variant: 'error',
    iconName: base.kind === 'unauthorized' || base.kind === 'forbidden' ? 'lock' : 'warning',
    title,
    message,
    action,
  }));

  return title;
}

/* Device detail ------------------------------------------------------------------------------- */

function detailGroup(title, entries) {
  return el('section', { className: 'detail-group' }, [
    el('h3', { className: 'detail-group-title', text: title }),
    el('dl', { className: 'detail-list' }, entries.flatMap(([label, node]) => [
      el('dt', { text: label }),
      el('dd', {}, [node]),
    ])),
  ]);
}

function statusDetail(device) {
  const wrap = el('span', { className: 'detail-status' }, [deviceStatusTag(device.statusRaw)]);

  // A value outside the contract is shown as Unknown, but never hidden.
  if (device.statusRaw && !DEVICE_STATUSES.includes(device.statusRaw)) {
    wrap.append(el('span', { className: 'detail-hint', text: `Recorded value: ${device.statusRaw}` }));
  }

  return wrap;
}

function seenDetail(device) {
  const wrap = el('span', { className: 'detail-seen' }, [timeNode(device.lastSeen, device.lastSeenRaw, 'mono')]);
  const relative = formatRelativeTime(device.lastSeenRaw, state.now);

  if (device.lastSeen && relative) {
    wrap.append(el('span', { className: 'detail-hint', text: relative }));
  }

  return wrap;
}

function detailContent(device, { closable }) {
  const head = el('header', { className: 'detail-head' }, [
    el('div', { className: 'detail-heading' }, [
      el('p', { className: 'detail-kicker', text: `${humanize(device.type) || 'Device'} #${device.id}` }),
      el('h2', {
        className: device.hostname ? 'detail-title' : 'detail-title is-missing',
        text: device.hostname || 'Unnamed device',
        attrs: { id: 'device-title' },
      }),
      el('div', { className: 'detail-tags' }, [
        deviceStatusTag(device.statusRaw),
        el('span', { className: 'detail-ip mono', text: device.ip || 'No IP recorded' }),
      ]),
    ]),
  ]);

  if (closable) {
    const close = el('button', {
      className: 'icon-button',
      attrs: { type: 'button', 'aria-label': 'Close device details' },
    }, [icon('close')]);
    close.addEventListener('click', closeDetail);
    head.append(close);
  }

  const created = parseApiDate(device.createdRaw);
  const updated = parseApiDate(device.updatedRaw);

  return el('article', { className: `device-detail status-${device.statusKey}`, attrs: { 'aria-labelledby': 'device-title' } }, [
    head,
    detailGroup('Identity', [
      ['Hostname', valueOrMissing(device.hostname)],
      ['Device ID', el('span', { className: 'mono', text: `#${device.id}` })],
      ['UUID', valueOrMissing(device.uuid, { mono: true })],
    ]),
    detailGroup('Network', [
      ['IP address', valueOrMissing(device.ip, { mono: true })],
      ['MAC address', valueOrMissing(device.mac, { mono: true })],
    ]),
    detailGroup('Platform', [
      ['Device type', valueOrMissing(humanize(device.type))],
      ['Vendor', valueOrMissing(device.vendor)],
      ['Operating system', valueOrMissing(device.os)],
      ['Environment', valueOrMissing(humanize(device.environment))],
    ]),
    detailGroup('Observation', [
      ['Recorded status', statusDetail(device)],
      ['Last seen', seenDetail(device)],
      ['Created', timeNode(created, device.createdRaw, 'mono')],
      ['Updated', timeNode(updated, device.updatedRaw, 'mono')],
    ]),
    el('p', {
      className: 'detail-footnote',
      text: 'Recorded status and last seen come from the inventory. Neither confirms that the device is connected right now.',
    }),
  ]);
}

function findDevice(id) {
  return state.devices.find((device) => device.id === id) || null;
}

function updateSelectionMarkers() {
  dom.body.querySelectorAll('.device-link').forEach((link) => {
    const selected = link.dataset.deviceId === String(state.selectedId);

    link.closest('tr').classList.toggle('is-selected', selected);

    if (selected) {
      link.setAttribute('aria-current', 'true');
    } else {
      link.removeAttribute('aria-current');
    }

    if (SPLIT_QUERY.matches) {
      link.setAttribute('aria-controls', 'device-panel');
      link.setAttribute('aria-expanded', String(selected));
      link.removeAttribute('aria-haspopup');
    } else {
      link.setAttribute('aria-controls', 'device-dialog');
      link.setAttribute('aria-haspopup', 'dialog');
      link.removeAttribute('aria-expanded');
    }
  });
}

/** Side panel on wide screens (only while a device is selected), dialog otherwise. */
function renderDetail() {
  const device = state.selectedId !== null ? findDevice(state.selectedId) : null;
  const showPanel = SPLIT_QUERY.matches && device !== null;

  dom.grid.classList.toggle('has-detail', showPanel);
  dom.panel.hidden = !showPanel;
  clearChildren(dom.panel);

  if (showPanel) {
    dom.panel.append(detailContent(device, { closable: true }));
  }

  if (dom.dialog.open && (SPLIT_QUERY.matches || !device)) {
    dom.dialog.close();
  }
}

function focusSelectedLink() {
  const link = Array.from(dom.body.querySelectorAll('.device-link'))
    .find((node) => node.dataset.deviceId === String(state.selectedId));

  if (link) {
    link.focus();
  }
}

function selectDevice(id) {
  const device = findDevice(id);

  if (!device) {
    return;
  }

  state.selectedId = id;
  writeUrlState();
  updateSelectionMarkers();

  if (SPLIT_QUERY.matches) {
    // Focus stays on the row so keyboard users keep moving through the inventory.
    renderDetail();
    announce(`Details shown for ${device.hostname || `device #${id}`}.`);
  } else {
    clearChildren(dom.dialogBody);
    dom.dialogBody.append(detailContent(device, { closable: false }));
    dom.dialog.showModal();
  }
}

function closeDetail() {
  const previous = state.selectedId;
  state.selectedId = null;
  writeUrlState();
  renderDetail();
  updateSelectionMarkers();

  const link = Array.from(dom.body.querySelectorAll('.device-link'))
    .find((node) => node.dataset.deviceId === String(previous));

  if (link) {
    link.focus();
  }
}

/* Update cycle --------------------------------------------------------------------------------- */

function update({ announceResult = false } = {}) {
  renderOverview();
  // Rendering clamps the page when filters shrink the result: persist the URL afterwards.
  const matching = renderInventory();
  writeUrlState();
  renderDetail();

  if (announceResult) {
    announce(state.devices.length === 0
      ? 'No devices to filter yet. The filters will apply as soon as devices are known.'
      : `${plural(matching.length, 'device')} shown.`, { delayed: true });
  }
}

function goToPage(page) {
  state.page = page;
  update();
  announce(dom.range.textContent);
  // The clicked control is re-rendered: move focus to the inventory heading.
  dom.title.focus();
}

function retryFromView() {
  if (dom.dialog.open) {
    dom.dialog.close();
  }

  dom.refresh.focus();
  loadDevices();
}

async function loadDevices({ openSelected = false } = {}) {
  if (state.loading) {
    return;
  }

  setBusy(true);
  setFilterBar(state.answered ? 'paused' : 'hidden');
  setSyncStatus('Loading…', 'loading');
  announce('Loading devices.');
  resetOverview();
  renderCompositionState('loading');
  renderLoading();

  let result;

  try {
    result = await getDevices();

    if (!Array.isArray(result)) {
      throw new ApiError('The API returned devices in an unexpected format.', { status: 200, kind: 'invalid-response' });
    }
  } catch (error) {
    state.devices = [];
    state.answered = false;
    setFilterBar('hidden');
    const title = renderLoadError(error);
    renderCompositionState('error');
    dom.grid.classList.remove('has-detail');
    dom.panel.hidden = true;
    setSyncStatus(`Update failed ${formatClockTime(new Date())}`, 'error');
    announce(title);
    setBusy(false);
    return;
  }

  state.now = Date.now();
  state.devices = result.filter(isDeviceRecord).map((raw) => normalizeDevice(raw, state.now));
  state.skipped = result.length - state.devices.length;
  state.answered = true;

  if (state.selectedId !== null && !findDevice(state.selectedId)) {
    state.selectedId = null;
  }

  populateFilterOptions();
  syncControls();
  setFilterBar('ready');
  update();
  setSyncStatus(`Updated ${formatClockTime(new Date())}`, 'ready');
  announce(`${plural(state.devices.length, 'device')} loaded.`);
  setBusy(false);

  if (openSelected && state.selectedId !== null && !SPLIT_QUERY.matches) {
    selectDevice(state.selectedId);
  }
}

/* Events ------------------------------------------------------------------------------------------ */

function bindEvents() {
  dom.form.addEventListener('submit', (event) => event.preventDefault());

  dom.search.addEventListener('input', () => {
    window.clearTimeout(state.timers.search);
    state.timers.search = window.setTimeout(() => {
      state.filters.q = dom.search.value.trim().slice(0, MAX_PARAM_LENGTH);
      state.page = 1;
      update({ announceResult: true });
    }, SEARCH_DELAY_MS);
  });

  [
    [dom.status, 'status'],
    [dom.type, 'type'],
    [dom.environment, 'environment'],
    [dom.sort, 'sort'],
  ].forEach(([select, key]) => {
    select.addEventListener('change', () => {
      state.filters[key] = select.value;
      state.page = 1;
      update({ announceResult: true });
    });
  });

  dom.bandItems.forEach((item) => {
    item.addEventListener('click', () => {
      const key = item.dataset.filter;
      const value = item.dataset.value;
      const allowed = key === 'status'
        ? DEVICE_STATUSES.includes(value)
        : SEEN_BUCKETS.some((bucket) => bucket.key === value);

      if (!allowed) {
        return;
      }

      state.filters[key] = state.filters[key] === value ? '' : value;
      state.page = 1;
      syncControls();
      update({ announceResult: true });
    });
  });

  dom.clear.addEventListener('click', clearFilters);
  dom.prev.addEventListener('click', () => goToPage(state.page - 1));
  dom.next.addEventListener('click', () => goToPage(state.page + 1));
  dom.refresh.addEventListener('click', () => loadDevices());

  dom.dialogClose.addEventListener('click', () => dom.dialog.close());

  // Escape, the close button and the backdrop all end here: focus returns to the device row.
  dom.dialog.addEventListener('close', () => {
    clearChildren(dom.dialogBody);
    focusSelectedLink();
  });

  dom.dialog.addEventListener('click', (event) => {
    if (event.target === dom.dialog) {
      dom.dialog.close();
    }
  });

  // Escape inside the side panel closes it.
  dom.panel.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeDetail();
    }
  });

  SPLIT_QUERY.addEventListener('change', () => {
    if (dom.dialog.open) {
      dom.dialog.close();
    }

    updateSelectionMarkers();
    renderDetail();
  });
}

bindEvents();
readUrlState();
loadDevices({ openSelected: true });
