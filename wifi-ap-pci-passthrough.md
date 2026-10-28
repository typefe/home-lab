# Wi-Fi Access Point via PCI Passthrough + OpenWrt VM

If your Proxmox host has (or can take) a spare M.2/PCIe Wi-Fi card, you can turn it into a full access point without any dedicated AP hardware — pass the card through to a tiny OpenWrt VM running as a dumb AP, bridged onto your existing LAN. This guide walks through a full build, including the firmware and driver gotchas that are easy to lose an evening to.

This uses the same [PCI passthrough](./pci-passthrough-router.md) technique as the router VM guide, applied to a Wi-Fi card instead of a NIC.

## 0 — What You Need

- A Wi-Fi card in an M.2 E-key slot (or a PCIe card via adapter) with Linux/mac80211 driver support — this guide's specifics are for a MediaTek MT7925 (Wi-Fi 7 chipset, run in Wi-Fi 6/`HE` mode), but the passthrough mechanics apply to most modern cards.
- A Proxmox host with IOMMU already enabled — see [PCI Passthrough guide](./pci-passthrough-router.md) Steps 1–3 if you haven't done this yet.
- The OpenWrt `x86/generic` image for your VM's firmware type (SeaBIOS vs. UEFI — see Step 2).

> ⚠️ If this card is going into a host that's already running live services, budget for a few minutes of downtime per reboot during setup, and do this work at a time that tolerates it.

## 1 — Identify and Bind the Wi-Fi Card

```bash
lspci -nnk | grep -A3 -i <vendor-name>   # e.g. "mediatek", "intel", "realtek"
```

Note the PCI address and vendor:device ID (e.g. `01:00.0`, `14c3:7925`).

```bash
cat > /etc/modprobe.d/vfio.conf << 'EOF'
options vfio-pci ids=<vendor:device>
EOF

update-initramfs -u -k all
update-grub
proxmox-boot-tool refresh   # only if this host uses proxmox-boot-tool — see the PCI Passthrough guide, Step 2
reboot
```

A Wi-Fi card's vendor:device ID is essentially never shared with anything else on the host, so the blanket ID-based bind used here is safe (unlike the multi-port-NIC caution in the router passthrough guide).

**Verify after reboot:**

```bash
cat /proc/cmdline           # → ... intel_iommu=on iommu=pt
lsmod | grep vfio           # → vfio_pci, vfio_iommu_type1, vfio loaded
lspci -nnk | grep -A3 -i <vendor-name>
# → Kernel driver in use: vfio-pci   (not the normal Wi-Fi driver)
```

## 2 — Download the OpenWrt Image

```bash
wget https://downloads.openwrt.org/releases/<version>/targets/x86/generic/openwrt-<version>-x86-generic-generic-ext4-combined.img.gz
wget -q https://downloads.openwrt.org/releases/<version>/targets/x86/generic/sha256sums -O sha256sums
sha256sum openwrt-*-ext4-combined.img.gz
grep ext4-combined.img.gz sha256sums   # must match

gunzip -k openwrt-*-ext4-combined.img.gz
```

> **EFI or not?** OpenWrt publishes both an `-efi` and a plain image for x86. Use the plain (non-EFI) image if your VM will run **SeaBIOS** (the Proxmox `qm create` default) — the `-efi` variant is only for VMs using OVMF/UEFI.

## 3 — Create the VM

```bash
qm create 900 --name openwrt-ap --memory 512 --net0 virtio,bridge=vmbr0
qm importdisk 900 openwrt-<version>-x86-generic-generic-ext4-combined.img local-lvm
qm set 900 --scsihw virtio-scsi-pci --scsi0 local-lvm:900/vm-900-disk-0.raw
qm set 900 --boot order=scsi0
qm set 900 --machine q35                 # required for PCIe passthrough
qm set 900 --hostpci0 01:00,pcie=1       # your Wi-Fi card's PCI address
qm set 900 --serial0 socket --vga serial0  # serial console — very useful for headless access
```

**Accessing the serial console from the host, without an interactive TTY** (handy for scripting or a non-interactive shell):

```bash
socat - UNIX-CONNECT:/var/run/qemu-server/900.serial0
```

`qm terminal 900` requires a real interactive TTY and won't work over a piped/non-interactive session — `socat` works everywhere.

## 4 — First Boot: Avoid an IP Collision (Order Matters)

**OpenWrt's factory default is `192.168.1.1/24` with its own DHCP server enabled.** If your home network's router is anywhere near that same subnet — extremely common — bridging this VM straight onto your live LAN at first boot will hand out a second, conflicting DHCP server and break other devices on the network. Bring the link up disabled first:

```bash
qm set 900 --net0 virtio=<MAC-from-qm-config-900>,bridge=vmbr0,link_down=1
qm start 900
```

From the serial console, reconfigure before ever enabling the link:

```bash
# Move to a non-conflicting static address — don't forget the /24!
uci set network.lan.ipaddr='192.168.1.250/24'   # writing just '192.168.1.250' silently becomes /32 — "Network unreachable"
uci set network.lan.gateway='192.168.1.1'
uci set network.lan.dns='1.1.1.1'
# Disable OpenWrt's own DHCP server — your existing router keeps handing out addresses
uci set dhcp.lan.ignore='1'
uci commit network
uci commit dhcp
/etc/init.d/network restart
```

Verify: `ip a show br-lan` should show the new static address. **Only then** bring the link up:

```bash
qm set 900 --net0 virtio=<MAC>,bridge=vmbr0,link_down=0
```

Test from inside the VM: `ping -c2 192.168.1.1` and `ping -c2 1.1.1.1` should both succeed.

## 5 — Install Wi-Fi Packages

> ⚠️ Fresh OpenWrt images often ship without CA certificates, which breaks `apk`'s HTTPS package fetches with a confusing "Operation not permitted" — before you can install `ca-bundle` to fix it, you need HTTPS to work. Break the chicken-and-egg with a temporary bypass, used only long enough to fetch the real fix:

```bash
mv /bin/uclient-fetch /bin/uclient-fetch.orig
cat > /bin/uclient-fetch << 'WRAP'
#!/bin/sh
exec /bin/uclient-fetch.orig --no-check-certificate "$@"
WRAP
chmod +x /bin/uclient-fetch

apk update
apk add ca-bundle

mv /bin/uclient-fetch.orig /bin/uclient-fetch   # restore real certificate verification
apk update    # should now complete with no errors
```

Now install the Wi-Fi stack — **all of these together**, matched to your specific chipset:

```bash
apk add kmod-<your-chipset-driver> kmod-<your-chipset>-firmware wpad-basic-mbedtls iwinfo
```

| Package | Purpose |
|---|---|
| `kmod-<chipset>` | The PCIe driver itself |
| `kmod-<chipset>-firmware` | Firmware blobs — **a separate package from the driver.** Skipping it is the single most common mistake here. |
| `wpad-basic-mbedtls` | hostapd/AP mode + WPA3-SAE + OWE support |
| `iwinfo` | Signal/client diagnostic tool |

> ⚠️ **The most common trap in this whole setup:** installing the driver *without* its matching firmware package and letting it load will crash the driver with a `hardware init failed` error — and leave the radio (`phy`) in a broken state. Symptoms: `wifi` commands silently do nothing, the log shows an endless loop of `Tearing down phyX` / `Starting` every ~30 seconds, `iw dev` returns nothing, and `ubus call network.wireless status` reports `"pending": true` forever. **`rmmod`/`modprobe` does not fix this** — the only reliable recovery is a full VM stop/start with the firmware package already installed (Step 7). Installing both packages in the same command, as shown above, avoids ever hitting this.

## 6 — Update the Firmware Blobs (If Your Distro's Package Is Stale)

Distro-packaged firmware blobs for newer Wi-Fi chipsets can lag upstream by a long time, and older blobs sometimes crash under sustained AP-mode load — periodic firmware timeouts that silently reset the radio and drop connected clients:

```
kern.err  <driver> 0000:01:00.0: Message timeout   ← firmware crashed
kern.info <driver> 0000:01:00.0: Firmware Version: ..., Build Time: <old date>  ← self-reset
daemon.info hostapd: ... disconnected due to excessive missing ACKs  ← clients dropped
```

If you see this pattern, pull current firmware blobs from [kernel.org's linux-firmware tree](https://git.kernel.org/pub/scm/linux/kernel/git/firmware/linux-firmware.git/) for your exact chipset and drop them into `/lib/firmware/<vendor>/<chipset>/`, backing up the originals first:

```bash
cd /lib/firmware/<vendor>/<chipset>
mv <firmware-file> <firmware-file>.old
# copy the freshly downloaded blob(s) into place with the original filenames
```

> ⚠️ A subsequent `apk upgrade` of the firmware package can silently overwrite these newer blobs with the older packaged ones. After any upgrade, check the active firmware build date in `dmesg` and re-copy if it reverted.

## 7 — Clean Restart (Required After a Firmware Change)

A firmware swap needs a clean driver re-init to take effect — a VM **reboot** isn't enough, since that doesn't reset the PCI device state the same way a full stop/start does:

```bash
qm shutdown 900 --timeout 30
qm start 900
```

Verify from the serial console:

```bash
dmesg | grep -iE "firmware version"
# → should show the NEW build date, not the old one
# → should NOT show "hardware init failed"
```

## 8 — Configure the Radio

**Country code first** — the channel list depends on it, and leaving it at the "world" default (`00`) makes most channels "no IR" (radar/indoor-only) restricted, which will prevent the AP from starting at all:

```bash
uci set wireless.radio0.country='<your two-letter country code>'
```

**Pick a band.** A single-radio card like most modern Wi-Fi 6/7 chips can only transmit on one band at a time — switching bands means moving your SSID to that band, not adding a second radio:

```bash
# 5GHz — good balance of range and speed; use a non-DFS channel (36–48 or 149–165)
uci set wireless.radio0.band='5g'
uci set wireless.radio0.channel='36'
uci set wireless.radio0.htmode='HE80'
uci set wireless.radio0.noscan='1'
```

> **Why `noscan='1'` on 5GHz:** by default hostapd runs a 40MHz-coexistence scan at start-up and, if it sees neighbouring beacons on the secondary channel, **swaps its primary and secondary channel** (`logread` shows `Switch own primary and secondary channel to get secondary channel with no Beacons from other BSSes`). The AP then quietly runs on e.g. channel 40 while your config says 36. That is a legal 80MHz layout, but it is not what you configured and it makes the channel unpredictable between restarts. The coexistence rule exists for 2.4GHz; on 5GHz it only gets in the way, so turn it off. Check what you really got with `iw dev <ap-interface> info` (look at the `channel` line).

```bash
# 6GHz — highest throughput, shortest range; PSC channels (e.g. 37) get priority scanning from phones
uci set wireless.radio0.band='6g'
uci set wireless.radio0.channel='37'
uci set wireless.radio0.htmode='HE160'
```

> 6GHz operation legally **requires** encryption — an open network isn't permitted on this band by regulation, unlike 2.4/5GHz.

**SSID and encryption:**

```bash
uci set wireless.default_radio0.ssid='YourSSID'
uci set wireless.default_radio0.encryption='sae-mixed'   # WPA2/WPA3 mixed — works with both old and new clients
uci set wireless.default_radio0.key='change-this-password'
uci set wireless.default_radio0.ieee80211w='2'           # PMF required for SAE
```

**Enable and apply:**

```bash
uci set wireless.radio0.disabled='0'
uci set wireless.default_radio0.disabled='0'
uci commit wireless
wifi reload
```

**Verify:**

```bash
ubus call network.wireless status   # → "up": true, "pending": false
iwinfo                              # → Mode: Master, correct channel/HT mode
iwinfo <ap-interface> assoclist     # → connected clients, signal, MCS/NSS
```

> If `assoclist` consistently shows `HE-NSS 1` for every client, only one spatial stream is active — usually because a second antenna is present on the card but not physically connected (common on cards with dual U.FL pads where only one pigtail was wired up).

## 9 — Known Bug: SAE/PMF Deauth Loop

Some clients (phones especially) may show a **"Connection failure"** loop — associating, then getting kicked ~9 seconds later by the AP itself logging `deauthenticated due to local deauth request`, repeating indefinitely, while other clients connect fine. This is a known compatibility issue between recent hostapd builds and SAE/PMF (802.11w) with certain client Wi-Fi stacks — see this [OpenWrt forum thread](https://forum.openwrt.org/t/how-to-solve-hostapd-excessive-deauthenticated-due-to-local-deauth-request/113135).

**Fix — disable PMF, keep WPA3-SAE otherwise intact:**

```bash
uci set wireless.default_radio0.ieee80211w='0'
uci commit wireless
wifi reload
```

A more drastic fallback (loses WPA3 entirely) is dropping `encryption` from `sae-mixed` to plain `psk2`.

> This `wifi reload` will briefly disconnect every currently-associated client — expected, not a new problem.

## 10 — Known Issue: Silent "Bad State" and the Nightly Restart

After weeks of flawless operation, the AP can drop into a state where it *looks* healthy but clients intermittently cannot connect or keep dropping. It clears completely after a full VM stop/start and then stays good for a long time. This is a **driver/firmware state problem of the passed-through chip, not a configuration or password problem** — the same config worked for weeks before and works again right after the restart.

### 10.1 — How to recognise it (and tell it apart from a real wrong password)

| Where you look | What you see in this bad state |
|---|---|
| Client (NetworkManager / Android) | "Password incorrect" / "authentication problem" even though the password is right; connecting works again later by itself |
| Client `wpa_supplicant` | `4-Way Handshake failed - pre-shared key may be incorrect` with `reason=15`. **This text is printed for *any* handshake timeout**, not only for a wrong key — don't trust it |
| Healthy Intel client, already connected | Drops every few seconds with `reason=4 locally_generated=1`; kernel log shows `iwlwifi: missed beacons exceeds threshold` — the AP stopped sending beacons |
| AP `hostapd` | `sending 1/4 msg of 4-Way Handshake`, then `EAPOL-Key timeout` ×4 and `PTKSTART: Retry limit 4 reached` — and **never** `received EAPOL-Key frame (2/4 Pairwise)`; idle phones get `disassociated due to inactivity` |
| AP `dmesg`, `hostapd` status, `iw dev` | **All look normal** — no driver error, hostapd says `ENABLED`. That silence is the signature |

A *real* wrong password looks different: hostapd **does** receive the 2/4 message and logs an MIC failure / `AP-STA-POSSIBLE-PSK-MISMATCH`. To see the handshake steps, temporarily raise hostapd's log level on the radio, then remove it again:

```bash
uci set wireless.radio0.log_level='0'     # 0 = full debug; default is 2
uci commit wireless && wifi reload
logread | grep -E "received EAPOL-Key|Retry limit|POSSIBLE-PSK"
# when done:
uci delete wireless.radio0.log_level && uci commit wireless && wifi reload
```

### 10.2 — What does *not* work (don't repeat these)

- `wifi reload`, `rmmod`/`modprobe`, or a guest-internal reboot — none of them resets the card's firmware state.
- Flipping the driver's power-management switches **live** (`echo 0 > /sys/kernel/debug/ieee80211/phy*/mt76/runtime-pm` and `.../deep-sleep`). In our case a beacon-loss storm began 16 seconds after doing this, switching them back did not stop it, and only a full VM stop/start did. Whether the switch caused it is unproven — but it is a risky thing to poke at on a live AP.
- Re-entering or changing the Wi-Fi password: it was never wrong.

### 10.3 — The fix: a full VM stop/start (what actually clears it)

Run on the **Proxmox host**. The shutdown closes the passthrough device, which resets the PCIe function; the start brings the card up with fresh firmware state:

```bash
qm shutdown 900 --timeout 60 --forceStop 1 && sleep 3 && qm start 900
```

> If you administer the host over this very Wi-Fi, the command kills your own SSH session. Run it detached: `nohup sh -c 'qm shutdown 900 --timeout 60 --forceStop 1; sleep 3; qm start 900' >/root/ap-restart.log 2>&1 &`

The whole cycle takes about 15 seconds for the VM and roughly half a minute until clients are back.

### 10.4 — Prevent it: restart the AP VM every night

Because the bad state builds up with uptime, a scheduled restart in the small hours stops it before it ever shows. This only affects Wi-Fi (about 30 seconds); wired devices and all other guests are untouched.

**The script** — `/usr/local/sbin/restart-wifi-ap-vm.sh` on the Proxmox host:

```sh
#!/bin/sh
# Full stop/start of the OpenWrt Wi-Fi AP VM.
# A plain "wifi reload" or module reload does NOT reset the passed-through card's
# firmware state; a full VM stop/start does (the PCIe function is reset on close).
# Set DRY_RUN=1 to only report what would happen.
VMID="${VMID:-900}"
LOCK=/run/lock/restart-wifi-ap-vm.lock

exec 9>"$LOCK"
flock -n 9 || { echo "another restart is already running, skipping"; exit 0; }

if ! qm status "$VMID" | grep -q 'status: running'; then
    echo "VM $VMID is not running, leaving it alone"
    exit 0
fi

if [ "$DRY_RUN" = 1 ]; then
    echo "DRY RUN: would stop and start VM $VMID"
    exit 0
fi

echo "stopping VM $VMID"
qm shutdown "$VMID" --timeout 60 --forceStop 1
sleep 3
echo "starting VM $VMID"
qm start "$VMID"
sleep 2

if qm status "$VMID" | grep -q 'status: running'; then
    echo "VM $VMID is running again"
else
    echo "VM $VMID did NOT come back up" >&2
    exit 1
fi
```

Why each guard exists: `flock` prevents two overlapping runs; the `running` check means a VM you stopped on purpose is **not** started behind your back; the final check makes systemd mark the run as *failed* if the VM doesn't return, so you can see it.

**The service** — `/etc/systemd/system/wifi-ap-restart.service`:

```ini
[Unit]
Description=Restart the OpenWrt Wi-Fi AP VM (clears the silent bad state)

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/restart-wifi-ap-vm.sh
TimeoutStartSec=180
```

**The timer** — `/etc/systemd/system/wifi-ap-restart.timer`:

```ini
[Unit]
Description=Nightly restart of the OpenWrt Wi-Fi AP VM

[Timer]
OnCalendar=*-*-* 04:30:00
AccuracySec=10s
Persistent=false

[Install]
WantedBy=timers.target
```

`Persistent=false` is deliberate: if the host happened to be off at 04:30, you do **not** want an extra restart the moment it boots. `OnCalendar` uses the host's local time zone — confirm it with `timedatectl` first.

**Enable and verify:**

```bash
chmod 755 /usr/local/sbin/restart-wifi-ap-vm.sh
systemctl daemon-reload
systemctl enable --now wifi-ap-restart.timer

systemd-analyze verify /etc/systemd/system/wifi-ap-restart.service /etc/systemd/system/wifi-ap-restart.timer
systemctl list-timers wifi-ap-restart.timer        # → NEXT should read tonight/tomorrow 04:30
systemd-run --wait --pipe -E DRY_RUN=1 /usr/local/sbin/restart-wifi-ap-vm.sh
# → "DRY RUN: would stop and start VM 900" — proves the script runs under systemd with the right PATH, without restarting anything
```

**Check it ran, and run it by hand when needed:**

```bash
journalctl -u wifi-ap-restart.service --since yesterday   # → "stopping VM…", "VM … is running again"
systemctl start wifi-ap-restart.service                   # immediate manual restart
systemctl disable --now wifi-ap-restart.timer             # turn the schedule off
```

> A nightly restart is a **workaround, not a root-cause fix**. If the bad state ever appears in the middle of the day, use the manual command above, and consider tracking upstream (see Sources) or trying a different firmware build (Step 6).

## 11 — Troubleshooting Table

| Symptom | Cause | Fix |
|---|---|---|
| VM won't start: `q35 machine model is not enabled` | `hostpci` set with a machine type other than q35 | `qm set 900 --machine q35` |
| `hardware init failed`, endless `Tearing down/Starting` loop, `iw dev` empty, status stuck `pending` | Driver loaded without its firmware package | Install the firmware package, then a **full VM stop/start** (not `rmmod`/`modprobe`, not a reboot) |
| Periodic `Message timeout`, clients drop with "excessive missing ACKs" | Stale distro-packaged firmware crashing under AP load | Step 6 — pull current blobs from kernel.org |
| `apk update` → `Operation not permitted` | No CA bundle in the base image | Step 5's temporary-bypass-then-fix sequence |
| VM has `ping: Network unreachable` | Static IP set without a `/24` (silently became `/32`) | `uci set network.lan.ipaddr='<ip>/24'` — always include the mask |
| AP won't start on 6GHz | `encryption='none'` — not legally permitted on this band | Use `sae` (or `sae-mixed`) with a key and `ieee80211w='2'` |
| AP won't start on 5GHz | A DFS channel was selected | Use a non-DFS channel (36–48 or 149–165) |
| AP doesn't come back after a VM/host reboot | Known passthrough re-init bug on some Wi-Fi chipsets | Full VM stop/start first; if that doesn't help, reboot the Proxmox host itself |
| Card shows up under its normal driver (not `vfio-pci`) after a host reboot | The vfio binding didn't survive — usually a missed `proxmox-boot-tool refresh` on UEFI hosts | Re-check Step 1, especially the boot-tool refresh step |
| Clients say "wrong password" or drop every few seconds, but the password is right and AP logs/`dmesg` look clean | The silent driver/firmware bad state (hostapd sends 1/4, never gets 2/4; or beacons stop) | Step 10 — full VM stop/start, and the nightly restart timer to prevent it |
| AP runs on a different channel than configured (e.g. 40 instead of 36) | hostapd's 40MHz-coexistence scan swapped primary/secondary channel | Step 8 — `uci set wireless.radio0.noscan='1'` on 5GHz |

---
### Sources
- [OpenWrt Documentation](https://openwrt.org/docs/start)
- [OpenWrt Forum — hostapd "excessive deauthenticated due to local deauth request"](https://forum.openwrt.org/t/how-to-solve-hostapd-excessive-deauthenticated-due-to-local-deauth-request/113135)
- [kernel.org linux-firmware repository](https://git.kernel.org/pub/scm/linux/kernel/git/firmware/linux-firmware.git/)
- [openwrt/mt76 issue #1103 — mt7925e: first 4-way handshake after firmware init times out (EAPOL 2/4 never transmitted)](https://github.com/openwrt/mt76/issues/1103)
- [openwrt/mt76 PR #1143 — mt7925: release the JOIN ROC before reporting the BSS connected](https://github.com/openwrt/mt76/pull/1143)
- [OpenWrt Forum — MT7925 crashes in AP mode (Proxmox VM; "disassociated due to inactivity", fixed by a firmware update)](https://forum.openwrt.org/t/mt7925-crashes-in-ap-mode/231946)
