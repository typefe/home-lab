# Replacing Your ISP's ONT with a Rooted GPON SFP Module

Many fiber (GPON) ISPs provision their ONT to act as its own router, which means your own router sits behind an extra NAT layer it has no control over — a form of double-NAT that breaks port forwarding and complicates self-hosting, on top of whatever IP policy your account already has. This guide covers eliminating the ONT from the path entirely: terminating GPON directly inside your router VM using a GPON SFP transceiver, reflashed to present your existing line's own identity to your ISP's OLT.

> ⚠️ **This does not bypass CGNAT.** Whether your line gets a public IP or a Carrier-Grade NAT address (`100.64.0.0/10`) is a policy decision made by your ISP's own network (the BNG/CGN gateway), tied to your account or line provisioning — not to which physical device happens to present the GPON identity on the fiber. A rooted SFP presenting your ONT's real, unmodified identity looks, from the OLT's point of view, exactly like your original ONT — nothing about your account's provisioning changes. If your ISP places your account behind CGNAT, this technique will not get you a routable public IP, because the ONT was never the thing deciding that in the first place. What this technique **does** reliably fix is a separate, narrower problem: an ONT that additionally acts as its own router/NAT device, adding a second NAT layer under your own roof, independent of whatever IP your ISP assigns upstream of it.

> ⚠️ **Read before proceeding.** This modifies firmware on hardware you own to present a GPON identity your ISP's network already trusts for *your own line* — it isn't impersonating anyone else's service. That said, it is standard, widely-documented home-networking/hacker-community practice (see [hack-gpon.org](https://hack-gpon.org)), not something invented here, but it likely falls outside your ISP's terms of service regardless of legality in your country. Do your own research before proceeding, and keep your original ONT powered off as a fallback rather than disposing of it.

## How This Works

A GPON ONT authenticates to your ISP's OLT (Optical Line Terminal) using identity fields sent at the PLOAM/registration layer — primarily a **GPON Serial Number** (a vendor code plus a per-device serial) and sometimes a **PLOAM password** and/or a secondary **LOID** (logical ONT ID). The OLT has no way to distinguish hardware beyond these fields. A generic, community-supported GPON SFP module can be reflashed with your *own* ONT's serial number, and the OLT accepts it exactly as it would the original device — because as far as the OLT is concerned, it is the original device.

With the SFP terminating GPON directly inside your router, the ONT is removed from the data path entirely, including any NAT/routing it was doing on its own. Your router's WAN interface gets whatever IP your ISP's network assigns for that line directly, with no intermediate device translating it further — but what that IP actually is (public or CGNAT) is decided entirely by your ISP's provisioning, not by this change. See the warning above.

## Prerequisites

- A router VM with a free SFP+ (or SFP) cage, ideally passed through per the [PCI Passthrough guide](./pci-passthrough-router.md).
- A GPON SFP module compatible with your OLT vendor — sourced via community group-buys or marketplaces, ideally already rooted/pre-modded. Compatibility (chipset, OLT vendor support) varies significantly; check a current community compatibility list before buying.
- Read access to your existing ONT's admin panel, to pull its real GPON identity — see Step 1.
- Patience. Every command below is "confirmed for one specific firmware build" — treat this as a methodology to adapt, not a universal script. Firmware layouts genuinely differ between units.

## Step 1: Get Your ONT's Real GPON Identity

Even ISPs that market their ONT as fully closed/carrier-locked often still expose a read-only status page. Look for:

- **PON Serial Number** (sometimes labeled Equipment ID)
- **LOID** — frequently identical to the serial number, by ISP convention
- **PLOAM/registration password** — often rendered as a masked field in the UI; don't assume it's blank just because it displays empty or dotted-out, it could genuinely be either

If the panel isn't reachable through the normal routed path, some ONTs still answer on a direct/isolated path (e.g. connecting a device straight to one of its LAN ports rather than through your existing router). If the panel is inaccessible entirely, the serial is usually also printed on a label on the unit itself — though the *live* value in NVRAM can, in rare cases, differ from a factory label if the unit was ever re-provisioned.

## Step 2: Bring Up the SFP Module on the Host

Plug the module into your Proxmox host's SFP+ port first (before passing it through to any VM), so you can inspect and reflash it from Linux:

```bash
ethtool -m <nic>
```

This confirms the transceiver's vendor/part number/serial — the **SFF-8472 transceiver EEPROM identity**. This is a completely different thing from the GPON/OMCI identity you're about to change in later steps; the OLT never sees this EEPROM at all, so don't confuse the two when following identity-spoofing instructions.

Most datacenter-grade 10G NICs refuse "unqualified" transceivers by default:

```bash
cat > /etc/modprobe.d/ixgbe.conf << EOF
options ixgbe allow_unsupported_sfp=1
EOF
rmmod ixgbe && modprobe ixgbe
```

(Swap the module name for your NIC family — `ixgbe` for Intel 82599/X520, `i40e` for XL710, etc.)

With this set, many GPON SFPs link up at a clean 1 Gbps to the host NIC **even with no fiber connected at all** — this is only the internal SGMII link between the NIC and the module's own embedded SoC, entirely independent of the PON/fiber side. It's a good sign the module is alive, but proves nothing about GPON registration yet.

## Step 3: Reach the Module's Own Management Interface

These modules typically run embedded Linux — often an OpenWrt-derived image — and expose a small management interface on a private default subnet, reachable only from the host port it's plugged into:

```bash
ip addr add 192.168.1.20/24 dev <nic>   # isolated, unbridged — no risk of colliding with your real LAN
ssh root@192.168.1.10
```

> If the module was purchased already rooted/pre-modded (common via community group-buys), SSH access usually already works with a documented default credential — check your specific community source. Rooting a stock, unmodified unit from scratch (UART access, custom firmware flashing) is a substantially longer process that's out of scope here; see the Sources section below for that path.

## Step 4: Back Up Everything Before Writing Anything

Non-negotiable before touching identity fields — a bad write to the wrong partition can permanently brick the module:

```bash
cat /proc/mtd
# note every mtd partition name and size, then for each one:
dd if=/dev/mtd0 of=mtd0_backup.bin
# repeat for every mtd device listed
```

Also save the current U-Boot environment as plain text for quick reference:

```bash
fw_printenv > fw_printenv_backup.txt
```

Keep copies in at least two separate places. If anything goes wrong later, this is your only way back to a known-good state.

> ⚠️ These modules commonly ship with **two firmware images (A/B partitioning)** for fail-safe updates, and the two images are often **not symmetric** in what they can safely receive. Check which image is currently active (`fw_printenv | grep -i image`) before writing anything, and confirm both images are marked valid. Understand your specific unit's dual-image layout before you start, not after something goes wrong.

## Step 5: Find the *Real* Identity Mechanism for Your Build

This is the step where generic instructions most often lead you astray. The commonly-documented method (see [hack-gpon.org](https://hack-gpon.org)) sets the identity via the U-Boot environment:

```bash
fw_setenv nSerial "<YOUR-REAL-SERIAL>"
```

**Test this, then verify it survives a reboot.** On some firmware builds this variable is legacy/vestigial — the value written here silently reverts after reboot because an init script actually re-derives the live identity from somewhere else entirely (commonly a UCI config section, on OpenWrt-derived builds):

```bash
uci show gpon
# look for something like gpon.onu.vendor_id / gpon.onu.nSerial
```

If your build has a UCI `gpon` section plus a corresponding init binary (often something under `/opt/<vendor>/bin/`, invoked from an init script on every boot), **that** is the real control point, not the U-Boot env:

```bash
uci set gpon.onu.vendor_id="<4-character vendor code — often the first 4 characters of the serial>"
uci set gpon.onu.nSerial="<YOUR-REAL-SERIAL>"
uci commit gpon
/opt/<vendor>/bin/config_onu init    # the lighter "reload" action may not re-apply this — use init
```

Confirm the change actually took via the system log — and critically, **reboot once and check again**, since silent reversion on the wrong layer is exactly the failure mode this step exists to catch:

```bash
logread | grep -i config_onu
# expect the new serial/vendor ID to be set both immediately and again automatically after a fresh reboot
```

> **Don't assume a field doesn't exist just because one guide says so.** Some firmware builds embed the vendor code inside the serial string itself with no separate field to set; others expose a genuinely distinct vendor-ID field that must be set independently. Confirm empirically for your specific build (list the relevant config-binary's directory, `strings` the binary for hints) rather than trusting a single source verbatim.

## Step 6: Physical and Passthrough Migration

Once the module registers successfully on the host:

1. Shut down the router VM.
2. If a different physical port was previously your WAN passthrough device, free it and pass through the SFP+ port instead — see the [PCI Passthrough guide](./pci-passthrough-router.md)'s NIC-binding step for the safe (ID-shared-aware) method.
3. Boot the VM. The physical interface should enumerate under its new driver name (e.g. `ix0` for an Intel 82599-family card under FreeBSD/OPNsense).
4. If your PPPoE setup runs over a VLAN tag on top of the WAN interface (common with fiber ISPs — see the [OPNsense PPPoE & VLAN guide](./virtual-pppoe-router.md)), re-point that **VLAN device's parent interface** to the new physical NIC. A PPPoE device that references the VLAN device logically (rather than by raw interface name) should follow automatically, with no changes needed to username, password, or MTU.
5. Connect the fiber. If registration succeeds, PPPoE should establish on the first attempt using your existing WAN credentials unchanged — only what's terminating the fiber underneath it has changed.

> Some 10G NIC/driver combinations have a documented FreeBSD bug where the "allow unsupported SFP" tunable (`hw.ix.unsupported_sfp="1"` for the `ix` driver) doesn't reliably apply — see [pfSense bugtracker #15287](https://redmine.pfsense.org/issues/15287). Try without it first; only chase that tunable if the interface genuinely refuses to link.

## What Changes, and What Doesn't

| Before (via ONT) | After (via SFP) |
|---|---|
| Router sits behind the ONT's own NAT — a second, uncontrolled NAT layer on top of whatever your account is provisioned for | Router's WAN interface gets your account's assigned IP directly, with no intermediate NAT device in the way |
| Port forwarding blocked by the ONT's own NAT, even on accounts with a public IP | Port forwarding works, **if** your account has a public IP — this step doesn't change your ISP's CGNAT policy either way |
| Tunnel-based access (see the [Remote Access guide](./remote-access-cloudflare.md)) unaffected either way | Still unaffected — outbound-initiated tunnels never depended on having a public IP |
| ONT sits in the path as an extra device/failure point | ONT fully redundant — keep it powered off as a fallback rather than discarding it |

## Known Rough Edge: Asymmetric Upstream Throughput

GPON's upstream is fundamentally different from downstream: it's TDMA/burst-mode, with the OLT performing Dynamic Bandwidth Allocation (DBA) across every ONU sharing that PON, rather than a continuous stream. It's not unusual to see stable, near-line-rate download alongside a **fluctuating upload**, even with clean optics and no configuration mistakes. If you hit this:

- Rule out the obvious first — hardware checksum/TSO/LRO offloading disabled on the WAN interface, and any "VLAN Hardware Filtering"-style setting in your router's interface settings.
- Check whether the module exposes multiple GEM queue/MIB profile files (often somewhere like `/etc/mibs/` on the module itself) — an asymmetric queue profile (different upstream vs. downstream burst sizing) may be swappable for a more symmetric one.
- If neither helps, this may simply be inherent to the optical link quality on your specific burst-mode upstream path rather than a fixable configuration issue.

---
### Sources
- [hack-gpon.org](https://hack-gpon.org) — community wiki for GPON SFP identity spoofing, UART rooting, and dual-image firmware handling
- [pfSense Bugtracker #15287](https://redmine.pfsense.org/issues/15287) — FreeBSD `ix` driver unsupported-SFP tunable reliability
