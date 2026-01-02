# Keycloak Cluster (PostgreSQL, 2 nodes)

Local Keycloak 26.x cluster with two nodes, shared PostgreSQL database, and an external directory for extensions.

## How to run
- Requirements: Docker + Docker Compose.
- Copy `.env.example` to `.env` and adjust credentials if needed.
- Start: `docker compose up -d`.
- Stop: `docker compose down`.

## Access
- Admin: `admin` / `admin`.
- Nginx load balancer: http://localhost:8080 (routes to both nodes)
- Direct access (if needed): http://localhost:8081, http://localhost:8082

## Extensions
- Place your JARs/extension folders into `extensions/` (mounted to `/opt/keycloak/providers` on both nodes).
- After changing `extensions/`: `docker compose restart keycloak-1 keycloak-2`.

## Cluster notes
- Cache stack `tcp` with TCPPING discovery: nodes communicate directly over the Docker network.
- Starting with plain `start`; hostname strict modes are disabled for local convenience.
- Nginx uses `least_conn` balancing.
- Nginx waits until both Keycloak nodes open port 8080 (simple `nc` wait) to avoid early 502s.
