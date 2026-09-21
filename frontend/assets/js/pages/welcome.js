/**
 * CYBERGUARD CAMPUS — Welcome.
 * Public entry page: a 10-second presentation in five chapters, then sign-in.
 * No API request, no user data, no storage. The destination is a fixed relative page.
 *
 * One self-rescheduling timeout aligned to a deadline drives everything: the countdown,
 * the timecode and the chapter shown. The film itself is CSS; this module only sets
 * which chapters have been reached, and freezes every animation while paused.
 * It pauses on request (WCAG 2.2.1) and while the tab is hidden, can jump to a chapter,
 * stops when the page is left, and navigates only once.
 */

import { LOGIN_PAGE } from '../auth.js';

const DURATION_MS = 10000;
const SCENE_MS = 2000;
const SECOND = 1000;

const dom = {
  film: document.getElementById('film'),
  continueBlock: document.getElementById('welcome-continue'),
  controls: document.getElementById('film-controls'),
  value: document.getElementById('countdown-value'),
  text: document.getElementById('countdown-text'),
  timecode: document.getElementById('timecode'),
  continueNow: document.getElementById('continue-now'),
  pause: document.getElementById('countdown-pause'),
  pauseLabel: document.querySelector('.pause-label'),
  status: document.getElementById('countdown-status'),
  captions: Array.from(document.querySelectorAll('.caption')),
  chapters: Array.from(document.querySelectorAll('.chapter')),
};

const SCENES = dom.captions.length;

let timer = 0;
let deadline = 0;
let remainingMs = DURATION_MS;
let scene = -1;
let pausedByUser = false;
let leaving = false;

function now() {
  return performance.now();
}

function clearTimer() {
  if (timer) {
    window.clearTimeout(timer);
    timer = 0;
  }
}

function secondsLeft(ms) {
  return Math.max(0, Math.ceil(ms / SECOND));
}

function sceneFor(ms) {
  return Math.min(SCENES - 1, Math.max(0, Math.floor((DURATION_MS - ms) / SCENE_MS)));
}

function announce(message) {
  dom.status.textContent = message;
}

function chapterName(index) {
  return dom.chapters[index].getAttribute('aria-label');
}

/* Film state --------------------------------------------------------------------------- */

function setScene(next) {
  if (next === scene) {
    return;
  }

  scene = next;
  dom.film.dataset.scene = String(next);

  for (let index = 0; index < SCENES; index += 1) {
    // "reached-n" classes are cumulative: what a chapter built stays on screen afterwards.
    dom.film.classList.toggle(`reached-${index}`, index <= next);
    dom.captions[index].classList.toggle('is-active', index === next);
    dom.chapters[index].classList.toggle('is-active', index === next);
    dom.chapters[index].classList.toggle('is-done', index < next);

    if (index === next) {
      dom.chapters[index].setAttribute('aria-current', 'step');
    } else {
      dom.chapters[index].removeAttribute('aria-current');
    }
  }
}

/** Clears chapters from `index` on, so their animations play again when re-reached. */
function rewindTo(index) {
  for (let i = index; i < SCENES; i += 1) {
    dom.film.classList.remove(`reached-${i}`);
    dom.captions[i].classList.remove('is-active');
    dom.chapters[i].classList.remove('is-active');
  }

  void dom.film.offsetWidth; // Commit the removal so the animations restart.
  scene = -1;
}

function render() {
  const seconds = secondsLeft(remainingMs);
  dom.value.textContent = String(seconds);
  dom.timecode.textContent = `00:${String(10 - seconds).padStart(2, '0')}`;
  setScene(sceneFor(remainingMs));
}

function syncFrozen() {
  dom.film.classList.toggle('is-paused', pausedByUser || document.hidden);
}

/* Leaving ------------------------------------------------------------------------------ */

function leave() {
  if (leaving) {
    return;
  }

  leaving = true;
  clearTimer();
  dom.continueNow.disabled = true;
  dom.pause.disabled = true;
  dom.chapters.forEach((chapter) => {
    chapter.disabled = true;
  });
  announce('Opening sign-in.');
  window.location.assign(LOGIN_PAGE);
}

/* Countdown ---------------------------------------------------------------------------- */

function tick() {
  timer = 0;
  remainingMs = deadline - now();

  if (remainingMs <= 0) {
    remainingMs = 0;
    render();
    leave();
    return;
  }

  render();
  // Wake at the next whole-second boundary before the deadline (chapters start on even seconds).
  timer = window.setTimeout(tick, remainingMs % SECOND || SECOND);
}

function run() {
  if (leaving || pausedByUser || document.hidden) {
    return;
  }

  clearTimer();
  deadline = now() + remainingMs;
  tick();
}

function hold() {
  if (!timer) {
    return;
  }

  clearTimer();
  remainingMs = Math.max(0, deadline - now());
}

function setPaused(paused, { silent = false } = {}) {
  pausedByUser = paused;
  dom.pause.setAttribute('aria-pressed', String(paused));
  dom.pauseLabel.textContent = paused ? 'Resume' : 'Pause';
  dom.text.firstChild.textContent = paused ? 'Paused at ' : 'Continuing in ';

  if (paused) {
    hold();
  } else {
    run();
  }

  syncFrozen();

  if (!silent) {
    announce(paused
      ? 'Presentation paused. Use Continue now when you are ready.'
      : `Presentation resumed. Continuing to sign-in in ${secondsLeft(remainingMs)} seconds.`);
  }
}

function seek(index) {
  if (leaving) {
    return;
  }

  clearTimer();
  remainingMs = DURATION_MS - index * SCENE_MS;

  // Going back (or replaying the current chapter) plays its animations again.
  if (index <= scene) {
    rewindTo(index);
  }

  render();

  // Choosing a chapter plays it, as in a video player: a frozen chapter would show nothing.
  if (pausedByUser) {
    setPaused(false, { silent: true });
  } else {
    run();
  }

  announce(`${chapterName(index)}. Continuing to sign-in in ${secondsLeft(remainingMs)} seconds.`);
}

function restart() {
  leaving = false;
  remainingMs = DURATION_MS;
  dom.continueNow.disabled = false;
  dom.pause.disabled = false;
  dom.chapters.forEach((chapter) => {
    chapter.disabled = false;
  });
  rewindTo(0);
  render();
  setPaused(false, { silent: true });
}

/* Wiring ------------------------------------------------------------------------------- */

function init() {
  if (!dom.film || !dom.continueBlock || !dom.controls || !dom.continueNow || !dom.pause || !dom.status
    || SCENES === 0 || dom.chapters.length !== SCENES) {
    return;
  }

  dom.continueBlock.hidden = false;
  dom.controls.hidden = false;
  // Without this class the page shows the final frame, fully drawn (no JavaScript).
  dom.film.classList.add('is-playing');
  render();

  dom.continueNow.addEventListener('click', leave);
  dom.pause.addEventListener('click', () => setPaused(!pausedByUser));
  dom.chapters.forEach((chapter, index) => {
    chapter.addEventListener('click', () => seek(index));
  });

  // A hidden tab does not count down: the presentation is only timed while it can be seen.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      hold();
    } else {
      run();
    }

    syncFrozen();
  });

  // Leaving the page cancels the timer; returning from the back/forward cache starts afresh.
  window.addEventListener('pagehide', clearTimer);
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      restart();
    }
  });

  run();

  // Announced once, after load (earlier live-region changes are often dropped), never every
  // second: the visible countdown and the chapter text stay readable on demand.
  window.addEventListener('load', () => {
    if (!leaving && !pausedByUser) {
      announce('Presentation playing. Continuing to sign-in automatically in 10 seconds. Use Pause to stay on this page.');
    }
  }, { once: true });
}

init();
