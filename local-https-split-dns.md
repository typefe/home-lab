# Local HTTPS Access to Self-Hosted Services (Split-DNS + Virtual IPs)

The [Remote Access with Cloudflare Tunnels guide](./remote-access-cloudflare.md) gets `*.example.com` working from anywhere, with automatic TLS terminated at Cloudflare's edge. But from inside your own LAN, that means every request makes a round trip out to the internet and back — pointless latency for traffic that never needs to leave the building. This guide adds a second, LAN-only path: real, trusted HTTPS certificates served directly by an internal reverse proxy, with OPNsense's DNS resolver transparently sending local clients to a private IP instead of the public internet.

## The Problem DNS Alone Can't Solve

A hostname resolves to exactly one IP — it can't also carry a port. Pointing local DNS straight at a backend's real IP means you're stuck typing `http://service.example.com:8096` everywhere. Worse, once a browser has ever loaded that hostname over HTTPS via Cloudflare, cached HSTS will often force-upgrade even a plain `http://` request to `https://` automatically — breaking a portless, cert-less approach outright.

**The pattern that solves both problems:** one dedicated Virtual IP alias per service on your router's LAN interface, a NAT rule forwarding that VIP's 80/443 to a single internal reverse proxy, and your router's DNS resolver pointing each service's hostname at its own VIP.

```
Client (LAN/Wi-Fi)
  │  DNS: service.example.com → 192.168.20.101 (OPNsense Unbound host override)
  ▼
OPNsense — Virtual IP 192.168.20.101 (LAN interface alias)
  │  Destination NAT: :80 → proxy:80, :443 → proxy:443
  ▼
Reverse proxy container (services network, 192.168.40.90)
  │  Routes by Host header / SNI, holds a real Let's Encrypt certificate
  ▼
Real backend (e.g. 192.168.40.55:80)
```

This is entirely native OPNsense functionality — no extra router-side plugins. The only new component is a small reverse-proxy container; see [Local Reverse Proxy with Caddy](./caddy.md) for that half.

## Why Not Simpler Alternatives First

Two more obvious approaches are worth ruling out explicitly:

- **DNS override straight to the backend's real IP, plain HTTP, port in the URL.** Works, but you're stuck typing a port on every request, and it doesn't solve the HSTS force-upgrade problem the moment a client has ever loaded that hostname over HTTPS.
- **Router-side ACME + reverse-proxy plugins** (an ACME client plugin plus a router-hosted HAProxy instance). Technically works, but it adds real complexity to your router — the single most critical piece of infrastructure on your network — to solve a problem (local certificate trust) that a small, disposable, independently-updatable container handles just as well without touching the router's own software surface.

The Virtual-IP-per-service pattern below avoids both trade-offs: no ports, no router-side proxy software, and the reverse proxy itself is just another container you can rebuild independently at any time.

## Step 1: Create a Virtual IP per Service

**Interfaces → Virtual IPs → Settings → Add**, one per service:

| Field | Value |
|---|---|
| Mode | IP Alias |
| Interface | LAN |
| Address | A free IP in your LAN subnet, outside your DHCP pool (e.g. `192.168.20.101/24`) |
| Description | The service name |

Repeat for each service, incrementing the address (`.101`, `.102`, …). Keep a running table as you go — it's the source of truth for the NAT and DNS steps that follow:

| Service | Virtual IP | Backend |
|---|---|---|
| Grafana | 192.168.20.101 | 192.168.40.15:3000 |
| Jellyfin | 192.168.20.102 | 192.168.40.65:8096 |
| Nextcloud | 192.168.20.103 | 192.168.40.55:80 |

> Pick addresses outside your DHCP pool's range to avoid a future collision — check **Services → DHCPv4 → [LAN]** for the current pool boundaries.

## Step 2: Forward Each VIP to the Reverse Proxy

**Firewall → NAT → Port Forward → Add**, one rule per VIP, per port (80 and 443):

| Field | Value |
|---|---|
| Interface | LAN |
| Destination | The Virtual IP (not "LAN address" or "any") |
| Destination port | 80 (repeat for 443) |
| Redirect target IP | Your reverse proxy container's IP |
| Redirect target port | Same port (80 or 443) |

This is what makes `https://service.example.com` — once resolved to its VIP — actually land on the reverse proxy instead of nowhere.

## Step 3: Point Each Hostname at Its VIP (Split-DNS)

**Services → Unbound DNS → Overrides → Host Override → Add**, one per service:

| Field | Value |
|---|---|
| Host | `service` |
| Domain | `example.com` |
| Type | A |
| IP | The matching Virtual IP |

This is the actual "split" in split-DNS: LAN clients, using your router as their DNS server, resolve to the private VIP; anyone outside your network resolving the same hostname still gets Cloudflare's public IP via your regular public DNS records — the two paths never interfere with each other.

## Step 4: The GUI Port Conflict

OPNsense's own web GUI listens on port 443 by default, and it protects **every IP bound to the LAN interface** — including your new Virtual IP aliases — with an anti-lockout rule that takes priority over your NAT rules. Left as-is, HTTPS requests to a VIP land on OPNsense's own login page (sometimes with a "DNS Rebind attack" warning) instead of your NAT rule.

**Fix: move the GUI to a different port.** **System → Settings → Administration → TCP Port**, e.g. `8443`.

> ⚠️ **Non-obvious side effect: this also "claims" port 80.** The moment you move the HTTPS GUI port, OPNsense automatically creates a *second* anti-lockout rule for port 80 across the entire LAN interface (VIPs included) — presumably to redirect old bookmarks to the new port. The result: `http://service.example.com` now lands on OPNsense's own redirect too, the same symptom as before, just on the other port.
>
> **Fix: on the same Administration page, check "Disable webConfigurator redirect rule."** This frees port 80 across the whole LAN interface, VIPs included, without weakening protection on your actual GUI port.

**When verifying any of this, check the response's `Server:` header or body — not just the HTTP status code.** A redirect coming from OPNsense's own GUI can return a perfectly plausible 301/302, indistinguishable at a glance from your backend's own redirect. Checking only the status code can make a broken NAT path look like it's working correctly.

## Step 5: DNS Egress for ACME Challenges (If Applicable)

If your reverse proxy uses a DNS-01 ACME challenge to get real certificates (see [Local Reverse Proxy with Caddy](./caddy.md)), it needs to reach your DNS provider's **authoritative** nameservers directly — deliberately bypassing your local resolver, which is the standard, intended behavior of DNS-01 propagation checks. If your services network is otherwise locked down to only your router's own resolver (per the [Network Segmentation guide](./network-segmentation-opnsense.md)), add one narrow exception: services network → any, TCP/UDP port 53. Placed *after* your deny-to-LAN rule, this only opens outbound DNS to the internet — it doesn't reopen a path into LAN.

## Testing Correctly

When testing an SNI-routed HTTPS backend (which a Host-header-based reverse proxy is), **`curl -H "Host: x"` does not set the TLS SNI** — it only changes the HTTP header, sent *after* the TLS handshake has already picked a certificate/route based on whatever hostname (or bare IP) was actually used to connect. This produces confusing, plausible-looking TLS errors that have nothing to do with your NAT or DNS setup. Use `--resolve` instead, which keeps the real hostname in the request while pinning its DNS resolution:

```bash
curl --resolve service.example.com:443:192.168.20.101 https://service.example.com/
```

## Client-Side Note: Split-DNS on `systemd-resolved` Linux Clients

If a Linux laptop using `systemd-resolved` seems to ignore your split-DNS override entirely — resolving to the public IP even with the correct DNS server configured — check whether that DNS server is scoped to a specific "routing domain" rather than being the default for all queries. A per-connection routing-domain scope limited to, say, an internal `.lan` suffix won't automatically also apply to an unrelated domain like `example.com`; those queries can fall through to whatever DNS server is otherwise marked default (a VPN's DNS server is a common culprit here). Fix it on the specific network connection profile:

```bash
nmcli connection modify "<your-wifi-profile-name>" ipv4.dns-search "~." ipv6.dns-search "~."
```

`~.` marks this connection's DNS server as the default resolver for *all* domains, not just a specific suffix — and this only affects the one profile you apply it to.

> When testing, plain `dig` (no arguments) often bypasses `systemd-resolved` and reads `/etc/resolv.conf` directly, which can show a misleadingly different answer than what applications actually receive. Use `getent hosts <host>` or `resolvectl query <host>` to see what your applications' actual resolver returns.

---
### Sources
- [OPNsense Documentation — Virtual IPs](https://docs.opnsense.org/manual/vip_single.html)
- [OPNsense Documentation — Unbound DNS](https://docs.opnsense.org/manual/unbound.html)
