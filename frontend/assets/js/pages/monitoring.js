/**
 * CYBERGUARD CAMPUS — Monitoring (Security Operations Monitor).
 * Feeds: GET /api/metrics, /api/events, /api/alerts, /api/devices — loaded independently.
 * Each section renders from the feeds it needs; one failed feed never blanks the others.
 * "Loaded HH:MM:SS" is when this page received a feed, not when the network produced it.
 *
 * Derivations live in monitoring-data.js (pure functions over API records).
 */

import { ApiError, getAlerts, getDevices, getEvents, getMetrics } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { barList, binByHour, columnChart, formatDay } from '../charts.js';
import {
  ALERT_STATUSES,
  DEVICE_STATUSES,
  clearChildren,
  describeError,
  el,
  formatClockTime,
  formatCount,
  formatDateTime,
  formatDeviceStatus,
  formatSeverity,
  formatStatus,
  humanize,
  icon,
  severityTag,
  stateBlock,
  statusTag,
  toDateTimeAttribute,
} from '../format.js';
import {
  SEEN_BUCKETS,
  alertStatusDistribution,
  deviceObservation,
  eventPipeline,
  filterActivity,
  mergeActivity,
  mostActiveDevices,
  normalizeAlerts,
  normalizeDevices,
  normalizeEvents,
  normalizeMetrics,
  severityDistribution,
  topEventSources,
} from './monitoring-data.js';

const HOURS = 24;
const ACTIVITY_PAGE = 25;
const SEARCH_DELAY_MS = 150;
const MAX_QUERY = 120;
const SEVERITY_ORDER = [4, 3, 2, 1];

const FEEDS = {
  metrics: { load: getMetrics, normalize: normalizeMetrics },
  events: { load: getEvents, normalize: normalizeEvents },
  alerts: { load: getAlerts, normalize: normalizeAlerts },
  devices: { load: getDevices, normalize: normalizeDevices },
};

const FEED_NAMES = Object.keys(FEEDS);

// Which feeds each section reads.
const SECTION_FEEDS = {
  totals: ['metrics'],
  eventsHourly: ['events'],
  alertsHourly: ['alerts'],
  distribution: ['alerts'],
  observation: ['devices'],
  sources: ['events'],
  activity: ['events', 'alerts'],
};

const dom = {
  refresh: document.getElementById('monitoring-refresh'),
  syncStatus: document.getElementById('sync-status'),
  syncText: document.getElementById('sync-status-text'),
  announcer: document.getElementById('monitoring-announcer'),
  feeds: Object.fromEntries(FEED_NAMES.map((name) => [name, document.querySelector(`.feed[data-feed="${name}"]`)])),
  authBlock: document.getElementById('auth-block'),
  sections: document.getElementById('monitor-sections'),
  totals: document.getElementById('totals-body'),
  eventsHourly: document.getElementById('events-hourly'),
  alertsHourly: document.getElementById('alerts-hourly'),
  distribution: document.getElementById('alert-distribution'),
  observation: document.getElementById('device-observation'),
  sources: document.getElementById('signal-sources'),
  activity: document.getElementById('activity-body'),
  activityCount: document.getElementById('activity-count'),
  activitySummary: document.getElementById('activity-summary'),
  toolbar: document.getElementById('activity-toolbar'),
  kindButtons: Array.from(document.querySelectorAll('.kind-option[data-kind]')),
  search: document.getElementById('activity-search'),
  activityFooter: document.getElementById('activity-footer'),
  activityRange: document.getElementById('activity-range'),
  activityMore: document.getElementById('activity-more'),
};

/** All page state. Each feed: { status: 'loading' | 'ready' | 'error', data, error, loadedAt }. */
const state = {
  feeds: Object.fromEntries(FEED_NAMES.map((name) => [name, { status: 'loading', data: null, error: null, loadedAt: null }])),
  activity: { kind: 'all', query: '', limit: ACTIVITY_PAGE },
  busy: false,
  searchTimer: 0,
};

/* Helpers ---------------------------------------------------------------------------- */

function plural(count, word) {
  return `${formatCount(count)} ${count === 1 ? word : `${word}s`}`;
}

function announce(message) {
  dom.announcer.textContent = message;
}

function feed(name) {
  return state.feeds[name];
}

function ready(name) {
  return feed(name).status === 'ready';
}

function note(message) {
  return el('p', { className: 'chart-note', text: message });
}

function skeleton(className = 'chart-skeleton') {
  return el('span', { className: `skeleton ${className}`, attrs: { 'aria-hidden': 'true' } });
}

function timeNode(item) {
  const datetime = toDateTimeAttribute(item.timeRaw);

  return datetime
    ? el('time', { className: 'activity-time', text: formatDateTime(item.timeRaw), attrs: { datetime } })
    : el('span', { className: 'activity-time is-missing', text: 'Time unknown' });
}

/** Error copy for a feed; actions: Sign in (401), none (403), Retry otherwise. */
function feedError(name, { compact = true } = {}) {
  const { error } = feed(name);
  const base = describeError(error, `the ${name} feed`);
  let title = base.title;
  let message = base.message;
  let action = { label: 'Retry', onClick: () => retryFeed(name) };

  if (base.kind === 'unauthorized') {
    title = 'Your session has expired.';
    message = 'Sign in again to load monitoring data.';
    action = { label: 'Sign in', href: LOGIN_PAGE };
  } else if (base.kind === 'forbidden') {
    message = `Your account is not permitted to read the ${name} feed.`;
    action = undefined;
  } else if (base.kind === 'network' || base.kind === 'timeout') {
    title = 'Unable to reach the API.';
  } else if (error && error.status >= 500) {
    title = 'Server error';
    message = `The ${name} feed failed on the server (HTTP ${error.status}).`;
  }

  return stateBlock({
    variant: 'error',
    iconName: base.kind === 'unauthorized' || base.kind === 'forbidden' ? 'lock' : 'warning',
    title,
    message,
    action,
    compact,
  });
}

/**
 * Shared gate for single-feed sections: returns true when the section can render data;
 * otherwise renders the loading or error state into `container`.
 */
function gate(container, name) {
  container.setAttribute('aria-busy', String(feed(name).status === 'loading'));
  clearChildren(container);

  if (feed(name).status === 'loading') {
    container.append(skeleton());
    return false;
  }

  if (feed(name).status === 'error') {
    container.append(feedError(name));
    return false;
  }

  return true;
}

/* Data feeds strip ---------------------------------------------------------------------- */

function feedDetail(name) {
  const { data } = feed(name);

  if (name === 'metrics') {
    return '5 recorded totals';
  }

  const text = plural(data.items.length, name === 'devices' ? 'device' : name.slice(0, -1));

  return data.skipped > 0 ? `${text} · ${formatCount(data.skipped)} unreadable` : text;
}

function renderFeed(name) {
  const node = dom.feeds[name];
  const { status, error, loadedAt } = feed(name);
  const stateNode = node.querySelector('[data-feed-state]');
  const detailNode = node.querySelector('[data-feed-detail]');

  node.dataset.state = status;
  node.querySelector('.feed-retry')?.remove();
  clearChildren(detailNode);

  if (status === 'loading') {
    stateNode.textContent = 'Loading…';
    return;
  }

  if (status === 'ready') {
    stateNode.textContent = `Loaded ${formatClockTime(loadedAt)}`;
    detailNode.textContent = feedDetail(name);
    return;
  }

  const kind = describeError(error, name).kind;
  stateNode.textContent = `Failed ${formatClockTime(loadedAt)}`;
  detailNode.textContent = {
    unauthorized: 'Session expired',
    forbidden: 'Access denied',
    network: 'API unreachable',
    timeout: 'Timed out',
    'invalid-response': 'Unexpected response',
  }[kind] || `HTTP ${error && error.status ? error.status : 'error'}`;

  if (kind !== 'unauthorized' && kind !== 'forbidden') {
    const retry = el('button', { className: 'feed-retry text-button', text: 'Retry', attrs: { type: 'button', 'aria-label': `Retry the ${name} feed` } });
    retry.addEventListener('click', () => retryFeed(name));
    node.append(retry);
  }
}

/* Observation totals (metrics feed) ------------------------------------------------------ */

function ledgerCell(label, value, context, extra = []) {
  return el('div', { className: 'ledger-cell' }, [
    el('dt', { className: 'ledger-label', text: label }),
    el('dd', { className: 'ledger-value' }, [
      el('span', { className: 'ledger-number', text: value === null ? '—' : formatCount(value) }),
      el('span', { className: 'ledger-context', text: context }),
      ...extra,
    ]),
  ]);
}

function renderTotals() {
  if (!gate(dom.totals, 'metrics')) {
    return;
  }

  const m = feed('metrics').data;
  const share = m.alerts > 0 && m.critical !== null ? Math.round((m.critical / m.alerts) * 100) : null;

  // Share of critical alerts: two recorded totals, shown with both numbers.
  const meter = el('span', { className: 'ledger-meter', attrs: { 'aria-hidden': 'true' } }, [el('span', { className: 'ledger-meter-fill' })]);
  meter.firstChild.style.width = `${share ?? 0}%`;

  const criticalContext = m.alerts === null || m.critical === null
    ? 'Severity 4 alerts'
    : `of ${plural(m.alerts, 'alert')}${share !== null ? ` (${share}%)` : ''}`;

  dom.totals.append(el('dl', { className: 'ledger' }, [
    ledgerCell('Events observed', m.events, 'recorded by the platform'),
    ledgerCell('Alerts observed', m.alerts, 'raised from events'),
    ledgerCell('Critical alerts', m.critical, criticalContext, [meter]),
    ledgerCell('Active incidents', m.activeIncidents, 'not yet resolved or closed'),
    ledgerCell('Known devices', m.devices, 'in the inventory, not a connection count'),
  ]));

  const values = [m.events, m.alerts, m.critical, m.activeIncidents, m.devices];

  if (values.some((value) => value === null)) {
    dom.totals.append(note('A value shown as — was missing from the metrics response.'));
  } else if (values.every((value) => value === 0)) {
    dom.totals.append(note('Nothing recorded yet: no events, alerts, active incidents or devices.'));
  }
}

/* Hourly activity (events / alerts feeds) -------------------------------------------------- */

function renderHourly(container, name, unit) {
  if (!gate(container, name)) {
    return;
  }

  const items = feed(name).data.items;
  const { bins, outside, undated } = binByHour(items, (item) => item.timeRaw, HOURS);
  const inWindow = bins.reduce((sum, bin) => sum + bin.count, 0);
  const notes = [inWindow === 0 ? `No ${unit}s in the last ${HOURS} hours.` : `${plural(inWindow, unit)} in the last ${HOURS} hours.`];

  if (outside > 0) notes.push(`${plural(outside, unit)} outside this window.`);
  if (undated > 0) notes.push(`${plural(undated, unit)} without a valid time not plotted.`);

  container.append(
    note(notes.join(' ')),
    columnChart({
      caption: `${unit[0].toUpperCase()}${unit.slice(1)}s per hour, last ${HOURS} hours`,
      unit,
      labelEvery: 6,
      bins: bins.map((bin) => ({
        label: formatDay(bin.date, { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }),
        shortLabel: formatDay(bin.date, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }),
        value: bin.count,
      })),
    }),
  );
}

/* Alert distribution (alerts feed) --------------------------------------------------------- */

function renderDistribution() {
  if (!gate(dom.distribution, 'alerts')) {
    return;
  }

  const alerts = feed('alerts').data.items;

  if (alerts.length === 0) {
    dom.distribution.append(note('No alerts to distribute yet.'));
    return;
  }

  const severity = severityDistribution(alerts);
  const severityItems = SEVERITY_ORDER.map((level) => ({ label: formatSeverity(level), note: `Severity ${level}`, value: severity[level] }));

  if (severity.unknown > 0) {
    severityItems.push({ label: 'Unknown', note: 'Outside the 1–4 scale', value: severity.unknown });
  }

  const { counts, other } = alertStatusDistribution(alerts);
  const statusItems = ALERT_STATUSES.map((status) => ({ label: formatStatus(status), value: counts[status] }));

  if (other > 0) {
    statusItems.push({ label: 'Other status', note: 'Outside the status list', value: other });
  }

  dom.distribution.append(
    el('div', { className: 'split-lists' }, [
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'By severity' }),
        barList({ caption: 'Alerts by severity', items: severityItems, unit: 'alert' }),
      ]),
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'By status' }),
        barList({ caption: 'Alerts by status', items: statusItems, unit: 'alert' }),
      ]),
    ]),
    note(`Based on ${plural(alerts.length, 'alert')} returned by the API.`),
  );
}

/* Device observation (devices feed) ---------------------------------------------------------- */

function renderObservation() {
  if (!gate(dom.observation, 'devices')) {
    return;
  }

  const devices = feed('devices').data.items;

  if (devices.length === 0) {
    dom.observation.append(note('No device observations available.'));
    return;
  }

  const { status, seen } = deviceObservation(devices);

  dom.observation.append(
    el('div', { className: 'split-lists' }, [
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'Recorded status' }),
        barList({
          caption: 'Devices by recorded status',
          items: DEVICE_STATUSES.map((key) => ({ label: formatDeviceStatus(key), value: status[key] })),
          unit: 'device',
        }),
      ]),
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'Last observation' }),
        barList({
          caption: 'Devices by time since last observation',
          items: SEEN_BUCKETS.map((bucket) => ({ label: bucket.label, value: seen[bucket.key] })),
          unit: 'device',
        }),
      ]),
    ]),
    note('Status is the value stored in the inventory and "seen" is the last recorded observation. Neither proves a device is connected now.'),
  );
}

/* Signal sources (events feed; names from devices; alert links from alerts) --------------- */

function renderSources() {
  if (!gate(dom.sources, 'events')) {
    return;
  }

  const events = feed('events').data.items;

  if (events.length === 0) {
    dom.sources.append(note('No events recorded yet: sources appear once events arrive.'));
    return;
  }

  const sources = topEventSources(events);
  const sourceItems = sources.top.map((entry) => ({ label: entry.key || 'No source recorded', value: entry.value }));

  if (sources.others > 0) {
    sourceItems.push({ label: 'All other sources', note: `${formatCount(sources.groups - sources.top.length)} more`, value: sources.others });
  }

  const devices = ready('devices') ? feed('devices').data.items : null;
  const active = mostActiveDevices(events, devices);
  const deviceItems = active.top.map((entry) => ({
    label: entry.hostname || `Device #${entry.key}`,
    note: entry.inInventory ? `#${entry.key}` : (devices ? 'Not in the inventory' : 'Name unavailable: devices feed not loaded'),
    value: entry.value,
  }));

  const pipeline = eventPipeline(events, ready('alerts') ? feed('alerts').data.items : null);

  const deviceBlock = deviceItems.length
    ? barList({ caption: 'Devices with the most linked events', items: deviceItems, unit: 'event' })
    : note('No event is linked to a device.');

  dom.sources.append(
    el('div', { className: 'sources-grid' }, [
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'Top event sources' }),
        barList({ caption: 'Events by source', items: sourceItems, unit: 'event' }),
      ]),
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'Most active devices' }),
        deviceBlock,
        active.unlinked > 0 ? note(`${plural(active.unlinked, 'event')} not linked to a device.`) : el('span'),
      ]),
      el('div', { className: 'list-block' }, [
        el('h3', { className: 'list-title', text: 'Pipeline facts' }),
        el('dl', { className: 'facts' }, [
          el('dt', { text: 'Events that raised an alert' }),
          el('dd', { text: ready('alerts') ? `${formatCount(pipeline.alerted)} of ${formatCount(pipeline.total)}` : '— (alerts feed not loaded)' }),
          el('dt', { text: 'Events without a processed time' }),
          el('dd', { text: `${formatCount(pipeline.withoutProcessedTime)} of ${formatCount(pipeline.total)}` }),
        ]),
      ]),
    ]),
  );
}

/* Recent security activity (events + alerts feeds) ------------------------------------------- */

function eventTitle(item) {
  return item.signature || humanize(item.type) || 'Event';
}

function activityRow(item) {
  const isAlert = item.kind === 'alert';
  const meta = el('p', { className: 'activity-meta' });

  if (item.source) meta.append(el('span', { className: 'activity-source', text: item.source }));
  if (isAlert && item.type) meta.append(el('span', { text: humanize(item.type) }));
  if (!isAlert && item.signature && item.type) meta.append(el('span', { text: humanize(item.type) }));
  if (item.category) meta.append(el('span', { text: item.category }));
  if (!isAlert && (item.srcIp || item.dstIp)) {
    meta.append(el('span', { className: 'activity-ip', text: `${item.srcIp || '—'} → ${item.dstIp || '—'}` }));
  }
  if (item.deviceId !== null) meta.append(el('span', { text: `Device #${item.deviceId}` }));

  return el('li', { className: `activity-row is-${isAlert ? 'alert' : 'event'}` }, [
    el('span', { className: 'activity-kind' }, [icon(isAlert ? 'alerts' : 'events'), el('span', { text: isAlert ? 'Alert' : 'Event' })]),
    timeNode(item),
    el('span', { className: 'activity-severity' }, [severityTag(item.severity)]),
    el('div', { className: 'activity-main' }, [
      el('p', { className: 'activity-title', text: isAlert ? (item.title || 'Untitled alert') : eventTitle(item) }),
      meta,
    ]),
    el('span', { className: 'activity-status' }, [isAlert ? statusTag(item.status) : el('span', { className: 'is-missing', text: 'No status' })]),
  ]);
}

function renderActivity() {
  const loading = SECTION_FEEDS.activity.some((name) => feed(name).status === 'loading');
  const available = SECTION_FEEDS.activity.filter(ready);
  const failed = SECTION_FEEDS.activity.filter((name) => feed(name).status === 'error');

  dom.activity.setAttribute('aria-busy', String(loading));
  clearChildren(dom.activity);
  dom.activityFooter.hidden = true;

  if (loading) {
    dom.toolbar.hidden = true;
    dom.activityCount.textContent = '—';
    dom.activity.append(skeleton('activity-skeleton'));
    return;
  }

  if (available.length === 0) {
    dom.toolbar.hidden = true;
    dom.activityCount.textContent = '—';
    dom.activitySummary.textContent = 'Events and alerts, newest first';
    failed.forEach((name) => dom.activity.append(feedError(name)));
    return;
  }

  // Partial failure: show what loaded, and say what is missing.
  failed.forEach((name) => dom.activity.append(feedError(name)));

  const events = ready('events') ? feed('events').data.items : [];
  const alerts = ready('alerts') ? feed('alerts').data.items : [];
  const all = mergeActivity(events, alerts);
  const matching = filterActivity(all, state.activity);
  const shown = matching.slice(0, state.activity.limit);

  dom.toolbar.hidden = false;
  dom.kindButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.kind === state.activity.kind)));
  dom.activityCount.textContent = matching.length === all.length ? plural(all.length, 'record') : `${formatCount(matching.length)} of ${formatCount(all.length)}`;
  dom.activitySummary.textContent = available.length === 2
    ? `${plural(events.length, 'event')} and ${plural(alerts.length, 'alert')}, newest first`
    : `${available[0] === 'events' ? plural(events.length, 'event') : plural(alerts.length, 'alert')} only, newest first`;

  if (all.length === 0) {
    dom.activity.append(stateBlock({
      variant: 'empty',
      iconName: 'monitoring',
      title: 'No monitoring data available.',
      message: 'No events or alerts have been recorded yet. They appear here, newest first, as soon as the API returns them.',
    }));
    return;
  }

  if (matching.length === 0) {
    dom.activity.append(stateBlock({
      variant: 'empty',
      iconName: 'search',
      title: 'No activity matches this view.',
      message: 'Change the record type or clear the search to see more.',
      action: { label: 'Clear search', onClick: clearActivityFilters, icon: null },
    }));
    return;
  }

  dom.activity.append(el('ol', { className: 'activity-list', attrs: { 'aria-label': 'Recent events and alerts' } }, shown.map(activityRow)));

  const remaining = matching.length - shown.length;
  dom.activityFooter.hidden = remaining <= 0 && matching.length <= ACTIVITY_PAGE;
  dom.activityRange.textContent = `Showing ${formatCount(shown.length)} of ${plural(matching.length, 'record')}`;
  dom.activityMore.hidden = remaining <= 0;
  dom.activityMore.textContent = `Show ${formatCount(Math.min(ACTIVITY_PAGE, remaining))} more`;
}

function clearActivityFilters() {
  state.activity.kind = 'all';
  state.activity.query = '';
  state.activity.limit = ACTIVITY_PAGE;
  dom.search.value = '';
  renderActivity();
  dom.search.focus();
}

/* Rendering ------------------------------------------------------------------------------------ */

const RENDERERS = {
  totals: renderTotals,
  eventsHourly: () => renderHourly(dom.eventsHourly, 'events', 'event'),
  alertsHourly: () => renderHourly(dom.alertsHourly, 'alerts', 'alert'),
  distribution: renderDistribution,
  observation: renderObservation,
  sources: renderSources,
  activity: renderActivity,
};

/** Re-render only what depends on the feed that changed (sources also read devices/alerts). */
function renderFor(name) {
  renderFeed(name);

  Object.entries(SECTION_FEEDS).forEach(([section, feeds]) => {
    const readsIt = feeds.includes(name) || (section === 'sources' && (name === 'devices' || name === 'alerts'));

    if (readsIt) {
      RENDERERS[section]();
    }
  });

  renderAuthBlock();
}

/** When every feed says the session expired, one sign-in prompt replaces the sections. */
function renderAuthBlock() {
  const allExpired = FEED_NAMES.every((name) => feed(name).status === 'error' && describeError(feed(name).error, name).kind === 'unauthorized');

  dom.authBlock.hidden = !allExpired;
  dom.sections.hidden = allExpired;
  clearChildren(dom.authBlock);

  if (allExpired) {
    dom.authBlock.append(stateBlock({
      variant: 'error',
      iconName: 'lock',
      title: 'Your session has expired.',
      message: 'Sign in again to load the monitoring feeds.',
      action: { label: 'Sign in', href: LOGIN_PAGE },
    }));
  }
}

function updateSync() {
  const loadedAt = FEED_NAMES.map((name) => feed(name).loadedAt).filter(Boolean);
  const failed = FEED_NAMES.filter((name) => feed(name).status === 'error').length;

  if (FEED_NAMES.some((name) => feed(name).status === 'loading')) {
    dom.syncStatus.dataset.state = 'loading';
    dom.syncText.textContent = 'Loading…';
    return;
  }

  const last = new Date(Math.max(...loadedAt.map((date) => date.getTime())));
  dom.syncStatus.dataset.state = failed ? 'error' : 'ready';
  dom.syncText.textContent = failed
    ? `Last refreshed ${formatClockTime(last)} · ${failed} of ${FEED_NAMES.length} feeds failed`
    : `Last refreshed ${formatClockTime(last)}`;
}

/* Loading ----------------------------------------------------------------------------------- */

async function loadFeed(name) {
  const entry = feed(name);
  entry.status = 'loading';
  entry.error = null;
  renderFor(name);

  try {
    const raw = await FEEDS[name].load();

    try {
      entry.data = FEEDS[name].normalize(raw);
    } catch {
      throw new ApiError(`The ${name} feed returned an unexpected format.`, { status: 200, kind: 'invalid-response' });
    }

    entry.status = 'ready';
  } catch (error) {
    // Previous data is dropped: an error is shown instead of stale numbers presented as current.
    entry.data = null;
    entry.error = error;
    entry.status = 'error';
  }

  entry.loadedAt = new Date();
  renderFor(name);
}

async function loadAll() {
  if (state.busy) {
    return;
  }

  state.busy = true;
  dom.refresh.setAttribute('aria-disabled', 'true');
  dom.refresh.setAttribute('aria-busy', 'true');
  announce('Loading monitoring feeds.');
  updateSync();

  await Promise.all(FEED_NAMES.map(loadFeed));

  state.busy = false;
  dom.refresh.setAttribute('aria-disabled', 'false');
  dom.refresh.setAttribute('aria-busy', 'false');
  updateSync();

  const failed = FEED_NAMES.filter((name) => feed(name).status === 'error');
  announce(failed.length ? `Monitoring loaded with ${failed.length} failed ${failed.length === 1 ? 'feed' : 'feeds'}: ${failed.join(', ')}.` : 'Monitoring feeds loaded.');
}

async function retryFeed(name) {
  if (state.busy || feed(name).status === 'loading') {
    return;
  }

  // The retry control is re-rendered: keep focus on a stable control.
  dom.refresh.focus();
  await loadFeed(name);
  updateSync();
  announce(ready(name) ? `${humanize(name)} feed loaded.` : `${humanize(name)} feed failed again.`);
}

/* Events ------------------------------------------------------------------------------------- */

function bindEvents() {
  dom.refresh.addEventListener('click', loadAll);
  dom.toolbar.addEventListener('submit', (event) => event.preventDefault());

  dom.kindButtons.forEach((button) => {
    button.addEventListener('click', () => {
      state.activity.kind = button.dataset.kind;
      state.activity.limit = ACTIVITY_PAGE;
      renderActivity();
    });
  });

  dom.search.addEventListener('input', () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(() => {
      state.activity.query = dom.search.value.trim().slice(0, MAX_QUERY);
      state.activity.limit = ACTIVITY_PAGE;
      renderActivity();
    }, SEARCH_DELAY_MS);
  });

  dom.activityMore.addEventListener('click', () => {
    state.activity.limit += ACTIVITY_PAGE;
    renderActivity();
    announce(dom.activityRange.textContent);
  });
}

bindEvents();
loadAll();
