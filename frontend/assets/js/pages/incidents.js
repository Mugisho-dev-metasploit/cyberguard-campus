/**
 * CYBERGUARD CAMPUS — Incidents (Incident Command Center).
 * Data: GET /api/incidents (viewer, analyst, admin), PATCH /api/incidents/{id} (analyst, admin).
 * Filtering, sorting and paging are client-side: the backend returns every incident, newest first.
 *
 * The UI never shows a status change before the API confirms it, and always renders
 * the incident exactly as the API returned it after an update.
 */

import { ApiError, getIncidents, updateIncident } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { barList } from '../charts.js';
import {
  INCIDENT_STATUSES,
  clearChildren,
  describeError,
  el,
  formatClockTime,
  formatCount,
  formatDateTime,
  formatIncidentStatus,
  formatRelativeTime,
  formatSeverity,
  icon,
  incidentStatusTag,
  parseApiDate,
  severityClass,
  severityLevel,
  severityTag,
  stateBlock,
  toDateTimeAttribute,
} from '../format.js';

const PAGE_SIZE = 50;
const LOADING_ROWS = 5;
const SEARCH_DELAY_MS = 150;
const ANNOUNCE_DELAY_MS = 450;
const MAX_PARAM_LENGTH = 120;
const SEVERITY_OPTIONS = [4, 3, 2, 1];
const ACTIVE_STATUSES = ['open', 'acknowledged', 'investigating', 'contained'];
const SORT_OPTIONS = ['newest', 'oldest', 'severity', 'priority', 'updated'];
const OWNER_OPTIONS = ['assigned', 'unassigned'];

// Backend ENUM, highest first.
const PRIORITIES = ['critical', 'high', 'medium', 'low'];
const PRIORITY_LABELS = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' };

// Case file sits beside the queue on wide screens, in a modal dialog otherwise.
const SPLIT_QUERY = window.matchMedia('(min-width: 1280px)');

const HINT_READY = 'Select a stage to show only its incidents.';
const HINT_WAITING = 'Stages become selectable once incidents are loaded.';

const dom = {
  refresh: document.getElementById('incidents-refresh'),
  syncStatus: document.getElementById('sync-status'),
  syncText: document.getElementById('sync-status-text'),
  announcer: document.getElementById('incidents-announcer'),
  hint: document.getElementById('pipeline-hint'),
  stages: Array.from(document.querySelectorAll('.pipeline-stage[data-status]')),
  watch: {
    total: document.querySelector('[data-watch="total"]'),
    critical: document.querySelector('[data-watch="critical"]'),
    unassigned: document.querySelector('[data-watch="unassigned"]'),
  },
  grid: document.getElementById('command-grid'),
  summary: document.getElementById('queue-summary'),
  count: document.getElementById('queue-count'),
  form: document.getElementById('queue-filters'),
  fieldset: document.getElementById('queue-filters-set'),
  search: document.getElementById('filter-search'),
  sort: document.getElementById('filter-sort'),
  status: document.getElementById('filter-status'),
  severity: document.getElementById('filter-severity'),
  priority: document.getElementById('filter-priority'),
  owner: document.getElementById('filter-owner'),
  filtersFooter: document.getElementById('filters-footer'),
  clear: document.getElementById('filters-clear'),
  body: document.getElementById('queue-body'),
  footer: document.getElementById('queue-footer'),
  rangeText: document.getElementById('queue-range'),
  more: document.getElementById('queue-more'),
  caseFile: document.getElementById('case-file'),
  dialog: document.getElementById('case-dialog'),
  dialogBody: document.getElementById('case-dialog-body'),
  dialogClose: document.getElementById('case-dialog-close'),
  age: document.getElementById('age-body'),
  longest: document.getElementById('longest-open'),
};

const filters = {
  search: '',
  status: '',
  severity: '',
  priority: '',
  owner: '',
  sort: 'newest',
};

let incidents = [];
let selectedId = null;
let limit = PAGE_SIZE;
let isLoading = false;
let isUpdating = false;
let caseFlash = null;
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

function isIncident(value) {
  return Boolean(value) && typeof value === 'object' && Number.isSafeInteger(value.id) && value.id > 0;
}

function isActive(incident) {
  return ACTIVE_STATUSES.includes(incident.status);
}

function priorityKey(value) {
  return typeof value === 'string' && PRIORITIES.includes(value) ? value : null;
}

function priorityRank(value) {
  const key = priorityKey(value);

  return key ? PRIORITIES.length - PRIORITIES.indexOf(key) : 0;
}

function formatPriority(value) {
  const key = priorityKey(value);

  return key ? PRIORITY_LABELS[key] : 'Unknown';
}

/** Priority tag: text label; the data attribute is whitelisted. */
function priorityTag(value) {
  const key = priorityKey(value);

  return el('span', {
    className: 'priority',
    text: `${formatPriority(value)} priority`,
    attrs: { 'data-priority': key || 'unknown' },
  });
}

function ownerLabel(incident) {
  return Number.isSafeInteger(incident.assigned_to) && incident.assigned_to > 0
    ? `User #${incident.assigned_to}`
    : 'Unassigned';
}

function isAssigned(incident) {
  return Number.isSafeInteger(incident.assigned_to) && incident.assigned_to > 0;
}

function findIncident(id) {
  return incidents.find((incident) => incident.id === id) || null;
}

function hasActiveFilters() {
  return Boolean(filters.search || filters.status || filters.severity || filters.priority || filters.owner);
}

function timeNode(value, fallback, className) {
  const datetime = toDateTimeAttribute(value);

  return datetime
    ? el('time', { className, text: formatDateTime(value), attrs: { datetime } })
    : el('span', { className: `${className || ''} is-missing`.trim(), text: fallback });
}

/* Shareable view: filters and selection mirrored in the URL (validated) ------------ */

function readStateFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const status = params.get('status');
  const severity = params.get('severity');
  const priority = params.get('priority');
  const owner = params.get('owner');
  const sort = params.get('sort');
  const incident = params.get('incident');

  filters.search = (params.get('q') || '').trim().slice(0, MAX_PARAM_LENGTH);
  filters.status = INCIDENT_STATUSES.includes(status) ? status : '';
  filters.severity = /^[1-4]$/.test(severity || '') ? severity : '';
  filters.priority = PRIORITIES.includes(priority) ? priority : '';
  filters.owner = OWNER_OPTIONS.includes(owner) ? owner : '';
  filters.sort = SORT_OPTIONS.includes(sort) ? sort : 'newest';
  selectedId = /^[1-9]\d{0,15}$/.test(incident || '') ? Number(incident) : null;
}

function writeStateToUrl() {
  const params = new URLSearchParams();

  if (filters.search) params.set('q', filters.search);
  if (filters.status) params.set('status', filters.status);
  if (filters.severity) params.set('severity', filters.severity);
  if (filters.priority) params.set('priority', filters.priority);
  if (filters.owner) params.set('owner', filters.owner);
  if (filters.sort !== 'newest') params.set('sort', filters.sort);
  if (selectedId !== null) params.set('incident', String(selectedId));

  const query = params.toString();
  window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
}

function syncControls() {
  dom.search.value = filters.search;
  dom.sort.value = filters.sort;
  dom.status.value = filters.status;
  dom.severity.value = filters.severity;
  dom.priority.value = filters.priority;
  dom.owner.value = filters.owner;
}

/* Filtering & sorting ------------------------------------------------------------ */

function matches(incident) {
  if (filters.status && incident.status !== filters.status) return false;
  if (filters.severity && severityLevel(incident.severity) !== Number(filters.severity)) return false;
  if (filters.priority && priorityKey(incident.priority) !== filters.priority) return false;
  if (filters.owner === 'assigned' && !isAssigned(incident)) return false;
  if (filters.owner === 'unassigned' && isAssigned(incident)) return false;

  if (filters.search) {
    const haystack = [incident.incident_number, incident.title, incident.description, incident.incident_uuid]
      .map(text)
      .join('\n')
      .toLowerCase();

    if (!haystack.includes(filters.search.toLowerCase())) return false;
  }

  return true;
}

function timeOf(value) {
  const date = parseApiDate(value);

  return date ? date.getTime() : Number.NEGATIVE_INFINITY;
}

function sortIncidents(list) {
  // The API returns newest detected first: that order is the stable base.
  const indexed = list.map((incident, index) => ({ incident, index }));
  const byIndex = (a, b) => a.index - b.index;

  const comparators = {
    oldest: (a, b) => timeOf(a.incident.detected_at) - timeOf(b.incident.detected_at) || b.index - a.index,
    severity: (a, b) => (severityLevel(b.incident.severity) ?? 0) - (severityLevel(a.incident.severity) ?? 0) || byIndex(a, b),
    priority: (a, b) => priorityRank(b.incident.priority) - priorityRank(a.incident.priority) || byIndex(a, b),
    updated: (a, b) => timeOf(b.incident.updated_at) - timeOf(a.incident.updated_at) || byIndex(a, b),
  };

  if (comparators[filters.sort]) {
    indexed.sort(comparators[filters.sort]);
  }

  return indexed.map(({ incident }) => incident);
}

/* Filter controls ------------------------------------------------------------------- */

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

function populateFilterOptions() {
  const statusCounts = tally(incidents.map((incident) => incident.status));
  filters.status = fillSelect(dom.status, INCIDENT_STATUSES.map((status) => ({
    value: status, label: formatIncidentStatus(status), count: statusCounts.get(status) || 0,
  })), filters.status);

  const severityCounts = tally(incidents.map((incident) => severityLevel(incident.severity)));
  filters.severity = fillSelect(dom.severity, SEVERITY_OPTIONS.map((level) => ({
    value: String(level), label: formatSeverity(level), count: severityCounts.get(level) || 0,
  })), filters.severity);

  const priorityCounts = tally(incidents.map((incident) => priorityKey(incident.priority)));
  filters.priority = fillSelect(dom.priority, PRIORITIES.map((priority) => ({
    value: priority, label: PRIORITY_LABELS[priority], count: priorityCounts.get(priority) || 0,
  })), filters.priority);

  const assigned = incidents.filter(isAssigned).length;
  filters.owner = fillSelect(dom.owner, [
    { value: 'assigned', label: 'Assigned', count: assigned },
    { value: 'unassigned', label: 'Unassigned', count: incidents.length - assigned },
  ], filters.owner);

  dom.sort.value = filters.sort;
}

/**
 * Filter bar: 'hidden' when the API failed (or before the first answer),
 * 'paused' while a refresh runs, 'ready' whenever the API answered — even with no incidents.
 */
function setFilterBar(state) {
  dom.form.hidden = state === 'hidden';
  dom.fieldset.disabled = state !== 'ready';
}

function clearFilters() {
  filters.search = '';
  filters.status = '';
  filters.severity = '';
  filters.priority = '';
  filters.owner = '';
  syncControls();
  applyFilters({ announceResult: true });
  dom.search.focus();
}

/* Response pipeline & watch list ---------------------------------------------------------- */

function setStagesEnabled(enabled) {
  dom.stages.forEach((stage) => {
    stage.disabled = !enabled;
  });
  dom.hint.textContent = enabled ? HINT_READY : HINT_WAITING;
}

function renderPipeline() {
  const counts = tally(incidents.map((incident) => incident.status));

  dom.stages.forEach((stage) => {
    const status = stage.dataset.status;
    stage.querySelector('[data-stage-count]').textContent = formatCount(counts.get(status) || 0);
    stage.setAttribute('aria-pressed', String(filters.status === status));
  });

  const active = incidents.filter(isActive);
  const critical = active.filter((incident) => severityLevel(incident.severity) === 4).length;
  const unassigned = active.filter((incident) => !isAssigned(incident)).length;

  dom.watch.total.textContent = formatCount(incidents.length);
  dom.watch.critical.textContent = formatCount(critical);
  dom.watch.unassigned.textContent = formatCount(unassigned);
  dom.watch.critical.dataset.attention = String(critical > 0);
  renderAge();
}

function resetPipeline() {
  dom.stages.forEach((stage) => {
    stage.querySelector('[data-stage-count]').textContent = '—';
    stage.setAttribute('aria-pressed', 'false');
  });
  Object.values(dom.watch).forEach((node) => {
    node.textContent = '—';
    node.dataset.attention = 'false';
  });
}

/* Active incident age (time since detection, active statuses only) -------------------------- */

const HOUR_MS = 60 * 60 * 1000;
const AGE_BUCKETS = [
  { label: 'Under 24 hours', max: 24 * HOUR_MS },
  { label: '1 to 3 days', max: 3 * 24 * HOUR_MS },
  { label: '3 to 7 days', max: 7 * 24 * HOUR_MS },
  { label: 'Over 7 days', max: Infinity },
];

function renderAge() {
  const now = Date.now();
  const active = incidents.filter(isActive);
  const counts = AGE_BUCKETS.map(() => 0);
  let undated = 0;

  active.forEach((incident) => {
    const date = parseApiDate(incident.detected_at);

    if (!date) {
      undated += 1;
      return;
    }

    const age = Math.max(0, now - date.getTime());
    counts[AGE_BUCKETS.findIndex((bucket) => age < bucket.max)] += 1;
  });

  const items = AGE_BUCKETS.map((bucket, index) => ({ label: bucket.label, value: counts[index] }));

  if (undated > 0) {
    items.push({ label: 'Detection time unknown', value: undated });
  }

  dom.age.setAttribute('aria-busy', 'false');
  clearChildren(dom.age);
  dom.age.append(
    el('p', { className: 'chart-note', text: active.length === 0
      ? 'No active incidents: every recorded incident is resolved or closed, or none exist yet.'
      : `${plural(active.length, 'active incident')} by time since detection.` }),
    barList({ caption: 'Active incidents by time since detection', items, unit: 'incident' }),
  );

  renderLongestOpen(active);
}

function renderLongestOpen(active) {
  clearChildren(dom.longest);

  const oldest = active
    .filter((incident) => parseApiDate(incident.detected_at))
    .sort((a, b) => timeOf(a.detected_at) - timeOf(b.detected_at))
    .slice(0, 3);

  if (oldest.length === 0) {
    dom.longest.append(el('li', { className: 'longest-open-empty', text: 'Nothing open right now.' }));
    return;
  }

  oldest.forEach((incident) => {
    const button = el('button', { className: 'longest-open-item', attrs: { type: 'button' } }, [
      el('span', { className: 'longest-open-number', text: text(incident.incident_number) || `#${incident.id}` }),
      el('span', { className: 'longest-open-name', text: text(incident.title) || 'Untitled incident' }),
      el('span', { className: 'longest-open-meta' }, [
        incidentStatusTag(incident.status),
        el('span', { text: `Detected ${formatRelativeTime(incident.detected_at) || formatDateTime(incident.detected_at)}` }),
      ]),
    ]);
    button.addEventListener('click', () => selectIncident(incident.id));
    dom.longest.append(el('li', {}, [button]));
  });
}

function renderAgeState(mode) {
  dom.age.setAttribute('aria-busy', String(mode === 'loading'));
  clearChildren(dom.age);
  clearChildren(dom.longest);
  dom.age.append(mode === 'loading'
    ? el('span', { className: 'skeleton age-skeleton', attrs: { 'aria-hidden': 'true' } })
    : el('p', { className: 'chart-note', text: 'Unavailable until incidents can be loaded.' }));
}

/* Queue ------------------------------------------------------------------------------------ */

function createRow(incident) {
  const button = el('button', {
    className: `incident-row ${severityClass(incident.severity)}`,
    attrs: { type: 'button', 'data-incident-id': incident.id },
  }, [
    el('span', { className: 'incident-head' }, [
      severityTag(incident.severity),
      el('span', { className: 'incident-number', text: text(incident.incident_number) || `#${incident.id}` }),
    ]),
    timeNode(incident.detected_at, 'Time unknown', 'incident-detected'),
    el('span', { className: 'incident-title', text: text(incident.title) || 'Untitled incident' }),
    el('span', { className: 'incident-meta' }, [
      incidentStatusTag(incident.status),
      priorityTag(incident.priority),
      el('span', { className: isAssigned(incident) ? 'incident-owner' : 'incident-owner is-missing', text: ownerLabel(incident) }),
    ]),
  ]);

  button.addEventListener('click', () => selectIncident(incident.id));

  return el('li', {}, [button]);
}

function updateRowSelection() {
  dom.body.querySelectorAll('.incident-row').forEach((row) => {
    const selected = row.dataset.incidentId === String(selectedId);

    if (selected) {
      row.setAttribute('aria-current', 'true');
    } else {
      row.removeAttribute('aria-current');
    }

    if (SPLIT_QUERY.matches) {
      row.setAttribute('aria-controls', 'case-file');
      row.removeAttribute('aria-haspopup');
    } else {
      row.setAttribute('aria-controls', 'case-dialog');
      row.setAttribute('aria-haspopup', 'dialog');
    }
  });
}

function renderLoading() {
  dom.body.setAttribute('aria-busy', 'true');
  dom.count.textContent = '—';
  dom.summary.textContent = 'Loading incidents…';
  dom.footer.hidden = true;
  clearChildren(dom.body);

  const rows = Array.from({ length: LOADING_ROWS }, () => el('li', { attrs: { 'aria-hidden': 'true' } }, [
    el('div', { className: 'incident-row is-skeleton' }, [
      el('span', { className: 'incident-head' }, [el('span', { className: 'skeleton skeleton-tag' })]),
      el('span', { className: 'skeleton skeleton-time' }),
      el('span', { className: 'skeleton skeleton-title' }),
      el('span', { className: 'skeleton skeleton-meta' }),
    ]),
  ]));

  dom.body.append(
    el('ul', { className: 'incident-list' }, rows),
    el('p', { className: 'visually-hidden', text: 'Loading incidents' }),
  );
}

function renderQueue() {
  const total = incidents.length;
  const matching = sortIncidents(incidents.filter(matches));
  const shown = matching.slice(0, limit);

  dom.body.setAttribute('aria-busy', 'false');
  clearChildren(dom.body);
  dom.filtersFooter.hidden = !hasActiveFilters();

  if (total === 0) {
    dom.count.textContent = '0 incidents';
    dom.summary.textContent = hasActiveFilters()
      ? 'The API returned no incidents. Your filters will apply as soon as incidents are recorded.'
      : 'The API returned no incidents.';
    dom.footer.hidden = true;
    dom.body.append(stateBlock({
      variant: 'empty',
      iconName: 'incidents',
      title: 'No security incidents recorded',
      message: 'Incidents opened by the security team appear here with their response stage. Nothing has been recorded yet.',
      action: { label: 'Check again', onClick: retryFromView },
    }));
    return 0;
  }

  dom.count.textContent = matching.length === total
    ? plural(total, 'incident')
    : `${formatCount(matching.length)} of ${formatCount(total)}`;

  dom.summary.textContent = hasActiveFilters()
    ? `${plural(matching.length, 'incident')} match the current filters.`
    : `All ${plural(total, 'incident')} returned by the API.`;

  if (matching.length === 0) {
    dom.footer.hidden = true;
    dom.body.append(stateBlock({
      variant: 'empty',
      iconName: 'search',
      title: 'No incidents match these filters',
      message: `None of the ${plural(total, 'incident')} returned by the API match. Change or clear the filters to see them.`,
      action: { label: 'Clear filters', onClick: clearFilters, icon: null },
    }));
    return 0;
  }

  dom.body.append(el('ul', { className: 'incident-list' }, shown.map(createRow)));
  updateRowSelection();

  const remaining = matching.length - shown.length;
  dom.footer.hidden = remaining <= 0 && matching.length <= PAGE_SIZE;
  dom.rangeText.textContent = `Showing ${formatCount(shown.length)} of ${plural(matching.length, 'incident')}`;
  dom.more.hidden = remaining <= 0;
  dom.more.textContent = `Show ${formatCount(Math.min(PAGE_SIZE, remaining))} more`;

  return matching.length;
}

function renderQueueError(error) {
  const description = describeError(error, 'incidents');
  let action;

  if (description.kind === 'unauthorized') {
    action = { label: 'Sign in', href: LOGIN_PAGE };
  } else if (description.kind !== 'forbidden') {
    // Retrying cannot change a permission decision, so 403 offers no retry.
    action = { label: 'Retry', onClick: retryFromView };
  }

  dom.body.setAttribute('aria-busy', 'false');
  dom.count.textContent = '—';
  dom.summary.textContent = description.title;
  dom.footer.hidden = true;
  clearChildren(dom.body);
  dom.body.append(stateBlock({
    variant: 'error',
    iconName: description.kind === 'unauthorized' || description.kind === 'forbidden' ? 'lock' : 'warning',
    title: description.title,
    message: description.message,
    action,
  }));
}

/* Case file --------------------------------------------------------------------------------- */

function caseSection(title, children, className = '') {
  return el('section', { className: `case-section ${className}`.trim() }, [
    el('h3', { className: 'case-section-title', text: title }),
    ...children,
  ]);
}

function stageTrack(status) {
  const current = INCIDENT_STATUSES.indexOf(status);

  return el('ol', { className: 'stage-track', attrs: { 'aria-label': 'Response stage' } }, INCIDENT_STATUSES.map((stage, index) => {
    const state = index < current ? 'is-done' : index === current ? 'is-current' : '';
    const step = el('li', { className: `stage-step ${state}`.trim() }, [
      el('span', { className: 'stage-marker', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'stage-label', text: formatIncidentStatus(stage) }),
    ]);

    if (index === current) {
      step.setAttribute('aria-current', 'step');
      step.append(el('span', { className: 'visually-hidden', text: '(current stage)' }));
    }

    return step;
  }));
}

function setFeedback(node, { state, message, action }) {
  clearChildren(node);
  node.dataset.state = state;
  node.hidden = false;

  const iconName = state === 'success' ? 'check' : state === 'error' ? 'warning' : 'clock';
  node.append(icon(iconName), el('span', { className: 'case-feedback-text', text: message }));

  if (action && action.href) {
    node.append(el('a', { className: 'text-button', text: action.label, attrs: { href: action.href } }));
  } else if (action) {
    const button = el('button', { className: 'text-button', text: action.label, attrs: { type: 'button' } });
    button.addEventListener('click', action.onClick);
    node.append(button);
  }
}

/** User-facing copy for a failed PATCH. Never exposes backend messages. */
function updateFailure(error) {
  const kind = error && error.kind;
  const status = error && error.status;
  const reload = { label: 'Reload incidents', onClick: retryFromView };

  if (kind === 'unauthorized') {
    return { message: 'Your session has expired. Sign in again, then repeat the change.', action: { label: 'Sign in', href: LOGIN_PAGE } };
  }

  if (kind === 'forbidden') {
    return { message: 'Your role cannot change incident status. Ask an analyst or an administrator.' };
  }

  if (kind === 'timeout' || kind === 'network') {
    return { message: 'The API could not be reached. The status was not changed. Try again.' };
  }

  if (kind === 'invalid-response') {
    return { message: 'The API answered in an unexpected format. Reload to confirm the current status.', action: reload };
  }

  if (status === 404) {
    return { message: 'This incident no longer exists. Reload to see the current incidents.', action: reload };
  }

  if (status === 400 || status === 422) {
    return { message: 'The status change was rejected. Reload the incident and try again.', action: reload };
  }

  return { message: `The update failed (HTTP ${status || 'error'}). Reload to confirm the current status.`, action: reload };
}

function statusForm(incident) {
  const select = el('select', { className: 'select', attrs: { id: 'case-status', name: 'status' } });

  INCIDENT_STATUSES.forEach((status) => {
    const option = el('option', { text: formatIncidentStatus(status) });
    option.value = status;
    select.append(option);
  });

  const known = INCIDENT_STATUSES.includes(incident.status);

  if (!known) {
    select.prepend(el('option', { text: 'Unknown status', attrs: { value: '', disabled: '' } }));
  }

  select.value = known ? incident.status : '';

  const submit = el('button', {
    className: 'button button-primary',
    attrs: { type: 'submit', id: 'case-status-submit' },
  }, [el('span', { text: 'Update status' })]);

  const feedback = el('p', { className: 'case-feedback', attrs: { id: 'case-feedback' } });
  feedback.hidden = true;

  const syncSubmit = () => {
    submit.disabled = isUpdating || select.value === '' || select.value === incident.status;
  };

  select.addEventListener('change', () => {
    feedback.hidden = true;
    syncSubmit();
  });
  syncSubmit();

  const form = el('form', { className: 'status-form', attrs: { novalidate: '' } }, [
    el('label', { className: 'field-label', text: 'Change status', attrs: { for: 'case-status' } }),
    el('div', { className: 'status-controls' }, [select, submit]),
    el('p', { className: 'status-note', text: 'Status changes require an analyst or administrator role.' }),
    feedback,
  ]);

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    submitStatus(incident, select, submit, feedback);
  });

  if (caseFlash && caseFlash.id === incident.id) {
    setFeedback(feedback, caseFlash);
    caseFlash = null;
  }

  return form;
}

async function submitStatus(incident, select, submit, feedback) {
  const next = select.value;

  if (isUpdating || !INCIDENT_STATUSES.includes(next) || next === incident.status) {
    return;
  }

  isUpdating = true;
  select.disabled = true;
  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');
  submit.firstChild.textContent = 'Updating…';
  setFeedback(feedback, { state: 'pending', message: `Changing status to ${formatIncidentStatus(next)}…` });

  let updated;

  try {
    updated = await updateIncident(incident.id, { status: next });

    if (!isIncident(updated) || updated.id !== incident.id) {
      throw new ApiError('The API returned an unexpected response.', { status: 200, kind: 'invalid-response' });
    }
  } catch (error) {
    isUpdating = false;
    select.disabled = false;
    submit.disabled = false;
    submit.setAttribute('aria-busy', 'false');
    submit.firstChild.textContent = 'Update status';
    const failure = updateFailure(error);
    setFeedback(feedback, { state: 'error', ...failure });
    announce(failure.message);
    return;
  }

  // Confirmed by the API: render exactly what it returned.
  isUpdating = false;
  incidents = incidents.map((item) => (item.id === updated.id ? updated : item));
  const message = `Status changed to ${formatIncidentStatus(updated.status)}.`;
  caseFlash = { id: updated.id, state: 'success', message };

  populateFilterOptions();
  renderPipeline();
  renderQueue();
  renderCase();
  announce(message);

  const newSelect = document.getElementById('case-status');

  if (newSelect) {
    newSelect.focus();
  }
}

function caseContent(incident) {
  const description = text(incident.description);

  const details = el('dl', { className: 'case-details' }, [
    el('dt', { text: 'Priority' }), el('dd', { text: formatPriority(incident.priority) }),
    el('dt', { text: 'Assigned to' }), el('dd', { className: isAssigned(incident) ? '' : 'is-missing', text: ownerLabel(incident) }),
    el('dt', { text: 'Created' }), el('dd', {}, [timeNode(incident.created_at, 'Not recorded')]),
    el('dt', { text: 'Last updated' }), el('dd', {}, [timeNode(incident.updated_at, 'Not recorded')]),
    el('dt', { text: 'Incident ID' }), el('dd', { className: 'mono', text: text(incident.incident_uuid) || '—' }),
    el('dt', { text: 'Record' }), el('dd', { className: 'mono', text: `#${incident.id}` }),
  ]);

  const log = el('ol', { className: 'response-log' }, [
    ['Detected', incident.detected_at],
    ['Acknowledged', incident.acknowledged_at],
    ['Contained', incident.contained_at],
    ['Resolved', incident.resolved_at],
    ['Closed', incident.closed_at],
  ].map(([label, value]) => el('li', { className: toDateTimeAttribute(value) ? 'is-recorded' : '' }, [
    el('span', { className: 'response-log-label', text: label }),
    timeNode(value, 'Not recorded'),
  ])));

  return el('article', { className: `case ${severityClass(incident.severity)}`, attrs: { 'aria-labelledby': 'case-title' } }, [
    el('header', { className: 'case-head' }, [
      el('p', { className: 'case-number', text: text(incident.incident_number) || `Incident #${incident.id}` }),
      el('h2', { className: 'case-title', text: text(incident.title) || 'Untitled incident', attrs: { id: 'case-title' } }),
      el('div', { className: 'case-tags' }, [
        severityTag(incident.severity),
        incidentStatusTag(incident.status),
        priorityTag(incident.priority),
      ]),
    ]),
    caseSection('Response stage', [stageTrack(incident.status), statusForm(incident)]),
    caseSection('Description', [
      el('p', { className: description ? 'case-text' : 'case-text is-missing', text: description || 'No description provided.' }),
    ]),
    caseSection('Response timeline', [log]),
    caseSection('Details', [details]),
  ]);
}

function casePlaceholder() {
  return el('div', { className: 'case-placeholder' }, [
    el('span', { className: 'state-icon' }, [icon('incidents')]),
    el('p', { className: 'case-placeholder-title', text: 'No incident selected' }),
    el('p', { className: 'case-placeholder-text', text: 'Select an incident in the queue to open its case file, review its timeline and update its status.' }),
  ]);
}

/** Renders the case file where it belongs for the current viewport. */
function renderCase() {
  const incident = selectedId !== null ? findIncident(selectedId) : null;
  const showPanel = SPLIT_QUERY.matches && incidents.length > 0;

  dom.grid.classList.toggle('has-case', showPanel);
  dom.caseFile.hidden = !showPanel;
  clearChildren(dom.caseFile);

  if (showPanel) {
    dom.caseFile.append(incident ? caseContent(incident) : casePlaceholder());
  }

  if (dom.dialog.open) {
    clearChildren(dom.dialogBody);

    if (incident && !SPLIT_QUERY.matches) {
      dom.dialogBody.append(caseContent(incident));
    } else {
      dom.dialog.close();
    }
  }
}

function focusSelectedRow() {
  const row = Array.from(dom.body.querySelectorAll('.incident-row'))
    .find((node) => node.dataset.incidentId === String(selectedId));

  if (row) {
    row.focus();
  }
}

function selectIncident(id) {
  if (!findIncident(id)) {
    return;
  }

  selectedId = id;
  writeStateToUrl();
  updateRowSelection();

  if (SPLIT_QUERY.matches) {
    // Focus stays in the queue so keyboard users can keep moving through incidents.
    renderCase();
    const incident = findIncident(id);
    announce(`Case file shows ${text(incident.incident_number) || `incident #${id}`}.`);
  } else {
    clearChildren(dom.dialogBody);
    dom.dialogBody.append(caseContent(findIncident(id)));
    dom.dialog.showModal();
  }
}

/* Interaction -------------------------------------------------------------------------------- */

function applyFilters({ announceResult = false } = {}) {
  limit = PAGE_SIZE;
  writeStateToUrl();
  renderPipeline();
  const count = renderQueue();

  if (announceResult) {
    announce(incidents.length === 0
      ? 'No incidents to filter yet. The filters will apply as soon as incidents are recorded.'
      : `${plural(count, 'incident')} shown.`, { delayed: true });
  }
}

function retryFromView() {
  // The button that triggered this may be removed by the reload: keep focus on a stable control.
  if (dom.dialog.open) {
    dom.dialog.close();
  }

  dom.refresh.focus();
  loadIncidents();
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
  [dom.sort, 'sort'],
  [dom.status, 'status'],
  [dom.severity, 'severity'],
  [dom.priority, 'priority'],
  [dom.owner, 'owner'],
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

    if (!INCIDENT_STATUSES.includes(status)) {
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
  announce(dom.rangeText.textContent);
});

dom.dialogClose.addEventListener('click', () => dom.dialog.close());

// Escape and the close button both end here: return focus to the row that opened the case.
dom.dialog.addEventListener('close', () => {
  clearChildren(dom.dialogBody);
  focusSelectedRow();
});

// Clicking the backdrop closes the dialog.
dom.dialog.addEventListener('click', (event) => {
  if (event.target === dom.dialog) {
    dom.dialog.close();
  }
});

SPLIT_QUERY.addEventListener('change', () => {
  if (dom.dialog.open) {
    dom.dialog.close();
  }

  updateRowSelection();
  renderCase();
});

/* Load cycle ------------------------------------------------------------------------------------ */

async function loadIncidents({ openSelected = false } = {}) {
  if (isLoading) {
    return;
  }

  const hadAnswer = !dom.form.hidden;

  setBusy(true);
  setFilterBar(hadAnswer ? 'paused' : 'hidden');
  setStagesEnabled(false);
  setSyncStatus('Loading…', 'loading');
  announce('Loading incidents.');
  resetPipeline();
  renderAgeState('loading');
  renderLoading();

  let result;

  try {
    result = await getIncidents();

    if (!Array.isArray(result)) {
      throw new ApiError('The API returned incidents in an unexpected format.', { status: 200, kind: 'invalid-response' });
    }
  } catch (error) {
    incidents = [];
    setFilterBar('hidden');
    renderQueueError(error);
    renderAgeState('error');
    renderCase();
    setSyncStatus(`Update failed ${formatClockTime(new Date())}`, 'error');
    announce(describeError(error, 'incidents').title);
    setBusy(false);
    return;
  }

  // Entries without a valid id cannot be selected or updated: they are not displayed.
  incidents = result.filter(isIncident);
  limit = PAGE_SIZE;

  if (selectedId !== null && !findIncident(selectedId)) {
    selectedId = null;
  }

  populateFilterOptions();
  syncControls();
  setFilterBar('ready');
  setStagesEnabled(true);
  writeStateToUrl();
  renderPipeline();
  renderQueue();
  renderCase();
  setSyncStatus(`Updated ${formatClockTime(new Date())}`, 'ready');
  announce(`${plural(incidents.length, 'incident')} loaded.`);
  setBusy(false);

  if (openSelected && selectedId !== null && !SPLIT_QUERY.matches) {
    selectIncident(selectedId);
  }
}

dom.refresh.addEventListener('click', () => loadIncidents());

readStateFromUrl();
loadIncidents({ openSelected: true });
