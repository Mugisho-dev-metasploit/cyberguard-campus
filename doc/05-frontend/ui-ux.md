# UI and UX conventions

## Visual system

A dark operations theme ("Campus Signal"): ink-navy surfaces, a cyan accent, and a four-step
severity scale (low, medium, high, critical). Colours, spacing, radii and durations are CSS
custom properties declared in `app.css`; pages never hard-code a colour.

Typography: IBM Plex Sans for text, IBM Plex Mono for identifiers, counters and timestamps,
self-hosted as `woff2`.

## Layout

| Breakpoint | Behaviour |
|---|---|
| ≥ 920 px | Persistent sidebar, content column beside it |
| < 920 px | Sidebar becomes a drawer behind a menu button, with overlay and focus trapping |

Tables collapse to stacked rows on narrow screens; the incident detail is a side panel on wide
screens and a dialog on narrow ones.

## States

Every data surface has four explicit states: loading, empty, error and content. Empty states say
what would appear there; error states say what failed and offer a retry when one makes sense.

## Accessibility

- Skip link to the main content; visible keyboard focus.
- The drawer sets `inert` on the background and returns focus to the toggle on close.
- Charts are duplicated as tables; status and severity are never colour alone (a label is always
  present).
- Live regions announce sign-in progress and sign-out failures.
- `prefers-reduced-motion` is respected, including by the welcome presentation.

## Copy

Short, factual, in the interface's voice: "Sign-in failed. Check your identifier and password,
then try again." Backend messages are never shown verbatim, except the incident validation
messages, which are written for the operator.
