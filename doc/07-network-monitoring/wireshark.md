# Wireshark and packet capture

**Status: `PLANNED`.**

## Intended role

Deep investigation of a specific incident, from captured traffic.

## What exists today

Nothing: no capture, no storage of packet data, no reference to a pcap anywhere in the
repository, and no way to attach a file to an incident.

## What supporting it would require

| Need | Detail |
|---|---|
| Evidence storage | Files outside the web root, with their own access control |
| Attachment model | A link between an incident and its evidence, with who added it and when |
| Access control | A capture contains far more sensitive data than the records the platform holds; role-based reading would not be enough |
| Retention | Captures are large; retention and secure deletion must be defined before storage |

Until then, captures live in the analyst's own tooling and are referenced by hand in the
incident description.
