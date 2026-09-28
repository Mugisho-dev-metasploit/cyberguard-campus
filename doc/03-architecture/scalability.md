# Scalability

An honest reading of what the current design supports.

## Current limits

| Area | Today | Consequence |
|---|---|---|
| Read endpoints | Full table scan, no pagination, no filter | Response size and memory grow linearly with the data; the interface renders every row |
| Sessions | PHP files on local disk (`/opt/lampp/temp`, shared with other applications) | A second web node cannot read them: no horizontal scaling without shared session storage |
| Database | One MariaDB instance, one connection per request | Vertical scaling only |
| Caching | None | Every read hits the database |
| Background work | None | Everything must fit in a request |
| Throttle table | Rows purged after 2 hours idle, in the request that notices them | Bounded, but the purge competes with the request |
| Audit logs | Never purged by the application | Grows for ever without a retention job |
| Static assets | Served by Apache, no cache headers set by the application | Fine at this size |

## What would break first

1. **List endpoints**, as soon as `events` or `alerts` hold more than a few thousand rows: both
   the JSON payload and the browser rendering.
2. **Monitoring page**, which loads four endpoints and derives everything client-side.
3. **Audit growth** under a sustained attack: failures are bounded by bcrypt cost (~235 ms each)
   and throttled attempts are recorded at most once per address per minute, which caps the rate
   but not the total.

## Ordered improvements (`PROPOSED`)

| Step | Effect |
|---|---|
| Pagination + filters + `LIMIT` on every list endpoint | Removes the main bottleneck |
| Server-side aggregation for the dashboard and monitoring views | Removes large payloads |
| Retention jobs for `events`, `alerts` and `audit_logs` | Bounds storage |
| Shared session storage (database or Redis) and a dedicated PHP-FPM pool | Enables more than one web node |
| Read replica or materialised counters | Only after measurement; nothing here justifies it yet |

No load test exists in the repository, so every figure above is structural reasoning, not a
measurement — except the bcrypt cost, which is measured by `LoginTimingTest`.
