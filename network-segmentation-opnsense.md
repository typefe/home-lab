# Segmenting LAN and Service Networks with OPNsense

The [Network Architecture guide](./network-and-pfsense.md) already splits your network into LAN and GUEST. This guide covers a third, narrower split worth adding once you're self-hosting anything internet-facing: a dedicated **services network** for containers that are partially exposed to the outside world (a tunnel client, a torrent client, anything with inbound-reachable ports), isolated from the LAN where your admin devices and management interfaces live.

## Why Segment Services From LAN

If one of your self-hosted containers is ever compromised — through a vulnerable app, an exposed port, a bad dependency — the blast radius should stop at that network segment. The goal is a simple, asymmetric rule:

- **LAN → Services: allowed.** You need to reach Grafana, Nextcloud, etc. from your own devices.
- **Services → LAN: denied**, except for narrow, explicit exceptions (e.g. a monitoring host needs to scrape metrics from your Proxmox hosts).

This is a straightforward additional interface/VLAN on top of what the [Network Architecture guide](./network-and-pfsense.md) already sets up — the interesting part isn't the initial configuration, it's how easy the *isolation* itself is to accidentally defeat, and how to detect that when it happens.

## The Rule Evaluation Order Gotcha

OPNsense's firewall rules are evaluated by a `sequence` field internally — but neither the GUI's rule list nor an exported `config.xml`'s document order necessarily reflects that. **When auditing rules — especially from a raw config export — always sort by the actual `sequence` value, not by the order they appear in.** A rule that looks like it's "near the bottom" in a casual read of the XML can, by sequence number, actually be evaluated first.

Rules also use first-match-wins semantics when the `quick` flag is set (the OPNsense/pf default): the **first** matching rule by sequence order decides the packet's fate, and every rule after it — however specific — never gets evaluated for that packet.

## Case Study: How One Leftover Rule Quietly Defeated an Entire Ruleset

This is worth documenting in detail because the failure mode is genuinely easy to reproduce by accident, and just as easy to overlook.

**The setup:** a services-network interface had a small, deliberately narrow ruleset — DNS, NTP, a specific monitoring exception, a deny-to-LAN rule, then general internet egress.

**What actually happened:** during an unrelated troubleshooting session weeks earlier, a rule literally named `ALLOW_ALL` (`pass any src=any dst=any`, `quick` enabled) had been added to that same interface as a quick diagnostic — and never removed afterward. It sat at a low sequence number, right after the interface's default rules and **before every other rule on that interface**, including the deny-to-LAN rule.

**The effect:** for an unknown period, the services network had **completely unrestricted access to LAN** — any host, any port. A live test made this obvious in seconds: a ping and an HTTPS request from a services-network host to an unrelated admin port on a LAN host both succeeded, when neither should have been reachable at all.

**Finding it:** exporting the full firewall configuration and sorting the rule set by its true `sequence` field (not document order) immediately surfaced `ALLOW_ALL` sitting where it shouldn't have been. Deleting it and re-testing confirmed the isolation was intact again.

**The subtler lesson:** a previously-added "monitoring exception" rule, further down the list, had been assumed to be the thing granting a specific monitoring host its access — it wasn't. `ALLOW_ALL` had already been passing that traffic (and everything else) before the exception rule was ever evaluated, meaning the narrower rule had never actually been load-bearing. Don't assume a rule is doing what you think it's doing just because the traffic it's meant to allow is, in fact, flowing — something broader and earlier in the sequence may be the real reason.

**Takeaway:** audit for generically-named, overly broad rules (`ALLOW_ALL`, `TEMP`, `test`, `debug`, or similar) sitting near the top of an otherwise narrow ruleset on sight — this is exactly the kind of debugging leftover that silently defeats every rule below it, and it's invisible unless you deliberately sort by the real evaluation order rather than trusting a glance at the GUI or a raw export.

## Recommended Rule Order for a Services Interface

| Order | Rule | Purpose |
|---|---|---|
| 1 | Allow → router's own DNS resolver | Local name resolution for containers |
| 2 | Allow → NTP | Time sync |
| 3 | Narrow, explicit allow exceptions (specific source, destination, and port) | e.g. a monitoring host scraping metrics on specific ports |
| 4 | **Deny all → LAN**, with logging enabled | The actual isolation boundary — log it so crossing attempts are visible |
| 5 | Allow → WAN, port 53 (DNS) | Only needed if something here does its own external DNS lookups, e.g. an ACME DNS-01 challenge — see the [Local HTTPS guide](./local-https-split-dns.md) |
| 6 | General internet egress (80/443/etc.) | For containers that need outbound internet access |

Two details that are easy to miss when building this list:

- **Rules 5 and 6 must come *after* rule 4, not before it.** A general "allow to any destination on port 443" rule placed before the LAN-deny rule will happily let services-network traffic reach LAN hosts on port 443 too — the destination scope of "any" doesn't stop at WAN just because that's what the rule was written for.
- Enable logging (`log=1`) specifically on the deny rule. It's the cheapest way to notice if something unexpected is trying to cross the boundary, and it's what makes an audit like the one above possible in the first place.

## A Routing Gotcha That Looks Exactly Like a Firewall Problem

If a specific host on a segmented network suddenly becomes completely unreachable from another segment — ping and TCP both timing out, nothing obviously wrong in the firewall rules — don't assume it's a rule problem before checking `ip route` / `ip addr` **on the unreachable host itself**. A manually-added diagnostic IP alias on a router-adjacent host (e.g. `ip addr add 192.168.20.250/24 dev vmbr1`, added during some earlier troubleshooting and never removed) creates a *route*, not just an address — it can make that host believe an entire remote subnet is directly, locally attached, causing it to ARP for a remote peer instead of routing replies back through the real gateway. The reply then silently dies with no firewall rule involved at all.

The tell, if you get this far: `tcpdump` on the affected host while triggering the connection shows the request arriving fine, but also shows an ARP request for an address that clearly isn't on that host's own directly-connected segment — a router shouldn't ever ARP for an address on a subnet it only reaches via another hop. See the [PCI Passthrough guide](./pci-passthrough-router.md)'s note on always removing temporary diagnostic aliases — this is exactly the failure mode that note is warning about.

---

## Appendix: OPNsense REST API Quick Reference

Useful once you're managing more than a handful of rules/services by hand — OPNsense exposes a full REST API for most modern (MVC-migrated) modules.

**Auth:** HTTP Basic, using a key/secret pair generated under **System → Access → Users → (user) → API keys**.

**Finding the right endpoint for a GUI page:** hit `/api/core/menu/search` and grep the returned JSON tree for the page's visible name — this maps a GUI menu entry to its real controller name, which is often not what you'd guess (e.g. "Destination NAT" in the GUI, not "Port Forward", maps to the `d_nat` controller).

| Feature | Module / Controller | Notes |
|---|---|---|
| Filter rules | `firewall` / `filter` | `searchRule`, `getRule`, `addRule`, `setRule`, `delRule` |
| Destination NAT | `firewall` / `d_nat` | Same CRUD action names as above |
| Virtual IPs | `interfaces` / `vip_settings` | `searchItem`/`getItem`/`addItem`/`setItem`/`delItem`, apply via `POST .../reconfigure` |
| Unbound host overrides | `unbound` / `settings` | `searchHostOverride`/`getHostOverride`/`addHostOverride`/..., apply via `POST /api/unbound/service/reconfigure` |

**Field-format gotchas that don't match naive guesses:**

- A **Virtual IP**'s `network` field is not a bit count or netmask — it wants the **full CIDR of the alias address itself** (e.g. `10.0.0.101/24`). Passing just `"24"` or a plain netmask fails with an unhelpful validation error.
- A **Destination NAT** rule's `destination.address` field silently accepts the request (HTTP 200, `"result":"saved"`) but **does not actually store anything** — no error, just an empty value. The field that actually works for a single-host destination is `destination.network`, formatted as `"IP/32"`.
- **Always re-`GET` after a write** to confirm what was actually persisted. A "saved" response is not proof the value stuck — this API will report success while silently dropping a field.
- Filter/NAT rule changes made via the API don't always propagate to the live, running ruleset automatically — in some environments you still need to click **Apply changes** in the GUI after a scripted change. Test the live behavior after any automated rule change rather than trusting the API response alone.

---
### Sources
- [OPNsense Documentation — Firewall Rules](https://docs.opnsense.org/manual/firewall.html)
- [OPNsense Documentation — API](https://docs.opnsense.org/development/api.html)
