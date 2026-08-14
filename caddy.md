## Local Reverse Proxy with Caddy

This section documents the Caddy reverse proxy running on the services network, used to serve `*.example.com` to **LAN clients only**. Internet-facing access to the same services goes through Cloudflare Tunnel instead (see [remote-access-cloudflare.md](./remote-access-cloudflare.md)) and does **not** pass through Caddy — the two paths are independent and both terminate at the same backend containers. The OPNsense side (Virtual IPs, NAT, split-DNS) that routes LAN clients here is documented separately in [Local HTTPS Access with Split-DNS](./local-https-split-dns.md) — read that first if you're building this from scratch.

### Why a separate LAN-only proxy exists
- Cloudflare Tunnel (`cloudflared`) already exposes every service to the internet with automatic TLS at Cloudflare's edge.
- Caddy exists purely so LAN clients can reach `https://graf.example.com` etc. directly over the local network, with a real trusted certificate, without a round trip out to Cloudflare and back.
- LAN clients resolve `*.example.com` to private Virtual IPs via OPNsense's Unbound (split-horizon DNS) rather than Cloudflare's public IPs, and OPNsense NAT forwards each VIP's 80/443 to Caddy.

### Where it runs
- **Container:** an unprivileged LXC with `nesting=1` (needed to run Docker inside the container), hostname `caddy`.
- **IP:** on the services network (e.g. `192.168.40.90`).
- **Deployment:** Docker Compose at `/opt/caddy/`.

### 1. Custom Caddy build (Dockerfile)
The stock Caddy image doesn't include the Cloudflare DNS plugin, so it's built with `xcaddy`:

```dockerfile
FROM caddy:2-builder AS builder
RUN xcaddy build --with github.com/caddy-dns/cloudflare

FROM caddy:2-alpine
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
```

### 2. docker-compose.yml
```yaml
services:
  caddy:
    build: .
    container_name: caddy
    restart: unless-stopped
    ports:
      - "443:443"
      - "80:80"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    env_file:
      - .env

volumes:
  caddy_data:
  caddy_config:
```

### 3. .env
```
CF_API_TOKEN=<a Cloudflare API token scoped to DNS edit on the example.com zone>
```

### 4. Caddyfile
```caddyfile
{
	email your-email@example.com
}

(cf_dns) {
	tls {
		dns cloudflare {env.CF_API_TOKEN}
	}
}

graf.example.com {
	import cf_dns
	reverse_proxy 192.168.40.15:3000
}

jelly.example.com {
	import cf_dns
	reverse_proxy 192.168.40.65:8096
}

# ...one block per hostname, each pointing at the real backend IP:port
```

Each site block's header (e.g. `graf.example.com {`) is both the routing key (matched against the TLS SNI / HTTP Host header from the client) and the hostname Caddy requests a certificate for — there's no separate SNI configuration.

### Why DNS-01 (Cloudflare API) instead of the default HTTP-01 challenge
Caddy here is never reachable from the public internet — only LAN clients reach it, via OPNsense NAT. Let's Encrypt's default HTTP-01 challenge requires Let's Encrypt's own servers to reach port 80 on the host from the internet, which is impossible here. The `dns cloudflare {env.CF_API_TOKEN}` directive instead proves domain ownership by creating a temporary TXT record via the Cloudflare API — this works regardless of whether the server is internet-reachable, and is why `caddy-dns/cloudflare` had to be compiled in.

This gets Caddy a real, publicly-trusted Let's Encrypt certificate per hostname, auto-renewed, with zero manual certificate handling. It's also why plain HTTP (port 80) is forwarded to Caddy too — Caddy's automatic HTTPS handles the HTTP → HTTPS redirect itself.

### End-to-end request flow (LAN path)
1. LAN client resolves `graf.example.com` via OPNsense's Unbound → private Virtual IP `192.168.20.101`.
2. OPNsense NAT rewrites the destination to `192.168.40.90:443` (Caddy).
3. Caddy terminates TLS using the SNI to pick the matching site block and certificate.
4. Caddy reverse-proxies the request in plaintext HTTP to the real backend, e.g. `192.168.40.15:3000` (Grafana).

### Relationship to Cloudflare Tunnel
`cloudflared` (see [remote-access-cloudflare.md](./remote-access-cloudflare.md)) has its own `ingress:` rules in `/etc/cloudflared/config.yml` that map each hostname **directly** to the same backend IP:ports Caddy uses — it does not route through Caddy. TLS for that path terminates at Cloudflare's edge, not on this host. The two paths (Caddy for LAN, cloudflared for WAN) are maintained in parallel and must be updated together when a backend's IP or port changes.
