# Webhooks

**Status: `PLANNED`. The application neither sends nor receives webhooks.**

Verified: no outbound HTTP client anywhere in `backend/src`, no inbound route other than the
ten documented in [endpoints.md](endpoints.md), no signing secret in `.env.example`.

## What an outbound webhook would need (`PROPOSED`)

| Concern | Requirement |
|---|---|
| Trigger | A domain event worth publishing: incident status change, new critical alert |
| Delivery | Retries with backoff, and a dead-letter path; the application has no queue or worker today |
| Authenticity | HMAC signature over the body with a per-subscription secret, plus a timestamp against replay |
| Egress | The application currently opens no outbound connection; firewall rules would be needed |
| Storage | Subscriptions, secrets and delivery attempts — no tables exist |

## What an inbound webhook would need (`PROPOSED`)

Mostly the same requirements as event ingestion: machine authentication, strict input bounds,
idempotency and rate limiting. See
[03-architecture/event-flow.md](../03-architecture/event-flow.md).
