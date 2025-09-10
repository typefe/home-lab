## 🌐 Network Architecture and pfSense Setup
### Understanding the Network Design
Our home lab will use a segmented network architecture for enhanced security and organization:

```
Internet
    │
    ├── WAN (192.168.1.0/24) - Your existing home network
    │   └── Proxmox Server
    │
    └── pfSense Firewall/Router
        ├── LAN (192.168.20.0/24) - Secure internal network
        └── GUEST (192.168.30.0/24) - Isolated guest network
```

This design provides:
- **Network Segmentation**: Separate networks for different purposes
- **Enhanced Security**: pfSense firewall controls traffic between networks
- **Flexibility**: Easy to add new network segments or services

### Creating Virtual Networks in Proxmox
#### Step 1: Create LAN Bridge (vmbr1)
1. In Proxmox web interface, navigate to: **Node (pve) → System → Network**
2. Click **"Create"** → **"Linux Bridge"**
3. Configure the bridge:
   - **Name**: `vmbr1`
   - **IPv4/CIDR**: Leave blank (pfSense will handle routing)
   - **Gateway**: Leave blank
4. Click **"Create"**

#### Step 2: Create GUEST Bridge (vmbr2)
Repeat the process for the guest network:
1. Click **"Create"** → **"Linux Bridge"**
2. Configure the bridge:
   - **Name**: `vmbr2`
   - **IPv4/CIDR**: Leave blank
   - **Gateway**: Leave blank
3. Click **"Create"**

> **📝 Note**: We don't assign IP addresses to these bridges because pfSense will act as the router and DHCP server for these networks.
### pfSense Virtual Machine Setup
#### Step 1: Download pfSense
1. Download the latest pfSense Community Edition ISO from [pfSense Downloads](https://www.pfsense.org/download/)
2. Upload the ISO to your Proxmox server via the web interface

#### Step 2: Create pfSense VM
1. In Proxmox, click **"Create VM"**
2. Configure the VM:
   - **OS**: Other
   - **ISO Image**: Select the pfSense ISO
   - **System**: Default settings
   - **Hard Disk**: 8GB minimum
   - **CPU**: 2 cores
   - **Memory**: 2048MB (1GB minimum)
   - **Network**: 
     - **Bridge**: vmbr0 (this will be WAN interface)
     - **Model**: VirtIO (paravirtualized)

#### Step 3: Add Additional Network Interfaces
After creating the VM, add the LAN interface:
1. Select your pfSense VM
2. Go to **Hardware**
3. Click **"Add"** → **"Network Device"**
4. Configure:
   - **Bridge**: vmbr1
   - **Model**: VirtIO (paravirtualized)

### pfSense Installation and Configuration
#### Step 1: Install pfSense
1. Start the pfSense VM
2. Follow the installation wizard:
   - Accept default keymap
   - Choose **"Install"**
   - Select **"Auto (UFS)"** for partitioning
   - Complete the installation and reboot

#### Step 2: Initial Interface Configuration
During first boot, pfSense will ask about interface assignment:
1. **WAN Interface**: Should auto-detect as `vtnet0` (connected to vmbr0)
2. **LAN Interface**: Should auto-detect as `vtnet1` (connected to vmbr1)
3. Confirm the assignment

#### Step 3: Configure LAN Network
1. Select option **"2) Set interface(s) IP address"**
2. Choose **LAN** interface
3. Configure:
   - **IP Address**: `192.168.20.1`
   - **Subnet Mask**: `24` (for /24 network)
   - **DHCP Server**: `yes`
   - **DHCP Range**: `192.168.20.100` to `192.168.20.150`
4. When asked about web configurator protocol, choose **HTTPS**

#### Step 4: WAN Configuration
The WAN interface can be configured to:
- **DHCP** (recommended for home networks): Automatically gets IP from your router
- **Static IP**: If you prefer a fixed IP address

For DHCP configuration:
1. Select option **"2) Set interface(s) IP address"**
2. Choose **WAN** interface
3. Select **DHCP** for IPv4 configuration

### Creating Management VM
To access pfSense web interface, we need a VM on the LAN network:

#### Step 1: Create Ubuntu Desktop VM
1. Download Ubuntu Desktop ISO
2. Create a new VM in Proxmox:
   - **CPU**: 2 cores
   - **Memory**: 2048MB
   - **Network**: vmbr1 (LAN network)
   - **Hard Disk**: 20GB

#### Step 2: Install and Configure Ubuntu
1. Install Ubuntu with default settings
2. After installation, the VM should automatically receive an IP from pfSense DHCP (192.168.20.100-150 range)

### pfSense Web Interface Configuration
#### Step 1: Access Web Interface
1. From your Ubuntu VM, open a web browser
2. Navigate to `https://192.168.20.1`
3. Accept the security certificate warning
4. Login with:
   - **Username**: `admin`
   - **Password**: `pfsense`

#### Step 2: Complete Setup Wizard
1. **General Information**: Set hostname and domain
2. **Time Server Information**: Configure NTP servers
3. **WAN Interface**: Verify WAN configuration
4. **LAN Interface**: Confirm LAN settings
5. **Admin Password**: Change the default password
6. **Reload Configuration**: Apply all changes

#### Step 3: Configure DNS Resolution
If you experience internet connectivity issues from LAN clients:

1. Navigate to **System → General Setup**
2. In **DNS Server Settings**:
   - **DNS Resolution Behavior**: Set to "Use local, ignore remote"
   - **DNS Servers**: Add reliable DNS servers like `8.8.8.8` and `1.1.1.1`
3. Save and apply changes

### Enable Auto-Boot for pfSense
To ensure pfSense starts automatically when Proxmox boots:
1. In Proxmox web interface, select your pfSense VM
2. Go to **Options**
3. Double-click **"Start at boot"**
4. Set to **"Yes"**
5. Set **Boot Order** to **1** (highest priority)
