# OPNsense PPPoE & VLAN Setup for Home Labs

This guide details three common methods for configuring a virtualized OPNsense router to handle PPPoE authentication on a tagged VLAN, a frequent requirement for fiber ISPs. We'll cover setups for various budgets and hardware capabilities, from single-port mini PCs to multi-port servers.

![ISP VLAN Settings](./resources/isp-router-vlan-settings.png)
> [!NOTE]
> As shown in the diagram, many fiber ISPs require that all traffic from the router to the Optical Network Terminal (ONT) be tagged on a specific VLAN (e.g., VLAN 35). This guide addresses that requirement.

---

## Topology 1: Dual Dedicated 1G Ports (The Hardware Method)

This is the most straightforward and performant method, dedicating separate physical network ports for WAN and LAN.

- **How it works:** You physically add a second network card to your router host (e.g., a mini PC). One port connects to the ONT (WAN), and the other connects to your local network switch (LAN).
- **Pros:** Cleanest setup with the lowest latency. Avoids all internal bottlenecks. Ideal for traffic shaping algorithms like FQ-CoDel to combat bufferbloat effectively.
- **Cons:** Requires available hardware slots, opening the case, and potentially custom-mounting hardware.

### Configuration Steps

#### 1. Clear Existing WAN Configuration
First, ensure the physical port intended for the WAN connection is unconfigured.
1. Navigate to **Interfaces -> [WAN]**.
2. Set both **IPv4 Configuration Type** and **IPv6 Configuration Type** to **None**.
3. Uncheck **Enable Interface**.
4. Click **Save** and **Apply Changes**.

#### 2. Create the VLAN Interface
Create the VLAN interface that your ISP requires.
1. Go to **Interfaces -> Devices -> VLAN**.
2. Click **+ Add**.
3. **Device:** Name it descriptively (e.g., `vlan0.35`).
4. **Parent Interface:** Select the physical port you just cleared.
5. **VLAN tag:** Enter the required tag (e.g., `35`).
6. Click **Save**.

#### 3. Create the PPPoE Device
Configure the PPPoE credentials on top of the new VLAN interface.
1. Go to **Interfaces -> Devices -> Point-to-Point**.
2. Click **+ Add**.
3. **Link Type:** Select **PPPoE**.
4. **Link Interface(s):** Select the VLAN interface you created (`vlan0.35`).
5. **Username:** Enter your full ISP username.
6. **Password:** Enter your ISP password.
7. Click **Save**.

#### 4. Assign PPPoE to WAN
Assign the virtual PPPoE device as the primary WAN interface.
1. Go to **Interfaces -> Assignments**.
2. In the **WAN** row, select the new PPPoE device (e.g., `pppoe0 (vlan0.35)`).
3. Click **Save**.

#### 5. Configure and Enable WAN
Finalize the WAN interface settings.
1. Go to **Interfaces -> [WAN]**.
2. Check **Enable Interface**.
3. **IPv4 Configuration Type** should automatically be **PPPoE**.
4. **MTU:** `1492`
5. **MSS:** `1492`
6. Enable **Block private networks** and **Block bogon networks**.
7. Click **Save** and **Apply Changes**.

#### 6. Verify Connection
1. Go to **Lobby -> Dashboard**. The WAN interface should receive a public IP address within a few minutes.
2. Go to **Interfaces -> Diagnostics -> Ping**, enter `8.8.8.8`, and click **Apply** to confirm connectivity.

---

## Topology 2: 1G Router on a Stick (The Budget Lab)

This method uses a single physical port and a managed switch to handle both WAN and LAN traffic, making it ideal for devices with limited network ports.

- **How it works:** A managed switch separates traffic using VLANs. Both WAN and LAN traffic travel over a single "trunk" cable between the OPNsense host and the switch.
- **Pros:** Very cost-effective (~$20 for a switch). Provides excellent hands-on experience with VLANs and trunking.
- **Cons:** The "hairpin bottleneck." A single 1Gbps link is shared for all traffic. A simultaneous 1Gbps download and upload will saturate the link, effectively capping speeds at ~500/500 Mbps and reintroducing bufferbloat.

---

## Topology 3: 2.5G Router on a Stick (The Overkill Setup)

This setup overcomes the bottleneck of the 1G RoaS by upgrading the trunk link speed.

- **How it works:** You use a 2.5G managed switch and a 2.5G network adapter on the OPNsense host.
- **Pros:** Eliminates the hairpin bottleneck. The 2.5Gbps trunk provides ample bandwidth for a 1Gbps internet connection plus heavy internal network traffic.
- **Cons:** Expensive. Requires premium hardware (switch and adapter) primarily to avoid running a second physical cable.

---
### Source
- [OPNsense PPPoE Documentation](https://docs.opnsense.org/manual/how-tos/pppoe_isp_setup.html)
- [Lawrence Systems - OPNsense VLANs & PPPoE](https://www.youtube.com/watch?v=NBtJyedFAOw)