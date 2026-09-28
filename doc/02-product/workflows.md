# Workflows

## Sign-in

```mermaid
sequenceDiagram
    participant B as Browser
    participant A as API (LoginController)
    participant T as LoginThrottle
    participant S as AuthenticationService
    participant M as SessionManager
    participant D as Audit

    B->>A: POST /login {identifier, password} (application/json)
    A->>A: Content-Type and Origin checks (415 / 403)
    A->>A: Shape and length checks (401)
    A->>T: attempt(identifier, address)
    alt bucket blocked
        T-->>A: wait seconds
        A->>D: auth.login.throttled
        A-->>B: 429 + Retry-After
    else allowed
        A->>S: authenticate(identifier, password)
        S->>S: one bcrypt verification (account hash or reference hash)
        alt rejected
            A->>D: auth.login.failure
            A-->>B: 401 Invalid credentials.
        else accepted
            S->>S: upgrade hash if weaker than policy
            A->>M: authenticate(user) → new session id
            alt session not established
                A-->>B: 500 Unable to sign in.
            else established
                A->>T: clear buckets
                A->>D: auth.login.success
                A-->>B: 200 + session cookie
            end
        end
    end
```

## Protected request

```mermaid
sequenceDiagram
    participant B as Browser
    participant R as Router
    participant AM as AuthenticationMiddleware
    participant AZ as AuthorizationMiddleware
    participant C as Controller

    B->>R: GET /api/... (session cookie)
    R->>AM: route middleware
    AM->>AM: session valid? account still active?
    alt no
        AM-->>B: 401 (session destroyed if it was revoked)
    else yes
        AM->>AM: refresh role from the database
        AM->>AZ: next
        AZ->>AZ: role allowed on this route?
        alt no
            AZ-->>B: 403 Insufficient permissions.
        else yes
            AZ->>C: next
            C-->>B: 200 {success, message, data}
        end
    end
```

## Incident lifecycle

See [03-architecture/incident-flow.md](../03-architecture/incident-flow.md) for the state
machine and the write sequence.

## Sign-out

`POST /logout` destroys the session server-side, expires the cookie and answers the same
generic 200 whether or not a session existed. See
[06-security/authentication-security.md](../06-security/authentication-security.md).
