# Bare-Metal Router Performance with PCI Passthrough

The [Network Architecture guide](./network-and-pfsense.md) builds your router VM (pfSense/OPNsense) on bridged VirtIO NICs, which is the right default for most home labs. This guide covers the next step: handing the router VM **direct, exclusive control** of one or more physical NICs via PCI passthrough instead of a bridge.

## Why Passthrough Instead of a Bridged NIC

With a bridged VirtIO NIC, every packet crosses the Proxmox host's software bridge and the VirtIO driver layer before it ever reaches the guest. That's invisible overhead for typical LAN traffic, but it starts to matter when:

- Your WAN link needs to sustain near-line-rate PPPoE or routing at gigabit+ speeds, where every extra hop of CPU-bound packet copying counts.
- You're using a SFP+/10G card whose vendor-specific tunables (link-speed negotiation, unsupported-transceiver overrides) only exist in the guest OS's own driver — a bridge hides the physical NIC from the guest entirely, so those tunables have nothing to act on.
- You want the router VM to own specific physical ports directly, with no possibility of the host's own networking stack touching that traffic.

Passthrough gives the guest exclusive DMA access to the physical device — as close to bare-metal performance as a VM gets.

## Prerequisites

- A CPU and motherboard with IOMMU support (Intel VT-d / AMD-Vi), enabled in the BIOS.
- A NIC you can dedicate entirely to the VM — either a spare port on a multi-port card, or a dedicated add-in card. The host loses direct access to whatever you pass through.
- Comfort with `lspci` and editing GRUB config — a mistake here can cost you console/SSH access to the host until you find a keyboard and monitor for it.

## Step 1: Enable IOMMU in the BIOS

Menu names vary by vendor, but you're looking for two separate settings:

1. **VT-d** (sometimes under Chipset or North Bridge Configuration) — this is the actual IOMMU toggle passthrough depends on.
2. **Intel VMX / Virtualization Technology** — base virtualization (VT-x), required for Proxmox to run VMs at all, separate from VT-d.

Both need to be **Enabled**. Leave IOMMU-adjacent options like "Pre-boot DMA Protection" or "X2APIC Opt-Out" at their defaults unless you have a specific reason to change them.

## Step 2: Enable IOMMU at the Kernel Level

This is where hosts diverge depending on how Proxmox was installed, and it's the single most common place this setup silently fails to take effect. Check which boot method your host uses first:

```bash
proxmox-boot-tool status
```

- **If it errors** (e.g. `/etc/kernel/proxmox-boot-uuids does not exist`) — your host boots via plain GRUB (typical for ext4 installs). `update-grub` alone is sufficient.
- **If it prints something like** `System currently booted with uefi... configured with: grub` — this host boots via `proxmox-boot-tool`, which manages its own copy of the boot config on the EFI System Partition. Here, **`update-grub` on its own does not update what actually gets booted** — you must also run `proxmox-boot-tool refresh`, or your kernel parameters will silently never apply, no error, no warning.

```bash
cp /etc/default/grub /etc/default/grub.bak-$(date +%Y%m%d)
sed -i 's/^GRUB_CMDLINE_LINUX_DEFAULT=.*/GRUB_CMDLINE_LINUX_DEFAULT="quiet intel_iommu=on iommu=pt"/' /etc/default/grub
# AMD CPUs: use amd_iommu=on instead of intel_iommu=on

update-grub
proxmox-boot-tool refresh   # only needed if proxmox-boot-tool manages this host — see above
reboot
```

**Verify after reboot:**

```bash
cat /proc/cmdline
# should include: intel_iommu=on iommu=pt

dmesg | grep -e DMAR -e IOMMU
# look for: DMAR-IR: Enabled IRQ remapping in x2apic mode
```

If neither line shows up, don't proceed to passthrough — go back and check whether this host needed the `proxmox-boot-tool refresh` step.

## Step 3: Load the VFIO Kernel Modules

```bash
echo -e "vfio\nvfio_iommu_type1\nvfio_pci" >> /etc/modules
update-initramfs -u -k all
reboot
```

Verify: `lsmod | grep vfio` should list all three.

## Step 4: Identify and Bind the Target NIC

```bash
lspci -nnk | grep -A3 -iE "ethernet|network"
```

Note the **vendor:device ID** in brackets for the port you want to pass through (e.g. `8086:10fb`).

> ⚠️ **Critical safety check before binding anything.** Multi-port NICs very often share **one identical vendor:device ID across every port on the card** — including whichever port is carrying your Proxmox host's own management interface (`vmbr0`). A static binding rule like `options vfio-pci ids=8086:125c` matches by ID, not by physical port — if your management NIC shares that ID with the port you're trying to dedicate to the VM, you will unknowingly bind your own SSH/GUI port to vfio-pci too, and lose access to the host at the next reboot.
>
> Run `lspci -nnk | grep -B2 <vendor:device>` and confirm none of the matching ports is the one carrying host management before doing a blanket ID-based bind. If it shares an ID with the mgmt port, use the **PCI-address-specific** method instead (see the box below) and skip the static rule entirely.

**Option A — static binding by vendor:device ID** (safe only if the ID is unique to the port you're passing through):

```bash
cat > /etc/modprobe.d/vfio.conf << EOF
options vfio-pci ids=<vendor:device>
EOF
update-initramfs -u -k all
reboot
```

Verify: `lspci -k | grep -A3 -iE "ethernet|network"` should show `Kernel driver in use: vfio-pci` for that device.

**Option B — PCI-address-specific passthrough (safer default when the ID is shared):** skip `vfio.conf` entirely. In the VM's hardware config, reference the device by its **exact PCI address** (e.g. `0000:02:00.0`) instead of its ID. Proxmox automatically rebinds only that specific device to `vfio-pci` when the VM starts, and returns it to the host's normal driver when the VM stops — no static, global binding involved, so the host's own NIC (even if it shares a vendor:device ID) is never touched.

## Step 5: Create the Router VM

Same wizard as the [Network Architecture guide](./network-and-pfsense.md)'s pfSense VM, with a couple of settings PCI passthrough specifically requires:

| Tab | Field | Value |
|---|---|---|
| General | VM ID / Name | e.g. `100` / `router` |
| OS | ISO Image | Your pfSense/OPNsense installer |
| System | Machine | **`q35`** — required for PCIe passthrough; the older `i440fx` default will refuse to start with a passthrough device attached |
| System | BIOS | `OVMF (UEFI)`, with an EFI disk added, pre-enrolled keys **unchecked** |
| System | SCSI Controller | `VirtIO SCSI` |
| Disks | Size | 8–32 GB depending on OS |
| CPU | Cores / Type | 2–4 cores / `host` |
| Memory | | 2048–8192 MiB, ballooning **off** |
| Network | Bridge | Your LAN bridge only for now — the WAN interface will be the passthrough device, not a bridged NIC |
| Confirm | Start after created | **Unchecked** — add the PCI device first |

Then attach the physical NIC: **Hardware → Add → PCI Device → Raw Device**, select the port by its **exact PCI address** (not just its name — multi-port cards list several near-identical entries), leave **All Functions** unchecked, open **Advanced** and check **PCI-Express**, then **Add**.

## Step 6: The WAN/LAN Reversed-Assignment Gotcha

During first boot, the installer's interface-assignment wizard auto-detects which NIC is which — and with a passthrough NIC alongside a VirtIO one, it isn't unusual for this to come out **backwards** (passthrough NIC assigned as LAN, VirtIO as WAN, or vice versa). The GUI will look fully configured either way; the only symptom is that nothing on the LAN side actually reaches the router.

**Diagnose it from the Proxmox host**, without touching the VM's config, by adding a temporary IP directly to the bridge the LAN NIC is supposed to be on:

```bash
ip addr add 10.0.0.2/24 dev vmbr1
ping -c3 10.0.0.1   # the router VM's LAN IP
```

100% packet loss here means the interface you believe is LAN isn't actually the one bridged to `vmbr1` — the roles are swapped. Fix it under **Interfaces → Assignments** in the router's console/GUI, swapping which physical/virtual device maps to which role.

```bash
ip addr del 10.0.0.2/24 dev vmbr1   # clean up once you're done diagnosing
```

> **Don't skip the cleanup.** A forgotten manual IP alias left on a bridge doesn't just sit there harmlessly — it silently creates a route, making the host believe that entire subnet is directly attached to it. That can misdirect traffic for hosts on that subnet hours or sessions later in a way that looks exactly like a firewall problem. If a host on a segmented network mysteriously becomes unreachable with no firewall rule explaining it, check `ip route`/`ip addr` on the router/gateway host itself for a stray alias like this before assuming it's a rule issue.

## Step 7: Verify and Enable Auto-Start

1. Confirm the WAN interface receives the IP you expect (**Lobby → Dashboard** in OPNsense, or the console summary).
2. Test outbound connectivity from **Interfaces → Diagnostics → Ping**.
3. In Proxmox, select the VM → **Options** → set **Start at boot** to **Yes** and **Boot Order** to **1** (highest priority — everything else on this host depends on this VM having network access first).

## A Note on Diagnosing Bad Ports

If a passthrough NIC (or any port on a multi-port card) refuses to negotiate the speed it claims to support, don't assume it's a driver or config issue right away — some onboard ports do fail at the PHY level while still reporting full capabilities in software. The most conclusive way to isolate this: swap cables and remote devices first to rule those out, then physically swap which two ports on the *same card* connect to which devices. If the fault follows the port rather than the cable or the device on the other end, it's a hardware fault on that port, not something a driver flag or `ethtool -s` will fix.

---
### Sources
- [Proxmox VE Documentation — PCI(e) Passthrough](https://pve.proxmox.com/wiki/PCI(e)_Passthrough)
- [OPNsense Documentation — Hardware](https://docs.opnsense.org/manual/hardware.html)
