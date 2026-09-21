/**
 * CYBERGUARD CAMPUS — Events.
 * Data: GET /api/events. The table is always in exactly one state:
 * loading | data | empty | error — an error is never replaced by the empty state.
 */

import { getEvents } from '../api.js';
import { LOGIN_PAGE } from '../auth.js';
import { binByDay, columnChart, formatDay } from '../charts.js';
import {
  clearChildren,
  describeError,
  el,
  formatCount,
  formatDateTime,
  severityTag,
  stateBlock,
  toDateTimeAttribute,
} from '../format.js';

const COLUMN_COUNT = 9;
const LOADING_ROWS = 5;

const dom = {
  body: document.getElementById('events-table-body'),
  count: document.getElementById('events-count'),
  announcer: document.getElementById('events-announcer'),
  volume: document.getElementById('events-volume'),
};

function text(value) {
  return value === null || value === undefined || value === '' ? '—' : String(value);
}

function endpoint(ip, port) {
  if (!ip && !port) {
    return '—';
  }

  return `${ip || '—'}${port ? `:${port}` : ''}`;
}

function cell(content, className) {
  const td = el('td', { className });

  if (content instanceof Node) {
    td.append(content);
  } else {
    td.textContent = content;
  }

  return td;
}

function createEventRow(event) {
  const timestamp = event.event_timestamp || event.created_at || event.processed_at;
  const datetime = toDateTimeAttribute(timestamp);
  const time = datetime
    ? el('time', { text: formatDateTime(timestamp), attrs: { datetime } })
    : el('span', { text: '—' });

  return el('tr', {}, [
    cell(time, 'cell-mono cell-nowrap'),
    cell(severityTag(event.severity)),
    cell(text(event.source), 'cell-mono'),
    cell(text(event.event_type)),
    cell(text(event.category)),
    cell(endpoint(event.src_ip, event.src_port), 'cell-mono cell-nowrap'),
    cell(endpoint(event.dst_ip, event.dst_port), 'cell-mono cell-nowrap'),
    cell(text(event.protocol), 'cell-mono'),
    cell(text(event.signature), 'cell-signature'),
  ]);
}

function stateRow(content) {
  const td = el('td', { className: 'cell-state', attrs: { colspan: COLUMN_COUNT } }, [content]);

  return el('tr', {}, [td]);
}

/* Event volume (events per day, derived from the events returned by the API) ----------- */

const VOLUME_DAYS = 14;

function eventTime(event) {
  return event.event_timestamp || event.created_at || event.processed_at;
}

function renderVolume(events) {
  const { bins, outside, undated } = binByDay(events, eventTime, VOLUME_DAYS);
  const inWindow = bins.reduce((sum, bin) => sum + bin.count, 0);
  const notes = [inWindow === 0
    ? `No events recorded in the last ${VOLUME_DAYS} days.`
    : `${formatCount(inWindow)} ${inWindow === 1 ? 'event' : 'events'} in the last ${VOLUME_DAYS} days.`];

  if (outside > 0) notes.push(`${formatCount(outside)} older ${outside === 1 ? 'event is' : 'events are'} outside this window.`);
  if (undated > 0) notes.push(`${formatCount(undated)} without a valid time ${undated === 1 ? 'is' : 'are'} not plotted.`);

  dom.volume.setAttribute('aria-busy', 'false');
  clearChildren(dom.volume);
  dom.volume.append(
    el('p', { className: 'chart-note', text: notes.join(' ') }),
    columnChart({
      caption: `Events recorded per day, last ${VOLUME_DAYS} days`,
      unit: 'event',
      labelEvery: 2,
      bins: bins.map((bin) => ({
        label: formatDay(bin.date, { weekday: 'short', day: '2-digit', month: 'short' }),
        shortLabel: formatDay(bin.date),
        value: bin.count,
      })),
    }),
  );
}

function renderVolumeState(loading) {
  dom.volume.setAttribute('aria-busy', String(loading));
  clearChildren(dom.volume);
  dom.volume.append(loading
    ? el('span', { className: 'skeleton chart-skeleton', attrs: { 'aria-hidden': 'true' } })
    : el('p', { className: 'chart-note', text: 'Unavailable until events can be loaded.' }));
}

function renderLoading() {
  dom.body.setAttribute('aria-busy', 'true');
  dom.count.textContent = '—';
  dom.announcer.textContent = 'Loading security events.';
  clearChildren(dom.body);

  for (let index = 0; index < LOADING_ROWS; index += 1) {
    const cells = Array.from({ length: COLUMN_COUNT }, () => el('td', {}, [
      el('span', { className: 'skeleton events-skeleton' }),
    ]));

    dom.body.append(el('tr', { attrs: { 'aria-hidden': 'true' } }, cells));
  }
}

function renderEvents(events) {
  dom.body.setAttribute('aria-busy', 'false');
  clearChildren(dom.body);

  dom.count.textContent = `${formatCount(events.length)} ${events.length === 1 ? 'event' : 'events'}`;

  if (events.length === 0) {
    dom.body.append(stateRow(stateBlock({
      variant: 'empty',
      iconName: 'orbit',
      title: 'No events available',
      message: 'No security events are currently recorded. Events will appear here as soon as the API reports them.',
    })));
    dom.announcer.textContent = 'No security events available.';
    return;
  }

  events.forEach((event) => dom.body.append(createEventRow(event)));
  dom.announcer.textContent = `${formatCount(events.length)} security events loaded.`;
}

function renderError(error) {
  const description = describeError(error, 'security events');
  const needsSignIn = description.kind === 'unauthorized';

  dom.body.setAttribute('aria-busy', 'false');
  dom.count.textContent = '—';
  clearChildren(dom.body);
  dom.body.append(stateRow(stateBlock({
    variant: 'error',
    iconName: needsSignIn ? 'lock' : 'warning',
    title: description.title,
    message: description.message,
    action: needsSignIn
      ? { label: 'Sign in', href: LOGIN_PAGE }
      : { label: 'Retry', onClick: loadEvents },
  })));
  dom.announcer.textContent = description.title;
}

async function loadEvents() {
  renderLoading();
  renderVolumeState(true);

  let events;

  try {
    events = await getEvents();
  } catch (error) {
    renderError(error);
    renderVolumeState(false);
    return;
  }

  if (!Array.isArray(events)) {
    renderError({ kind: 'invalid-response', status: 200 });
    renderVolumeState(false);
    return;
  }

  renderEvents(events);
  renderVolume(events);
}

loadEvents();
