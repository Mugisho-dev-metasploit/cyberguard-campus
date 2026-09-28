# Sensors

**Status: `PLANNED`.**

## Intended role

A sensor is any probe that produces security records: an IDS instance, a firewall log shipper,
an endpoint agent, a network collector.

## What exists today

- No agent, no collector, no registration endpoint.
- No credential model for machines: the API only accepts browser-style sessions.
- The `devices` table can *describe* a sensor (`device_type = 'sensor'`), but nothing connects
  to one, and `status` / `last_seen_at` are values someone writes, never measurements.

## What integrating sensors would require

| Need | Detail |
|---|---|
| Machine authentication | A credential per sensor, revocable, distinct from user accounts |
| Ingestion endpoint | Write path with strict bounds, idempotency and per-sensor rate limits |
| Enrolment | How a sensor is registered and mapped to a device row |
| Heartbeat | Regular signal updating `last_seen_at`, with staleness rules |
| Backpressure | What happens when the platform is slower than the sensor |

## Where it would attach

Records land in `events` (and, once qualified, `alerts`); see
[../03-architecture/event-flow.md](../03-architecture/event-flow.md) and
[../03-architecture/integration-architecture.md](../03-architecture/integration-architecture.md).
