/**
 * CYBERGUARD CAMPUS — Dashboard (Security Overview).
 * Data: GET /api/metrics, GET /api/alerts. Nothing is invented client-side:
 * every figure comes from the API, the breakdown is derived from the alerts it returns.
 *
 * Each section is always in exactly one state: loading | data | empty | error.
 */

import { getAlerts, getMetrics } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { binByDay, columnChart, formatDay } from '../charts.js';
import {
  clearChildren,
  describeError,
  el,
  formatClockTime,
  formatCount,
  formatDateTime,
  formatSeverity,
  severityClass,
  severityLevel,
  severityTag,
  stateBlock,
  statusTag,
  toCount,
  toDateTimeAttribute,
} from '../format.js';

const RECENT_ALERT_LIMIT = 10;
const LOADING_ROWS = 4;

const KPI_KEYS = ['total_events', 'total_alerts', 'critical_alerts', 'open_incidents', 'devices'];

// Emphasis applied only when the API reports a non-zero value.
const KPI_ATTENTION = {
  critical_alerts: 'critical',
  open_incidents: 'warning',
};

const SEVERITY_ORDER = [4, 3, 2, 1];
const STATUS_ORDER = ['new', 'acknowledged', 'resolved', 'false_positive'];

const dom = {
  refresh: document.getElementById('dashboard-refresh'),
  syncStatus: document.getElementById('sync-status'),
  syncText: document.getElementById('sync-status-text'),
  announcer: document.getElementById('dashboard-announcer'),
  kpiGrid: document.getElementById('kpi-grid'),
  metricsError: document.getElementById('metrics-error'),
  alertsBody: document.getElementById('alerts-body'),
  alertsCount: document.getElementById('alerts-count'),
  breakdownBody: document.getElementById('breakdown-body'),
  trend: document.getElementById('alert-trend'),
};

let isLoading = false;

/* Shared -------------------------------------------------------------------- */

function announce(message) {
  dom.announcer.textContent = message;
}

function errorState(error, subject, { compact = false } = {}) {
  const description = describeError(error, subject);
  const needsSignIn = description.kind === 'unauthorized';

  return stateBlock({
    variant: 'error',
    iconName: needsSignIn ? 'lock' : 'warning',
    title: description.title,
    message: description.message,
    compact,
    action: needsSignIn
      ? { label: 'Sign in', href: LOGIN_PAGE }
      : { label: 'Retry', onClick: retryFromState },
  });
}

/* Key Security Metrics ---------------------------------------------------------- */

function kpiCards() {
  return KPI_KEYS.map((key) => ({
    key,
    card: dom.kpiGrid.querySelector(`[data-kpi="${key}"]`),
  })).filter(({ card }) => card);
}

function renderMetricsLoading() {
  clearChildren(dom.metricsError);
  dom.kpiGrid.setAttribute('aria-busy', 'true');

  kpiCards().forEach(({ card }) => {
    const value = card.querySelector('[data-kpi-value]');
    card.dataset.state = 'loading';
    delete card.dataset.attention;
    clearChildren(value);
    value.append(
      el('span', { className: 'skeleton kpi-skeleton', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'visually-hidden', text: 'Loading' }),
    );
  });
}

function renderMetrics(metrics) {
  if (!metrics || typeof metrics !== 'object' || Array.isArray(metrics)) {
    throw new TypeError('Invalid metrics payload.');
  }

  dom.kpiGrid.setAttribute('aria-busy', 'false');

  kpiCards().forEach(({ key, card }) => {
    const count = toCount(metrics[key]);
    const value = card.querySelector('[data-kpi-value]');

    value.textContent = count === null ? '—' : formatCount(count);
    card.dataset.state = count === null ? 'error' : 'ready';

    if (count !== null && count > 0 && KPI_ATTENTION[key]) {
      card.dataset.attention = KPI_ATTENTION[key];
    } else {
      delete card.dataset.attention;
    }
  });
}

function renderMetricsError(error) {
  dom.kpiGrid.setAttribute('aria-busy', 'false');

  kpiCards().forEach(({ card }) => {
    card.dataset.state = 'error';
    delete card.dataset.attention;
    card.querySelector('[data-kpi-value]').textContent = '—';
  });

  clearChildren(dom.metricsError);
  dom.metricsError.append(errorState(error, 'security metrics', { compact: true }));
}

/* Recent Alerts ------------------------------------------------------------------ */

function createAlertRow(alert) {
  const meta = el('p', { className: 'alert-meta' }, [
    el('span', { className: 'alert-source', text: alert.source || 'Unknown source' }),
  ]);

  if (alert.category) {
    meta.append(el('span', { text: alert.category }));
  }

  const datetime = toDateTimeAttribute(alert.detected_at);
  const time = datetime
    ? el('time', { className: 'alert-time', text: formatDateTime(alert.detected_at), attrs: { datetime } })
    : el('span', { className: 'alert-time', text: 'Time unknown' });

  return el('li', { className: `alert-row ${severityClass(alert.severity)}` }, [
    el('div', { className: 'alert-severity' }, [severityTag(alert.severity)]),
    el('div', { className: 'alert-main' }, [
      el('p', { className: 'alert-title', text: alert.title || 'Untitled alert' }),
      meta,
    ]),
    el('div', { className: 'alert-aside' }, [statusTag(alert.status), time]),
  ]);
}

function renderAlertsLoading() {
  dom.alertsBody.setAttribute('aria-busy', 'true');
  dom.alertsCount.textContent = '—';
  clearChildren(dom.alertsBody);

  const rows = Array.from({ length: LOADING_ROWS }, () => el('li', {
    className: 'alert-row is-skeleton',
    attrs: { 'aria-hidden': 'true' },
  }, [
    el('span', { className: 'skeleton skeleton-severity' }),
    el('div', { className: 'alert-main' }, [
      el('span', { className: 'skeleton' }),
      el('span', { className: 'skeleton' }),
    ]),
    el('span', { className: 'skeleton skeleton-time' }),
  ]));

  dom.alertsBody.append(
    el('ul', { className: 'alert-feed' }, rows),
    el('p', { className: 'visually-hidden', text: 'Loading recent alerts' }),
  );
}

function renderAlerts(alerts) {
  dom.alertsBody.setAttribute('aria-busy', 'false');
  clearChildren(dom.alertsBody);

  const total = alerts.length;

  if (total === 0) {
    dom.alertsCount.textContent = '0 alerts';
    dom.alertsBody.append(stateBlock({
      variant: 'empty',
      iconName: 'orbit',
      title: 'No alerts available',
      message: 'No security alerts are currently available. New alerts will appear here as soon as the API reports them.',
    }));
    return;
  }

  const shown = alerts.slice(0, RECENT_ALERT_LIMIT);

  dom.alertsCount.textContent = total > shown.length
    ? `${shown.length} of ${formatCount(total)}`
    : `${formatCount(total)} ${total === 1 ? 'alert' : 'alerts'}`;

  dom.alertsBody.append(el('ul', { className: 'alert-feed' }, shown.map(createAlertRow)));
}

function renderAlertsError(error) {
  dom.alertsBody.setAttribute('aria-busy', 'false');
  dom.alertsCount.textContent = '—';
  clearChildren(dom.alertsBody);
  dom.alertsBody.append(errorState(error, 'recent alerts'));
}

/* Alert Breakdown (derived from the alerts returned by the API) ------------------------ */

function countBy(alerts) {
  const severity = { 1: 0, 2: 0, 3: 0, 4: 0, unknown: 0 };
  const status = Object.fromEntries(STATUS_ORDER.map((key) => [key, 0]));

  alerts.forEach((alert) => {
    const level = severityLevel(alert.severity);
    severity[level === null ? 'unknown' : level] += 1;

    if (Object.hasOwn(status, alert.status)) {
      status[alert.status] += 1;
    }
  });

  return { severity, status };
}

function share(count, total) {
  return total === 0 ? 0 : (count / total) * 100;
}

function renderBreakdownLoading() {
  dom.breakdownBody.setAttribute('aria-busy', 'true');
  clearChildren(dom.breakdownBody);
  dom.breakdownBody.append(
    el('span', { className: 'skeleton mix-skeleton', attrs: { 'aria-hidden': 'true' } }),
    el('span', { className: 'skeleton mix-skeleton is-medium', attrs: { 'aria-hidden': 'true' } }),
    el('span', { className: 'skeleton mix-skeleton is-short', attrs: { 'aria-hidden': 'true' } }),
    el('p', { className: 'visually-hidden', text: 'Loading alert breakdown' }),
  );
}

function renderBreakdown(alerts) {
  dom.breakdownBody.setAttribute('aria-busy', 'false');
  clearChildren(dom.breakdownBody);

  const total = alerts.length;
  const { severity, status } = countBy(alerts);
  const levels = severity.unknown > 0 ? [...SEVERITY_ORDER, 'unknown'] : SEVERITY_ORDER;

  const summary = levels
    .map((level) => `${formatSeverity(level)} ${severity[level]}`)
    .join(', ');

  const bar = el('div', {
    className: 'mix-bar',
    attrs: { role: 'img', 'aria-label': `Alerts by severity: ${summary}.` },
  });

  levels.forEach((level) => {
    if (severity[level] > 0) {
      const segment = el('span', { className: `mix-bar-segment ${level === 'unknown' ? 'severity-unknown' : `severity-${level}`}` });
      segment.style.flexGrow = String(severity[level]);
      bar.append(segment);
    }
  });

  const rows = levels.map((level) => {
    const fill = el('span', { className: 'mix-fill' });
    fill.style.width = `${share(severity[level], total)}%`;

    return el('li', {
      className: `mix-row ${level === 'unknown' ? 'severity-unknown' : `severity-${level}`}`,
    }, [
      severityTag(level),
      el('span', { className: 'mix-track', attrs: { 'aria-hidden': 'true' } }, [fill]),
      el('span', { className: 'mix-count', text: formatCount(severity[level]) }),
    ]);
  });

  const statuses = STATUS_ORDER.map((key) => el('li', {}, [
    statusTag(key),
    el('span', { className: 'mix-count', text: formatCount(status[key]) }),
  ]));

  dom.breakdownBody.append(
    bar,
    el('ul', { className: 'mix-list', attrs: { 'aria-label': 'Alerts by severity' } }, rows),
    el('p', { className: 'mix-subheading', text: 'Status' }),
    el('ul', { className: 'status-summary', attrs: { 'aria-label': 'Alerts by status' } }, statuses),
    el('p', {
      className: 'mix-note',
      text: total === 0
        ? 'No alerts have been returned by the API yet.'
        : `Based on ${formatCount(total)} ${total === 1 ? 'alert' : 'alerts'} returned by the API.`,
    }),
  );
}

function renderBreakdownError() {
  dom.breakdownBody.setAttribute('aria-busy', 'false');
  clearChildren(dom.breakdownBody);
  dom.breakdownBody.append(el('p', {
    className: 'mix-note',
    text: 'Unavailable until alerts can be loaded.',
  }));
}

/* Alert Activity (alerts per day, derived from the alerts returned by the API) ------------ */

const TREND_DAYS = 14;

function severityLines(items) {
  return SEVERITY_ORDER
    .map((level) => [level, items.filter((alert) => severityLevel(alert.severity) === level).length])
    .filter(([, count]) => count > 0)
    .map(([level, count]) => ({ value: formatCount(count), label: formatSeverity(level) }));
}

function renderTrendLoading() {
  dom.trend.setAttribute('aria-busy', 'true');
  clearChildren(dom.trend);
  dom.trend.append(el('span', { className: 'skeleton chart-skeleton', attrs: { 'aria-hidden': 'true' } }));
}

function renderTrend(alerts) {
  const { bins, outside, undated } = binByDay(alerts, (alert) => alert.detected_at, TREND_DAYS);
  const inWindow = bins.reduce((sum, bin) => sum + bin.count, 0);
  const peak = bins.reduce((best, bin) => (bin.count > best.count ? bin : best), bins[0]);

  dom.trend.setAttribute('aria-busy', 'false');
  clearChildren(dom.trend);

  const notes = [];

  if (inWindow === 0) {
    notes.push(`No alerts detected in the last ${TREND_DAYS} days.`);
  } else {
    notes.push(`${formatCount(inWindow)} ${inWindow === 1 ? 'alert' : 'alerts'} in the last ${TREND_DAYS} days, busiest on ${formatDay(peak.date, { weekday: 'long', day: 'numeric', month: 'long' })} (${formatCount(peak.count)}).`);
  }

  if (outside > 0) {
    notes.push(`${formatCount(outside)} older ${outside === 1 ? 'alert is' : 'alerts are'} outside this window.`);
  }

  if (undated > 0) {
    notes.push(`${formatCount(undated)} without a valid detection time ${undated === 1 ? 'is' : 'are'} not plotted.`);
  }

  dom.trend.append(
    el('p', { className: 'chart-note', text: notes.join(' ') }),
    columnChart({
      caption: `Alerts detected per day, last ${TREND_DAYS} days`,
      unit: 'alert',
      labelEvery: 2,
      bins: bins.map((bin) => ({
        label: formatDay(bin.date, { weekday: 'short', day: '2-digit', month: 'short' }),
        shortLabel: formatDay(bin.date),
        value: bin.count,
        lines: severityLines(bin.items),
      })),
    }),
  );
}

function renderTrendError() {
  dom.trend.setAttribute('aria-busy', 'false');
  clearChildren(dom.trend);
  dom.trend.append(el('p', { className: 'chart-note', text: 'Unavailable until alerts can be loaded.' }));
}

/* Load cycle -------------------------------------------------------------------------- */

// aria-disabled (not `disabled`) keeps keyboard focus on the button while loading.
function setBusy(busy) {
  isLoading = busy;
  dom.refresh.setAttribute('aria-disabled', String(busy));
  dom.refresh.setAttribute('aria-busy', String(busy));
}

// The Retry button is removed by the reload: keep focus on a stable control.
function retryFromState() {
  dom.refresh.focus();
  loadDashboard();
}

function setSyncStatus(text, state) {
  dom.syncText.textContent = text;
  dom.syncStatus.dataset.state = state;
}

async function loadDashboard() {
  if (isLoading) {
    return;
  }

  setBusy(true);
  setSyncStatus('Loading…', 'loading');
  announce('Loading security overview.');

  renderMetricsLoading();
  renderAlertsLoading();
  renderBreakdownLoading();
  renderTrendLoading();

  const [metricsResult, alertsResult] = await Promise.allSettled([getMetrics(), getAlerts()]);

  let metricsLoaded = false;
  let alertsLoaded = false;

  if (metricsResult.status === 'fulfilled') {
    try {
      renderMetrics(metricsResult.value);
      metricsLoaded = true;
    } catch (error) {
      renderMetricsError({ kind: 'invalid-response', status: 200, message: error.message });
    }
  } else {
    renderMetricsError(metricsResult.reason);
  }

  if (alertsResult.status === 'fulfilled' && Array.isArray(alertsResult.value)) {
    renderAlerts(alertsResult.value);
    renderBreakdown(alertsResult.value);
    renderTrend(alertsResult.value);
    alertsLoaded = true;
  } else {
    const error = alertsResult.status === 'rejected'
      ? alertsResult.reason
      : { kind: 'invalid-response', status: 200 };
    renderAlertsError(error);
    renderBreakdownError();
    renderTrendError();
  }

  const time = formatClockTime(new Date());

  if (metricsLoaded && alertsLoaded) {
    setSyncStatus(`Updated ${time}`, 'ready');
    announce('Security overview updated.');
  } else if (metricsLoaded || alertsLoaded) {
    setSyncStatus(`Partially updated ${time}`, 'error');
    announce('Security overview partially updated. Some data could not be loaded.');
  } else {
    setSyncStatus(`Update failed ${time}`, 'error');
    announce('Security overview could not be loaded.');
  }

  setBusy(false);
}

dom.refresh.addEventListener('click', loadDashboard);

loadDashboard();
