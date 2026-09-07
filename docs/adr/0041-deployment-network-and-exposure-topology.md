# ADR 0041: Deployment Network and Exposure Topology

* **Status**: Accepted
* **Date**: 2026-09-07

## Context

ADR 0005 decided that every service and every long-lived process runs in its own container, and the stack has been built that way from the first phase: the API, the queue supervisor, the scheduler, the database, the cache and the reverse proxy are six containers, each with one process, its own health check and its own restart policy.

That record says nothing about what those containers may reach. Until now they all sat on one bridge network, `alphamaster_network`, which was not internal, and every container on it could open a connection to PostgreSQL and to Redis. While the only members were the six services above, that was a latent problem rather than a live one — each of them legitimately needs the database or the cache, except the proxy.

It stops being latent at the next step. ADR 0001 makes the backend API-only precisely so that a React Admin platform and independent public frontends can be built against it, and ADR 0006 commits to building the first of them. Those are static-asset containers. They talk to the API over HTTP and have no business addressing port 5432, but placing them on the one flat network would have handed them exactly that. A topology decided after those containers exist would be a topology decided under pressure.

Two further couplings belonged to the same question. `horizon` and `scheduler` both declared `depends_on: backend: condition: service_healthy`, so neither could start unless PHP-FPM was already answering — a queue worker waiting on the API contradicts the independent lifecycle ADR 0005 exists to provide. And no port in the composition distinguished what must be reachable from outside from what must not.

## Decision

Two networks, and a rule for which services attach to which.

1. **`alphamaster_edge`** — reachable from outside and able to reach outside. The reverse proxy publishes here.

2. **`alphamaster_data`** — `internal: true`. PostgreSQL and Redis attach to this network and to no other.

3. **The proxy is the only published surface.** `alphamaster-nginx` binds port 80 on the host. In the production composition no other container publishes anything: PostgreSQL and Redis expose their ports to the network and bind nothing on the host.

4. **The admin and public frontend containers, when they exist, attach to the edge network only.** This is the property the whole record exists to establish: a container that serves static assets and calls the API cannot address the database, because it is not on the network the database is on.

5. **`backend`, `horizon` and `scheduler` attach to both networks.** They need the data tier because they read and write it. They need the edge network for a reason that is not obvious and was established by experiment rather than by reading: Docker gives a container attached only to an internal network no route off the host, so a data-only queue worker could not reach a third-party API. Horizon dispatches exactly that work — the Integration module's SMS drivers are outbound HTTP — so a data-only Horizon would fail every integration job while reporting itself healthy.

6. **`horizon` and `scheduler` no longer depend on `backend`.** Both now wait on PostgreSQL and Redis alone. Stopping the API and recreating both from scratch was run as the gate for this change; both reached healthy and both connected to PostgreSQL and Redis while the API stayed down.

7. **Runtime lifecycles stay independent.** Each container is restarted, scaled, replaced and read for logs on its own. The network split constrains what a container may address; it does not couple any two containers' lifecycles, and item 6 removes the one coupling that existed.

8. **A development exception is made against the network, never against the services on it.** Docker discards a port publication for a container attached only to an internal network, and does it silently — the binding stays in the container's own configuration while its actual port map comes back empty, so nothing fails and nothing warns; the port simply refuses connections. `docker-compose.override.yml` therefore lifts `internal` on the data network for development, and PostgreSQL and Redis stay attached to that network and to nothing else.

   The obvious alternative — attaching the two data services to the edge network in the override — was tried first, and an edge-only probe reached port 5432 immediately. It restores publishing by putting the database on the network the admin and frontend containers will occupy, which is the arrangement item 4 exists to forbid. Lifting `internal` relaxes only what has to be relaxed, so the isolation this record establishes holds while developing rather than only once deployed.

This is a practical extension of ADR 0005 into the network dimension, not a revision of it: the container boundaries that record draws are unchanged, and nothing here alters the single-tenant architecture, the database, or any application behaviour.

## Consequences

An admin or public frontend container added later is confined by construction rather than by convention — the isolation is a property of the composition, and adding a service to the wrong network is visible in one file.

The blast radius of a compromised frontend container stops at the API. Reaching the database from it would require reaching the backend first.

PostgreSQL and Redis are unreachable from the host in a deployment. Operating on them means going through a container on the data network, which is what the Adminer tools profile already does.

`horizon` and `scheduler` sitting on the edge network is the one place the two-network model does not express least privilege exactly. They need egress, and this composition has no third network to give it to them separately. Neither listens on any port — both run an artisan command rather than PHP-FPM — so the practical exposure is nil, and the cost of a dedicated egress network was judged higher than the benefit. Should a future service need egress without edge membership, that is when the third network earns its place.

The development composition and the production composition now differ in whether the data network is internal. The gate that guards this is the same one that guards the source mounts: CI reads the baseline on its own, because every other command in the pipeline loads the override automatically and would never see a relaxation reintroduced there.
