/**
 * CYBERGUARD CAMPUS — Monitoring: pure data derivations (no DOM, no network).
 * Every value here is a count or grouping of records returned by the API.
 * Nothing is estimated, interpolated or scored.
 */

import {
  ALERT_STATUSES,
  DEVICE_STATUSES,
  deviceStatusKey,
  parseApiDate,
  severityLevel,
  toCount,
} from '../format.js';

const HOUR_MS = 3600 * 1000;
const DAY_MS = 24 * HOUR_MS;

function text(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function hasId(value) {
  return isRecord(value) && Number.isSafeInteger(value.id) && value.id > 0;
}

function safeId(value) {
  return Number.isSafeInteger(value) && value > 0 ? value : null;
}

/* Normalisation (display only; raw values are kept, never rewritten) ------------------ */

/** Metrics: each total is a non-negative integer or null when missing/invalid. */
export function normalizeMetrics(data) {
  if (!isRecord(data)) {
    throw new TypeError('Invalid metrics payload.');
  }

  return {
    events: toCount(data.total_events),
    alerts: toCount(data.total_alerts),
    critical: toCount(data.critical_alerts),
    activeIncidents: toCount(data.open_incidents),
    devices: toCount(data.devices),
  };
}

/** Returns { items, skipped }: records without a valid id cannot be shown reliably. */
function normalizeList(list, mapItem) {
  if (!Array.isArray(list)) {
    throw new TypeError('Invalid list payload.');
  }

  const items = list.filter(hasId).map(mapItem);

  return { items, skipped: list.length - items.length };
}

export function normalizeEvents(list) {
  return normalizeList(list, (raw) => ({
    kind: 'event',
    id: raw.id,
    timeRaw: raw.event_timestamp,
    time: parseApiDate(raw.event_timestamp),
    severity: raw.severity,
    source: text(raw.source),
    type: text(raw.event_type),
    signature: text(raw.signature),
    category: text(raw.category),
    protocol: text(raw.protocol),
    srcIp: text(raw.src_ip),
    dstIp: text(raw.dst_ip),
    deviceId: safeId(raw.device_id),
    processed: raw.processed_at !== null && raw.processed_at !== undefined && text(String(raw.processed_at)) !== '',
  }));
}

export function normalizeAlerts(list) {
  return normalizeList(list, (raw) => ({
    kind: 'alert',
    id: raw.id,
    timeRaw: raw.detected_at,
    time: parseApiDate(raw.detected_at),
    severity: raw.severity,
    source: text(raw.source),
    type: text(raw.alert_type),
    title: text(raw.title),
    signature: text(raw.signature),
    category: text(raw.category),
    status: text(raw.status),
    deviceId: safeId(raw.device_id),
    eventId: safeId(raw.event_id),
  }));
}

export function normalizeDevices(list) {
  return normalizeList(list, (raw) => ({
    id: raw.id,
    hostname: text(raw.hostname),
    statusKey: deviceStatusKey(raw.status),
    lastSeenRaw: raw.last_seen_at,
    lastSeen: parseApiDate(raw.last_seen_at),
  }));
}

/* Distributions ------------------------------------------------------------------------- */

/** Counts per severity level 4 → 1, plus values outside the 1–4 contract. */
export function severityDistribution(items) {
  const counts = { 4: 0, 3: 0, 2: 0, 1: 0, unknown: 0 };

  items.forEach((item) => {
    const level = severityLevel(item.severity);
    counts[level === null ? 'unknown' : level] += 1;
  });

  return counts;
}

/** Alert status counts for the backend ENUM, plus any value outside it. */
export function alertStatusDistribution(alerts) {
  const counts = Object.fromEntries(ALERT_STATUSES.map((status) => [status, 0]));
  let other = 0;

  alerts.forEach((alert) => {
    if (Object.hasOwn(counts, alert.status)) {
      counts[alert.status] += 1;
    } else {
      other += 1;
    }
  });

  return { counts, other };
}

export const SEEN_BUCKETS = Object.freeze([
  { key: 'day', label: 'Seen within 24 hours' },
  { key: 'week', label: 'Seen 1 to 7 days ago' },
  { key: 'older', label: 'Seen over 7 days ago' },
  { key: 'never', label: 'Never observed' },
]);

/** Recorded status counts and time since last observation (relative to `now`). */
export function deviceObservation(devices, now = Date.now()) {
  const status = Object.fromEntries(DEVICE_STATUSES.map((key) => [key, 0]));
  const seen = Object.fromEntries(SEEN_BUCKETS.map((bucket) => [bucket.key, 0]));

  devices.forEach((device) => {
    status[device.statusKey] += 1;

    if (!device.lastSeen) {
      seen.never += 1;
      return;
    }

    const age = now - device.lastSeen.getTime();

    // A slightly future timestamp (clock skew) counts as recent.
    if (age <= DAY_MS) seen.day += 1;
    else if (age <= 7 * DAY_MS) seen.week += 1;
    else seen.older += 1;
  });

  return { status, seen };
}

/** Largest groups first; the rest folds into `others` (never a generated category). */
function topGroups(values, limit) {
  const counts = new Map();

  values.forEach((value) => counts.set(value, (counts.get(value) || 0) + 1));

  const sorted = Array.from(counts, ([key, value]) => ({ key, value }))
    .sort((a, b) => b.value - a.value || String(a.key).localeCompare(String(b.key)));

  return {
    top: sorted.slice(0, limit),
    others: sorted.slice(limit).reduce((sum, entry) => sum + entry.value, 0),
    groups: sorted.length,
  };
}

/** Event sources ranked by event count. Empty sources are grouped as "". */
export function topEventSources(events, limit = 6) {
  return topGroups(events.map((event) => event.source), limit);
}

/**
 * Devices ranked by the number of events linked to them (events.device_id).
 * Hostnames come from the devices feed when it loaded; otherwise only the id is known.
 */
export function mostActiveDevices(events, devices, limit = 5) {
  const hostnames = new Map((devices || []).map((device) => [device.id, device.hostname]));
  const linked = events.filter((event) => event.deviceId !== null);
  const groups = topGroups(linked.map((event) => event.deviceId), limit);

  return {
    ...groups,
    unlinked: events.length - linked.length,
    top: groups.top.map((entry) => ({
      ...entry,
      hostname: hostnames.get(entry.key) || '',
      inInventory: hostnames.has(entry.key),
    })),
  };
}

/** Pipeline facts recorded on events: alert linkage and processing timestamps. */
export function eventPipeline(events, alerts) {
  const eventIds = new Set(events.map((event) => event.id));
  const alerted = new Set((alerts || []).map((alert) => alert.eventId).filter((id) => id !== null && eventIds.has(id)));

  return {
    total: events.length,
    alerted: alerted.size,
    withoutProcessedTime: events.filter((event) => !event.processed).length,
  };
}

/* Activity feed --------------------------------------------------------------------------- */

/** Events and alerts in one list, newest first; entries without a valid time go last. */
export function mergeActivity(events, alerts) {
  return [...events, ...alerts].sort((a, b) => {
    if (!a.time || !b.time) {
      return (!a.time) - (!b.time) || (a.kind < b.kind ? -1 : 1) || b.id - a.id;
    }

    return b.time.getTime() - a.time.getTime() || (a.kind < b.kind ? -1 : 1) || b.id - a.id;
  });
}

/** Fields that exist in the API responses and can be searched. */
function haystack(item) {
  return [
    item.kind, item.source, item.type, item.title, item.signature, item.category,
    item.protocol, item.srcIp, item.dstIp, item.status,
    item.deviceId !== null && item.deviceId !== undefined ? `device #${item.deviceId}` : '',
  ].filter(Boolean).join('\n').toLowerCase();
}

/** kind: 'all' | 'event' | 'alert'; every search term must match. */
export function filterActivity(items, { kind = 'all', query = '' } = {}) {
  const terms = query.toLowerCase().split(/\s+/).filter(Boolean);

  return items.filter((item) => (kind === 'all' || item.kind === kind)
    && terms.every((term) => haystack(item).includes(term)));
}

export { HOUR_MS };
