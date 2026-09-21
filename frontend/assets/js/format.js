/**
 * CYBERGUARD CAMPUS — shared formatters and small DOM builders.
 * All dynamic content is created with createElement / textContent / setAttribute.
 */

const LOCALE = 'en-GB';
const SVG_NS = 'http://www.w3.org/2000/svg';
const ICON_SPRITE = new URL('../img/icons.svg', import.meta.url).pathname;

/* Dates ------------------------------------------------------------------ */

// MariaDB DATETIME / DATETIME(6): "YYYY-MM-DD HH:MM[:SS[.ffffff]]" (space or T separator).
const SQL_DATETIME = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?$/;

/**
 * Parses a backend date explicitly instead of relying on browser-specific
 * parsing of "YYYY-MM-DD HH:MM:SS". Zone-less values are read as local time.
 * Returns a Date, or null when the value is missing or invalid.
 */
export function parseApiDate(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }

  const text = value.trim();
  const match = SQL_DATETIME.exec(text);

  if (match) {
    const [, year, month, day, hour, minute, second = '0', fraction = '0'] = match;
    const date = new Date(
      Number(year),
      Number(month) - 1,
      Number(day),
      Number(hour),
      Number(minute),
      Number(second),
      Number(fraction.padEnd(3, '0').slice(0, 3)),
    );

    const isRealDate = date.getFullYear() === Number(year)
      && date.getMonth() === Number(month) - 1
      && date.getDate() === Number(day);

    return isRealDate ? date : null;
  }

  // ISO 8601 with an explicit zone ("2026-09-18T10:00:00Z", "+02:00").
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/.test(text)) {
    const date = new Date(text);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  return null;
}

/** "18 Sep, 14:32" — the year is added when it is not the current one. */
export function formatDateTime(value, fallback = '—') {
  const date = parseApiDate(value);

  if (!date) {
    return fallback;
  }

  const options = {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  };

  if (date.getFullYear() !== new Date().getFullYear()) {
    options.year = 'numeric';
  }

  return new Intl.DateTimeFormat(LOCALE, options).format(date);
}

/** Value for a <time datetime="…"> attribute (local date-time, no zone). */
export function toDateTimeAttribute(value) {
  const date = parseApiDate(value);

  if (!date) {
    return '';
  }

  const pad = (number) => String(number).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** "14:32:05" — used for client-side refresh times. */
export function formatClockTime(date) {
  return new Intl.DateTimeFormat(LOCALE, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).format(date);
}

/**
 * "3 hours ago" relative to the browser clock. Returns '' when the value is missing,
 * invalid or in the future (clock skew): callers then show the absolute date only.
 */
export function formatRelativeTime(value, now = Date.now()) {
  const date = parseApiDate(value);

  if (!date) {
    return '';
  }

  const seconds = Math.round((date.getTime() - now) / 1000);

  if (seconds > 0) {
    return '';
  }

  if (seconds > -60) {
    return 'just now';
  }

  const units = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
  ];
  const [unit, size] = units.find(([, length]) => -seconds >= length);

  return new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' }).format(Math.round(seconds / size), unit);
}

/* Numbers ---------------------------------------------------------------- */

export function formatCount(value) {
  return new Intl.NumberFormat(LOCALE).format(value);
}

/** Returns a finite, non-negative integer, or null. */
export function toCount(value) {
  const number = typeof value === 'string' && value.trim() !== '' ? Number(value) : value;

  return Number.isInteger(number) && number >= 0 ? number : null;
}

/* Severity & status ------------------------------------------------------ */

const SEVERITY_LABELS = {
  1: 'Low',
  2: 'Medium',
  3: 'High',
  4: 'Critical',
};

/** 1–4, or null for anything outside the backend contract. */
export function severityLevel(value) {
  const level = Number(value);

  return Number.isInteger(level) && level >= 1 && level <= 4 ? level : null;
}

export function formatSeverity(value) {
  const level = severityLevel(value);

  return level === null ? 'Unknown' : SEVERITY_LABELS[level];
}

/** Whitelisted class name — never built from raw API text. */
export function severityClass(value) {
  const level = severityLevel(value);

  return level === null ? 'severity-unknown' : `severity-${level}`;
}

const STATUS_LABELS = {
  new: 'New',
  acknowledged: 'Acknowledged',
  resolved: 'Resolved',
  false_positive: 'False positive',
};

/** Alert statuses in lifecycle order (backend ENUM). */
export const ALERT_STATUSES = Object.freeze(Object.keys(STATUS_LABELS));

export function formatStatus(value) {
  if (typeof value !== 'string' || value === '') {
    return 'Unknown';
  }

  if (STATUS_LABELS[value]) {
    return STATUS_LABELS[value];
  }

  const text = value.replace(/_/g, ' ');

  return text.charAt(0).toUpperCase() + text.slice(1);
}

/* Errors ----------------------------------------------------------------- */

/**
 * Turns an ApiError into user-facing copy.
 * `subject` completes sentences such as "to view security metrics".
 */
export function describeError(error, subject) {
  const kind = error && error.kind ? error.kind : 'unknown';

  switch (kind) {
    case 'unauthorized':
      return {
        kind,
        title: 'Sign-in required',
        message: `Your session has expired or you are not signed in. Sign in to view ${subject}.`,
      };
    case 'forbidden':
      return {
        kind,
        title: 'Access denied',
        message: `Your account is not permitted to view ${subject}.`,
      };
    case 'timeout':
      return {
        kind,
        title: 'Request timed out',
        message: `The CYBERGUARD API did not respond in time while loading ${subject}.`,
      };
    case 'network':
      return {
        kind,
        title: 'API unreachable',
        message: `The CYBERGUARD API could not be reached, so ${subject} could not be loaded.`,
      };
    case 'invalid-response':
      return {
        kind,
        title: 'Unexpected response',
        message: `The API returned ${subject} in an unexpected format.`,
      };
    default:
      if (error && error.status >= 500) {
        return {
          kind,
          title: 'Server error',
          message: `The CYBERGUARD API failed while loading ${subject} (HTTP ${error.status}). Try again in a moment.`,
        };
      }

      if (error && error.status === 404) {
        return {
          kind,
          title: 'Not found',
          message: `The API could not find ${subject} (HTTP 404).`,
        };
      }

      if (error && (error.status === 400 || error.status === 422)) {
        return {
          kind,
          title: 'Request rejected',
          message: `The API rejected the request for ${subject} (HTTP ${error.status}).`,
        };
      }

      return {
        kind,
        title: `Unable to load ${subject}`,
        message: error && error.status
          ? `The API returned an error (HTTP ${error.status}). Try again in a moment.`
          : 'An unexpected error occurred. Try again in a moment.',
      };
  }
}

/* DOM builders ------------------------------------------------------------- */

export function clearChildren(node) {
  while (node && node.firstChild) {
    node.removeChild(node.firstChild);
  }
}

/**
 * Minimal element factory.
 * el('p', { className: 'x', text: 'Hello', attrs: { id: 'y' } }, [child, …])
 */
export function el(tag, { className, text, attrs } = {}, children = []) {
  const node = document.createElement(tag);

  if (className) {
    node.className = className;
  }

  if (text !== undefined && text !== null) {
    node.textContent = String(text);
  }

  if (attrs) {
    Object.entries(attrs).forEach(([name, value]) => {
      node.setAttribute(name, String(value));
    });
  }

  node.append(...children);

  return node;
}

/** Decorative sprite icon: <svg class="icon" aria-hidden="true"><use href="…#i-name"></svg> */
export function icon(name, className = 'icon') {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('class', className);
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');

  const use = document.createElementNS(SVG_NS, 'use');
  use.setAttribute('href', `${ICON_SPRITE}#i-${name}`);
  svg.append(use);

  return svg;
}

/** Severity tag: 4-step meter + text label. */
export function severityTag(value) {
  const meter = el('span', { className: 'severity-meter', attrs: { 'aria-hidden': 'true' } }, [
    el('span'), el('span'), el('span'), el('span'),
  ]);

  return el('span', { className: `severity ${severityClass(value)}` }, [
    meter,
    el('span', { text: formatSeverity(value) }),
  ]);
}

/** Status indicator: marker + text label. The data attribute is whitelisted. */
export function statusTag(value) {
  const known = typeof value === 'string' && Object.hasOwn(STATUS_LABELS, value);

  return el('span', {
    className: 'status',
    text: formatStatus(value),
    attrs: { 'data-status': known ? value : 'unknown' },
  });
}

const INCIDENT_STATUS_LABELS = {
  open: 'Open',
  acknowledged: 'Acknowledged',
  investigating: 'Investigating',
  contained: 'Contained',
  resolved: 'Resolved',
  closed: 'Closed',
};

/** Incident statuses in response order (backend ENUM). */
export const INCIDENT_STATUSES = Object.freeze(Object.keys(INCIDENT_STATUS_LABELS));

export function formatIncidentStatus(value) {
  return typeof value === 'string' && Object.hasOwn(INCIDENT_STATUS_LABELS, value)
    ? INCIDENT_STATUS_LABELS[value]
    : formatStatus(value);
}

/** Incident status indicator: same component as alerts, its own whitelist. */
export function incidentStatusTag(value) {
  const known = typeof value === 'string' && Object.hasOwn(INCIDENT_STATUS_LABELS, value);

  return el('span', {
    className: 'status',
    text: formatIncidentStatus(value),
    attrs: { 'data-status': known ? value : 'unknown' },
  });
}

/** Readable label for an enum-like API value: "false_positive" → "False positive". */
export function humanize(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return '';
  }

  const label = value.trim().replace(/[_-]+/g, ' ');

  return label.charAt(0).toUpperCase() + label.slice(1);
}

const DEVICE_STATUS_LABELS = {
  online: 'Online',
  degraded: 'Degraded',
  offline: 'Offline',
  unknown: 'Unknown',
};

/** Device statuses recorded in the inventory (backend ENUM). Not a live connection check. */
export const DEVICE_STATUSES = Object.freeze(Object.keys(DEVICE_STATUS_LABELS));

/** Any value outside the contract is reported as Unknown. */
export function deviceStatusKey(value) {
  return typeof value === 'string' && Object.hasOwn(DEVICE_STATUS_LABELS, value) ? value : 'unknown';
}

export function formatDeviceStatus(value) {
  return DEVICE_STATUS_LABELS[deviceStatusKey(value)];
}

/** Device status indicator: shared .status component, its own whitelist. */
export function deviceStatusTag(value) {
  const key = deviceStatusKey(value);

  return el('span', {
    className: 'status',
    text: DEVICE_STATUS_LABELS[key],
    attrs: { 'data-status': key },
  });
}

/**
 * Empty / error state block.
 * action: { label, href } renders a link, { label, onClick, icon } a button
 * (icon defaults to 'refresh'; pass null for none).
 */
export function stateBlock({ variant = 'empty', iconName, title, message, action, compact = false }) {
  const classes = ['state', `state-${variant}`];

  if (compact) {
    classes.push('state-compact');
  }

  const content = el('div', { className: 'state-content' }, [
    el('p', { className: 'state-title', text: title }),
    el('p', { className: 'state-text', text: message }),
  ]);

  const children = [
    el('span', { className: 'state-icon' }, [icon(iconName)]),
    content,
  ];

  if (action) {
    let control;

    if (action.href) {
      control = el('a', { className: 'button button-primary', attrs: { href: action.href } }, [
        el('span', { text: action.label }),
      ]);
    } else {
      const iconName = action.icon === undefined ? 'refresh' : action.icon;
      control = el('button', { className: 'button', attrs: { type: 'button' } }, [
        ...(iconName ? [icon(iconName)] : []),
        el('span', { text: action.label }),
      ]);
      control.addEventListener('click', action.onClick);
    }

    const wrapper = el('div', { className: 'state-action' }, [control]);

    if (compact) {
      children.push(wrapper);
    } else {
      content.append(wrapper);
    }
  }

  return el('div', { className: classes.join(' ') }, children);
}
