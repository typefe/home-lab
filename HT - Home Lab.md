---
aliases:
  - Home Lab
  - Lab
  - DIY
  - Homelab Guide
  - Self-Hosted Infrastructure
tags:
  - how-to
  - network
  - security
  - security/cloud
  - proxmox
  - pfsense
  - cloudflare
  - homelab
---

# 🏠 Complete Home Lab Setup Guide
This comprehensive guide will walk you through building a complete home lab infrastructure using Proxmox as the virtualization platform, pfSense for network security and routing, and Cloudflare tunnels for secure remote access.

## 📋 Table of Contents
- [[#Prerequisites and Planning|Prerequisites and Planning]]
- [[#Environment Preparation|Environment Preparation]]
- [[#Proxmox Installation and Initial Setup|Proxmox Installation and Initial Setup]]
- [[#Basic Security and SSH Configuration|Basic Security and SSH Configuration]]
- [[#Network Architecture and pfSense Setup|Network Architecture and pfSense Setup]]
- [[#Remote Access with Cloudflare Tunnels|Remote Access with Cloudflare Tunnels]]
- [[#Zero Trust Security Implementation|Zero Trust Security Implementation]]
- [[#Service Installations|Service Installations]]
- 

## 🎯 Prerequisites and Planning
### What You'll Need
**Hardware Requirements:**
- A dedicated server or powerful PC (minimum 8GB RAM, 4+ cores recommended)
- USB drive (8GB minimum) for installation media
- Stable internet connection
- Basic understanding of networking concepts

**Software Components:**
- **Proxmox VE**: Virtualization platform for managing VMs and containers
- **pfSense**: Open-source firewall and router software
- **Cloudflare**: For domain management and secure tunneling
- **Various services**: We'll install ChangeDetection.io, Nextcloud, Grafana...

### Network Planning
Before starting, it's crucial to plan your network architecture. We'll create multiple network segments:
- **WAN Network**: Your existing home network (e.g., 192.168.1.0/24)
- **LAN Network**: Internal secure network (192.168.20.0/24)
- **GUEST Network**: Isolated network for guest devices (to be configured later)

## 🛠️ Environment Preparation
### Hardware Setup
1. **Server Preparation**
   - Ensure your server meets minimum requirements
   - Connect to your router via Ethernet
   - Prepare a USB drive for Proxmox installation

2. **USB Installation Media**
   - Download the latest Proxmox VE ISO from [Proxmox Downloads](https://www.proxmox.com/en/downloads)
   - Create bootable USB using the following command:
   ```bash
   sudo dd if=Downloads/proxmox-ve_9.0-1.iso of=/dev/sda bs=4M status=progress conv=fsync
   ```
   > **⚠️ Warning**: Replace `/dev/sda` with your actual USB device. Use `lsblk` to identify the correct device.

## 💻 Proxmox Installation and Initial Setup
### Installation Process
1. **Boot from USB**
   - Boot your server from the USB drive
   - Select "Install Proxmox VE" from the boot menu

2. **Installation Wizard**
   - Follow the default installation wizard
   - Choose your target disk for installation
   - Set up your timezone, keyboard layout, and root password
   - Configure network settings (you can use DHCP for initial setup)

3. **Post-Installation Access**
   - After installation, Proxmox will display the web interface URL
   - Access via `https://<your-server-ip>:8006`
   - Login with username `root` and the password you set during installation

### Optional: Remove Boot Delay
If you want to eliminate the 5-second GRUB timeout during boot:
```bash
vim /etc/default/grub
# Change GRUB_TIMEOUT=5 to GRUB_TIMEOUT=0
update-grub
```

This will make your server boot faster by skipping the GRUB menu delay.

## 🔒 Basic Security and SSH Configuration
### Securing SSH Access
Before proceeding with other configurations, it's crucial to secure SSH access to your Proxmox server. We'll implement key-based authentication to prevent unauthorized access.

#### Step 1: Generate SSH Keys on Client Device
On any device you'll use to access your Proxmox server via SSH:

```bash
ssh-keygen -t ed25519
```

> **💡 Note**: Ed25519 is a modern, secure, and fast cryptographic algorithm. Press Enter for default file locations and optionally set a passphrase for additional security.

#### Step 2: Copy Public Key to Server
Transfer your public key to the Proxmox server:

```bash
ssh-copy-id root@192.168.1.x
```

Replace `192.168.1.x` with your Proxmox server's actual IP address. You'll need to enter the root password one last time.

#### Step 3: Disable Password Authentication
Once key-based authentication is working, disable password authentication:

```bash
vim /etc/ssh/sshd_config
```

Find and modify the following line:
```
PasswordAuthentication no
```

#### Step 4: Restart SSH Service
Apply the changes by restarting the SSH service:

```bash
systemctl restart sshd
```

> **⚠️ Important**: Test SSH key authentication before disabling password authentication to avoid being locked out of your server.

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

## 🌍 Remote Access with Cloudflare Tunnels
Cloudflare Tunnels provide a secure way to expose your home lab services to the internet without opening ports on your firewall or exposing your home IP address.
### Benefits of Cloudflare Tunnels
- **Security**: No inbound ports needed on your firewall
- **Privacy**: Your home IP remains hidden
- **SSL/TLS**: Automatic HTTPS encryption
- **DDoS Protection**: Cloudflare's built-in protection
- **Custom Domains**: Use your own domain names

### Domain Setup
#### Step 1: Register a Domain
1. Purchase a domain from a registrar (e.g., Spaceship for `.pro` domains)
2. The domain will be used to create subdomains for your services

#### Step 2: Configure Cloudflare DNS
1. Create a free Cloudflare account at [cloudflare.com](https://cloudflare.com)
2. Add your domain to Cloudflare
3. Update your domain's nameservers to Cloudflare's nameservers:
   - This change can take 5 minutes to 24 hours to propagate
4. Verify the domain is active in Cloudflare dashboard

### Cloudflared Installation
#### Step 1: Create LXC Container
1. In Proxmox, create a new LXC container:
   - **Template**: Debian 12 or Ubuntu 22.04
   - **CPU**: 1 core
   - **Memory**: 512MB
   - **Storage**: 4GB
   - **Network**: vmbr1 (LAN network)

#### Step 2: Install Cloudflared
Connect to your LXC container and run:

```bash
# Update system packages
apt update && apt upgrade -y

# Add Cloudflare GPG key and repository
mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
    | tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null

# Add Cloudflare repository
echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] \
https://pkg.cloudflare.com/cloudflared bookworm main" \
    | tee /etc/apt/sources.list.d/cloudflared.list

# Install cloudflared
apt update && apt install -y cloudflared
```

### Tunnel Configuration
#### Step 1: Authenticate with Cloudflare
```bash
cloudflared tunnel login
```

This will open a browser window where you'll need to:
1. Log in to your Cloudflare account
2. Select the domain you want to use
3. Authorize the tunnel

#### Step 2: Create a Tunnel
```bash
cloudflared tunnel create homelab-tunnel
```

This command:
- Creates a tunnel named "homelab-tunnel"
- Generates credentials stored in `~/.cloudflared/<UUID>.json`
- Returns a tunnel UUID that you'll need for configuration

#### Step 3: Create DNS Records
Create a DNS record for Proxmox access:
```bash
cloudflared tunnel route dns homelab-tunnel proxmox.example.com
```

Replace `example.com` with your actual domain.

#### Step 4: Configure Tunnel
Create the tunnel configuration file:

```bash
nano ~/.cloudflared/config.yml
```

Add the following configuration:

```yaml
tunnel: <UUID>
credentials-file: /root/.cloudflared/<UUID>.json

ingress:
  - hostname: proxmox.example.com
    service: https://192.168.20.10:8006
    originRequest:
      noTLSVerify: true
  - service: http_status:404
```

**Configuration Explanation:**
- `tunnel`: Your tunnel UUID (from step 2)
- `credentials-file`: Path to the credentials file
- `hostname`: The subdomain that will access this service
- `service`: The internal IP and port of your Proxmox server
- `noTLSVerify: true`: Ignores self-signed certificate warnings

> **📝 Note**: Replace `192.168.20.10` with your actual Proxmox IP address if different.

#### Step 5: Test the Tunnel
Start the tunnel in foreground mode to test:

```bash
cloudflared tunnel run homelab-tunnel
```

If successful, you should be able to access Proxmox via `https://proxmox.example.com`

### Making the Tunnel Persistent
#### Step 1: Install as System Service
```bash
cloudflared service install
systemctl enable cloudflared
systemctl start cloudflared
```

#### Step 2: Handle Configuration File Location
When installed as a service, cloudflared looks for configuration in `/etc/cloudflared/`. Create a symbolic link:

```bash
ln -s /root/.cloudflared/config.yml /etc/cloudflared/config.yml
```

#### Step 3: Enable Auto-Boot
Set the Cloudflared LXC container to start automatically:

1. In Proxmox web interface, select your Cloudflared LXC
2. Go to **Options**
3. Set **"Start at boot"** to **"Yes"**
4. Set **Boot Order** to **2** (after pfSense)

## 🛡️ Zero Trust Security Implementation
Cloudflare Zero Trust adds an authentication layer to your services, ensuring only authorized users can access sensitive applications while keeping others publicly accessible.

### Understanding Zero Trust
Zero Trust security operates on the principle of "never trust, always verify." Instead of relying solely on network-level security, it adds application-level authentication for sensitive services.

**Benefits:**
- **Granular Access Control**: Different authentication requirements per service
- **Identity-Based Security**: Users authenticate before accessing applications
- **Audit Trail**: Track who accesses what and when
- **Multi-Factor Authentication**: Additional security layers available

### Configuring Zero Trust for Proxmox
#### Step 1: Access Zero Trust Dashboard

1. In your Cloudflare dashboard, navigate to **"Zero Trust"**
2. If this is your first time, you may need to set up a Zero Trust account

#### Step 2: Create an Application
1. Go to **Access** → **Applications**
2. Click **"Add an application"**
3. Select **"Self-hosted"** application type

#### Step 3: Configure Application Settings
**Application Configuration:**
- **Application name**: `Proxmox Admin` (or any descriptive name)
- **Subdomain**: `proxmox`
- **Domain**: Select your domain (e.g., `example.com`)
- **Session Duration**: How long users stay logged in (default: 24 hours)

#### Step 4: Create Access Policy
1. **Policy name**: `Admin Access` (descriptive name for this policy)
2. **Action**: Select **"Allow"**
3. **Configure rules**: Define who can access this application

**Common Rule Options:**
- **Email**: Specific email addresses that can access
- **Email domain**: Allow entire domains (e.g., @yourcompany.com)
- **Country**: Geographic restrictions
- **IP ranges**: Specific IP address ranges

**Example Configuration:**
```
Rule Type: Include
Selector: Emails
Value: your-email@example.com
```

#### Step 5: Complete Setup
1. Review your configuration
2. Click **"Add application"** to save

#### Step 6: Test Zero Trust
1. Try accessing `https://proxmox.example.com`
2. You should be redirected to a Cloudflare login page
3. Enter your email address
4. Check your email for a verification code or magic link
5. After verification, you'll be redirected to Proxmox

### Advanced Zero Trust Configuration
#### Multiple Authentication Methods
You can configure additional authentication methods:

1. **Google OAuth**: Allow Google account logins
2. **GitHub OAuth**: Use GitHub accounts for authentication
3. **SAML**: Integration with enterprise identity providers
4. **Multi-factor Authentication**: Require additional verification

#### Session Management
- **Session Duration**: Control how long users stay authenticated
- **Re-authentication**: Require periodic re-authentication for sensitive apps
- **Device Trust**: Remember trusted devices

#### Granular Policies
Create different policies for different user groups:
- **Administrators**: Full access to all services
- **Read-Only Users**: Limited access to monitoring dashboards
- **Contractors**: Time-limited access with additional restrictions

> **🔐 Important**: Zero Trust is now protecting your Proxmox access. Only users with authorized email addresses can access the web interface.

## 🛠️ Service Installations
Now that we have a secure foundation, let's install some useful services. We'll start with ChangeDetection.io as an example of how to deploy services in your home lab.

### ChangeDetection.io - Website Change Monitoring
ChangeDetection.io is a powerful tool for monitoring websites for changes. It's perfect for tracking price changes, content updates, or availability notifications.

**Use Cases:**
- Monitor product prices on e-commerce sites
- Track job postings on career websites
- Monitor competitor website changes
- Get notified when services come back online
- Track changes in documentation or policies

#### Installation Process
##### Step 1: Create LXC Container
1. In Proxmox, create a new LXC container:
   - **Template**: Debian 12 (Bookworm) or Ubuntu 22.04
   - **CPU**: 1 cores
   - **Memory**: 512MB
   - **Storage**: 5-10GB
   - **Network**: vmbr1 (LAN network)

2. **Optional Advanced Settings**:
   - In **Options** → **Features**: Enable **Nesting = ON** if you plan to run Docker containers inside

3. **Enable Auto-Start**:
   - Set **"Start at boot"** to **"Yes"**

##### Step 2: Install Dependencies
Connect to your LXC container and install required packages:

```bash
# Update package repositories
apt update && apt upgrade -y

# Install Python and development tools
apt install -y python3 python3-venv python3-pip git
```

##### Step 3: Download and Setup ChangeDetection.io
```bash
# Navigate to /opt directory for system applications
cd /opt

# Clone the ChangeDetection.io repository
git clone https://github.com/dgtlmoon/changedetection.io.git

# Enter the application directory
cd changedetection.io

# Create a Python virtual environment
python3 -m venv venv

# Activate the virtual environment
source venv/bin/activate

# Install Python dependencies
pip install -r requirements.txt

# Create data directory for storing configurations and data
mkdir datastore
```

##### Step 4: Test Installation
Run ChangeDetection.io to verify installation:

```bash
./venv/bin/python3 changedetection.py -d /opt/changedetection.io/datastore
```

**Testing Access:**
1. Open a web browser from your Ubuntu management VM
2. Navigate to `http://<LXC-IP>:5000`
3. You should see the ChangeDetection.io interface

##### Step 5: Create System Service
Create a systemd service for automatic startup:

```bash
nano /etc/systemd/system/changedetection.service
```

Add the following configuration:

```ini
[Unit]
Description=ChangeDetection.io Website Change Monitor
After=network.target

[Service]
User=root
WorkingDirectory=/opt/changedetection.io
ExecStart=/opt/changedetection.io/venv/bin/python3 changedetection.py -d /opt/changedetection.io/datastore
Restart=always
RestartSec=3

# Logging configuration
StandardOutput=append:/var/log/changedetection.log
StandardError=append:/var/log/changedetection.log

[Install]
WantedBy=multi-user.target
```

**Service Configuration Explanation:**
- **User**: Runs as root (consider creating dedicated user for production)
- **WorkingDirectory**: Application base directory
- **ExecStart**: Command to start the application
- **Restart=always**: Automatically restart if the service crashes
- **StandardOutput/StandardError**: Log all output to file

##### Step 6: Enable and Start Service
```bash
# Reload systemd configuration
systemctl daemon-reload

# Enable service to start at boot
systemctl enable changedetection

# Start the service now
systemctl start changedetection

# Check service status
systemctl status changedetection
```

#### Exposing via Cloudflare Tunnel
##### Step 1: Create DNS Record
```bash
cloudflared tunnel route dns homelab-tunnel changedetection.example.com
```

##### Step 2: Update Tunnel Configuration
Edit your Cloudflare tunnel configuration:

```bash
nano /etc/cloudflared/config.yml
```

Update the configuration to include ChangeDetection.io:

```yaml
tunnel: <UUID>
credentials-file: /root/.cloudflared/<UUID>.json

ingress:
  # Proxmox access
  - hostname: proxmox.example.com
    service: https://192.168.20.10:8006
    originRequest:
      noTLSVerify: true
  
  # ChangeDetection.io access
  - hostname: changedetection.example.com
    service: http://192.168.20.20:5000
    originRequest:
      noTLSVerify: true
      
  # Catch-all for unknown subdomains
  - service: http_status:404
```

**Configuration Notes:**
- Replace `192.168.20.20` with your ChangeDetection.io LXC container's actual IP
- The service uses HTTP (not HTTPS) since it's internal communication
- Each service gets its own subdomain

##### Step 3: Restart Cloudflared
```bash
systemctl restart cloudflared
```

##### Step 4: Test External Access
1. Open a web browser from any internet-connected device
2. Navigate to `https://changedetection.example.com`
3. You should see the ChangeDetection.io interface

#### Basic Usage Guide

**Adding Your First Monitor:**
1. Click **"+ Watch a new URL"**
2. Enter a website URL (e.g., `https://example.com`)
3. Configure check frequency (e.g., every 6 hours)
4. Set up notifications (email, Discord, Slack, etc.)
5. Click **"Watch"** to start monitoring

**Notification Setup:**
1. Go to **Settings** → **Notifications**
2. Configure your preferred notification method:
   - **Email**: Requires SMTP server configuration
   - **Discord**: Webhook URL
   - **Slack**: Webhook URL
   - **Telegram**: Bot token and chat ID

> [!Important]
>  ChangeDetection.io is now monitoring websites and accessible from anywhere, you should consider adding zero trust like as we did in proxmox 

## 📋 Maintenance and Logging
Proper log management is crucial for maintaining a healthy home lab. Without it, logs can consume significant disk space and make troubleshooting difficult.

### Understanding Log Rotation
**Why Log Rotation Matters:**
- **Disk Space**: Prevents logs from filling up your storage
- **Performance**: Keeps log files at manageable sizes
- **Troubleshooting**: Maintains recent logs while archiving older ones
- **Compliance**: Helps maintain audit trails for security

### Implementing Log Rotation for ChangeDetection.io
#### Step 1: Configure Service Logging

First, we need to ensure our service logs to a file instead of just the system journal.

**Edit the Service File:**

```bash
nano /etc/systemd/system/changedetection.service
```

Ensure your service file includes logging directives:

```ini
[Unit]
Description=ChangeDetection.io Website Change Monitor
After=network.target

[Service]
User=root
WorkingDirectory=/opt/changedetection.io
ExecStart=/opt/changedetection.io/venv/bin/python3 changedetection.py -d /opt/changedetection.io/datastore
Restart=always
RestartSec=3

# Configure logging to file
StandardOutput=append:/var/log/changedetection.log
StandardError=append:/var/log/changedetection.log

[Install]
WantedBy=multi-user.target
```

**Logging Configuration Explanation:**
- **StandardOutput=append**: Appends stdout to specified log file
- **StandardError=append**: Appends stderr to the same log file
- **append**: Ensures logs are added to existing file instead of overwriting

#### Step 2: Verify Logrotate Installation
Check if logrotate is installed and running:

```bash
# Check if logrotate is installed
which logrotate

# Check the logrotate timer status
systemctl status logrotate.timer

# If not enabled, enable it
systemctl enable logrotate.timer
systemctl start logrotate.timer
```

#### Step 3: Create Logrotate Configuration
Create a specific configuration for ChangeDetection.io:

```bash
vi /etc/logrotate.d/changedetection
```

Add the following configuration:

```bash
/var/log/changedetection.log {
    weekly
    rotate 4
    compress
    copytruncate
    missingok
    notifempty
    create 644 root root
}
```

**Configuration Options Explained:**

| Option | Description |
|--------|-------------|
| `weekly` | Rotate logs once per week |
| `rotate 4` | Keep 4 weeks of archived logs |
| `compress` | Compress old logs with gzip |
| `copytruncate` | Copy log content then truncate original file |
| `missingok` | Don't error if log file doesn't exist |
| `notifempty` | Don't rotate empty log files |
| `create 644 root root` | Create new log file with specified permissions |

#### Step 4: Apply Configuration Changes
```bash
# Reload systemd to recognize service changes
systemctl daemon-reload

# Restart the service to begin logging to file
systemctl restart changedetection

# Test logrotate configuration (dry run)
logrotate -df /etc/logrotate.d/changedetection
```

#### Step 5: Verify Log Rotation Setup
**Check if logging is working:**

```bash
# View recent log entries
tail -f /var/log/changedetection.log

# Check log file size
ls -lh /var/log/changedetection.log
```

**Test log rotation manually:**

```bash
# Force log rotation (for testing)
logrotate -f /etc/logrotate.d/changedetection

# Check for rotated files
ls -la /var/log/changedetection.log*
```

## Monitoring Your Home Lab
This section guides you through setting up a professional monitoring stack for your home lab. You'll learn how to install and configure tools that provide:
- Real-time system metrics
- Visual dashboards 
- Automated alerts when issues occur
- Trend analysis and performance monitoring

### Setting Up Proxmox VE Exporter
First, we'll install the Prometheus PVE Exporter which collects metrics from your Proxmox hosts.

#### 1. Create a Secure Monitoring User in Proxmox
Start by creating a dedicated user with minimal permissions to access metrics:
1. **Create a Limited Access Role:**
   - In Proxmox web UI: **Datacenter** > **Permissions** > **Roles**
   - Click **Create**
   - **Name:** `monitor`
   - **Privileges:** Select only these three:
     - `Datastore.Audit`
     - `Sys.Audit`
     - `VM.Audit`
   - Click **Add**

2. **Create a Dedicated User:**
   - Go to **Datacenter** > **Permissions** > **Users**
   - Click **Add**
   - **User name:** `monitoring`
   - **Realm:** `Proxmox VE authentication server`
   - Create a strong, unique password (save it securely)
   - Click **Add**

3. **Assign Permissions:**
   - Go to **Datacenter** > **Permissions**
   - Click **Add** > **User Permission**
   - **Path:** `/` (root level)
   - **User:** `monitoring@pve`
   - **Role:** `monitor`
   - Click **Add**

#### 2. Prepare the Host System
Run these commands on your Proxmox host to prepare for the exporter:
```bash
# Update packages and install Python virtual environment
apt update
apt install python3-venv -y

# Create a dedicated system user with no login shell for security
useradd --system --no-create-home --shell /bin/false pve-exporter
```

#### 3. Install the PVE-Exporter in a Virtual Environment
Using a virtual environment keeps dependencies isolated and improves security:
```bash
# Create a directory for the virtual environment
mkdir /opt/pve-exporter

# Create the Python virtual environment
python3 -m venv /opt/pve-exporter/venv

# Install the exporter within the virtual environment
/opt/pve-exporter/venv/bin/python3 -m pip install prometheus-pve-exporter

# Set proper ownership
chown -R pve-exporter:pve-exporter /opt/pve-exporter
```

#### 4. Configure the Exporter
Create the configuration file with your Proxmox credentials:
```bash
# Create configuration directory
mkdir /etc/pve-exporter

# Create and edit configuration file
vi /etc/pve-exporter/pve.yml
```

Add this configuration (replace the password with your actual password):
```yaml
default:
    user: "monitoring@pve"
    password: "YOUR_PASSWORD_HERE"
    verify_ssl: false  # Common for homelabs with self-signed certificates
```

Set secure permissions:
```bash
chown root:pve-exporter /etc/pve-exporter/pve.yml
chmod 640 /etc/pve-exporter/pve.yml
```

#### 5. Create the Service for Auto-Start
Set up a systemd service to manage the exporter:
```bash
vi /etc/systemd/system/pve-exporter.service
```

Add this configuration for system service:
```ini
[Unit]
Description=Prometheus PVE Exporter (venv)
Wants=network-online.target
After=network-online.target

[Service]
User=pve-exporter
Group=pve-exporter
Type=simple
Restart=always
ExecStart=/opt/pve-exporter/venv/bin/pve_exporter --config.file="/etc/pve-exporter/pve.yml"

# Send logs to a dedicated file for easier troubleshooting
StandardOutput=append:/var/log/pve-exporter.log
StandardError=append:/var/log/pve-exporter.log

[Install]
WantedBy=multi-user.target
```

#### 6. Set Up Log Rotation (Optional)
Prevent logs from filling up your disk by setting up rotation:
```bash
vi /etc/logrotate.d/pve-exporter
```

Add this configuration:
```
/var/log/pve-exporter.log {
    weekly           # Rotate logs weekly
    rotate 4         # Keep 4 weeks of logs
    compress         # Compress old logs
    copytruncate     # Keep file handle open during rotation
    missingok        # Don't error if log is missing
    notifempty       # Don't rotate empty logs
    create 644 root root  # New log file permissions
}
```

### Setting Up the Complete Monitoring Stack
Now we'll install Prometheus (metrics database), Grafana (visualization), and Alertmanager (notifications) in a single lightweight container.
#### 1. Create a Monitoring LXC Container
In Proxmox, create a dedicated LXC container:
- **Template**: Debian 12 (Bookworm) or Ubuntu 22.04
- **Resources**: 1 CPU core, 512MB RAM, 5-10GB storage
- **Network**: Your LAN network

All remaining commands should be run **inside this new container**.

#### 2. Install Grafana
Grafana provides beautiful dashboards for visualizing your metrics:
```bash
# Install required packages
sudo apt update
sudo apt install -y apt-transport-https software-properties-common wget gpg

# Add Grafana repository
sudo mkdir -p /etc/apt/keyrings/
wget -q -O - https://apt.grafana.com/gpg.key | gpg --dearmor | sudo tee /etc/apt/keyrings/grafana.gpg > /dev/null
echo "deb [signed-by=/etc/apt/keyrings/grafana.gpg] https://apt.grafana.com stable main" | sudo tee /etc/apt/sources.list.d/grafana.list

# Install and start Grafana
sudo apt update
sudo apt install grafana
sudo systemctl enable --now grafana-server
```

#### 3. Install Prometheus and Alertmanager
Prometheus collects and stores metrics, while Alertmanager handles notifications:
```bash
# Create dedicated users for security
sudo useradd --no-create-home --shell /bin/false prometheus
sudo useradd --no-create-home --shell /bin/false alertmanager

# Create configuration and data directories
sudo mkdir /etc/prometheus /var/lib/prometheus
sudo mkdir /etc/alertmanager /var/lib/alertmanager

# Download and install Prometheus
cd /tmp
wget https://github.com/prometheus/prometheus/releases/download/v2.53.1/prometheus-2.53.1.linux-amd64.tar.gz
tar xvfz prometheus-*.tar.gz
cd prometheus-*.linux-amd64
sudo mv prometheus promtool /usr/local/bin/
cd ..

# Download and install Alertmanager
wget https://github.com/prometheus/alertmanager/releases/download/v0.27.0/alertmanager-0.27.0.linux-amd64.tar.gz
tar xvfz alertmanager-*.tar.gz
cd alertmanager-*.linux-amd64
sudo mv alertmanager amtool /usr/local/bin/
cd ..

# Clean up downloaded files
rm -rf /tmp/prometheus-* /tmp/alertmanager-*

# Set proper permissions
sudo chown -R prometheus:prometheus /etc/prometheus /var/lib/prometheus
sudo chown -R alertmanager:alertmanager /etc/alertmanager /var/lib/alertmanager
```

#### 4. Set Up Telegram Notifications (Optional)
For receiving alerts on your phone:
1. **Get Telegram Credentials:**
   - Message `@BotFather` on Telegram
   - Send `/newbot` and follow instructions to get your API Token
   - Message your new bot (send `/start`)
   - Message `@GetMyID_bot` to get your numeric Chat ID

#### 5. Configure the Monitoring Services
##### 5.1. Create Prometheus Service
```bash
sudo vi /etc/systemd/system/prometheus.service
```

Add this configuration:
```ini
[Unit]
Description=Prometheus
Wants=network-online.target
After=network-online.target

[Service]
User=prometheus
Group=prometheus
Type=simple
ExecStart=/usr/local/bin/prometheus \
    --config.file /etc/prometheus/prometheus.yml \
    --storage.tsdb.path /var/lib/prometheus/

[Install]
WantedBy=multi-user.target
```

##### 5.2. Create Alertmanager Service
```bash
sudo vi /etc/systemd/system/alertmanager.service
```

Add this configuration:
```ini
[Unit]
Description=Alertmanager
Wants=network-online.target
After=network-online.target

[Service]
User=alertmanager
Group=alertmanager
Type=simple
ExecStart=/usr/local/bin/alertmanager \
    --config.file /etc/alertmanager/alertmanager.yml \
    --storage.path /var/lib/alertmanager/

[Install]
WantedBy=multi-user.target
```

##### 5.3. Create Alert Rules

These rules define when alerts will be triggered:
```bash
sudo vi /etc/prometheus/pve_rules.yml
```

Add this configuration:
```yaml
groups:
# General system alerts
- name: general-alerts
  rules:
  # Alert on too many restarts
  - alert: TooManyRestarts
    expr: changes(process_start_time_seconds[15m]) > 2
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "Too Many Restarts"
      description: "The process '{{ $labels.job }}' on instance '{{ $labels.instance }}' has restarted more than 2 times in the last 15 minutes."
      description_resolved: "The process '{{ $labels.job }}' on instance '{{ $labels.instance }}' has stabilized."

# Proxmox VE specific alerts
- name: proxmox-alerts
  rules:
  # Alert if the Proxmox Exporter is down
  - alert: ProxmoxExporterDown
    expr: up{job="pve"} == 0
    for: 5m
    labels:
      severity: critical
    annotations:
      summary: "Proxmox Exporter Down"
      description: "The proxmox-exporter target {{ $labels.instance }} has been down for more than 5 minutes."
      description_resolved: "The proxmox-exporter target {{ $labels.instance }} is back up."

  # Alert if Node Exporter is down
  - alert: ProxmoxNodeExporterDown
    expr: up{job="pve-node"} == 0
    for: 5m
    labels:
      severity: critical
    annotations:
      summary: "Proxmox Node Exporter Down"
      description: "The node-exporter target {{ $labels.instance }} has been down for more than 5 minutes."
      description_resolved: "The node-exporter target {{ $labels.instance }} is back up."

  # Storage warning (80% full)
  - alert: ProxmoxStorageWarning
    expr: ((pve_disk_usage_bytes / pve_disk_size_bytes) * 100 > 80) * on(id) group_left(name) pve_guest_info
    for: 15m
    labels:
      severity: warning
    annotations:
      summary: "Storage Warning"
      description: "System '{{ $labels.name }}' storage is {{ $value | printf \"%.2f\" }}% full. Please investigate."
      description_resolved: "System '{{ $labels.name }}' storage is back to normal levels at {{ $value | printf \"%.2f\" }}% full."

  # Storage critical (90% full)
  - alert: ProxmoxStorageCritical
    expr: ((pve_disk_usage_bytes / pve_disk_size_bytes) * 100 > 90) * on(id) group_left(name) pve_guest_info
    for: 15m
    labels:
      severity: critical
    annotations:
      summary: "Storage Critical"
      description: "System '{{ $labels.name }}' storage is {{ $value | printf \"%.2f\" }}% full! IMMEDIATE ACTION REQUIRED."
      description_resolved: "System '{{ $labels.name }}' storage is back to normal levels at {{ $value | printf \"%.2f\" }}% full."
```

##### 5.4. Configure Prometheus
Create the main Prometheus configuration:
```bash
sudo vi /etc/prometheus/prometheus.yml
```

Add this configuration (replace `192.168.1.10` with your PVE host IP):
```yaml
# Global configuration
global:
  scrape_interval: 15s  # How often to collect metrics

# Load alert rule files
rule_files:
  - "/etc/prometheus/pve_rules.yml"

# Alerting configuration
alerting:
  alertmanagers:
    - static_configs:
        - targets:
            - 'localhost:9093'

# Metric collection jobs
scrape_configs:
  # Monitor Prometheus itself
  - job_name: "prometheus"
    static_configs:
      - targets: ["localhost:9090"]

  # Monitor Alertmanager
  - job_name: "alertmanager"
    static_configs:
      - targets: ["localhost:9093"]

  # Monitor Proxmox via PVE Exporter
  - job_name: "pve"
    metrics_path: /pve
    
    # Enable all metric collectors
    params:
      module: [default]
      cluster: ['1']
      node: ['1']
      qemu: ['1']
      lxc: ['1']
      
    static_configs:
      - targets: ['192.168.1.10:9221']  # Change to your PVE Host IP
```

##### 5.5. Configure Alertmanager

Set up notification routing:
```bash
sudo vi /etc/alertmanager/alertmanager.yml
```

Add this configuration (replace token and chat ID with your values):
```yaml
global:
  # Telegram API endpoint
  telegram_api_url: 'https://api.telegram.org'

route:
  receiver: 'telegram-alerts'
  group_by: ['alertname', 'severity']
  group_wait: 30s         # Wait 30s to group similar alerts
  group_interval: 5m      # Wait 5m before sending a new alert for group
  repeat_interval: 4h     # Wait 4h before resending

receivers:
- name: 'telegram-alerts'
  telegram_configs:
  - bot_token: 'HIDDEN'   # Your bot token here
    chat_id: ID           # Your chat ID here (without quotes)
    api_url: 'https://api.telegram.org'
    parse_mode: 'HTML'
    disable_notifications: false
    message: |
      {{ if eq .Status "firing" -}}
      🔥 <b>ALERT NOTIFICATION</b>: {{ len .Alerts }} alert(s) firing
        {{ if eq .CommonLabels.severity "critical" }}
      🔴 <b>CRITICAL ALERT</b>: {{ len .Alerts }} alert(s) firing
        {{ else if eq .CommonLabels.severity "warning" }}
      🟡 <b>WARNING ALERT</b>: {{ len .Alerts }} alert(s) firing
        {{ end }}
      <b>Status:</b> FIRING
      <b>Alert:</b> {{ .CommonLabels.alertname }}
      <b>Severity:</b> {{ .CommonLabels.severity | toUpper }}
      <b>Summary:</b> {{ .CommonAnnotations.summary }}

      <b>Details:</b>
      {{ range .Alerts -}}
      • <b>System:</b> {{ if .Labels.name }}{{ .Labels.name }}{{ else }}{{ .Labels.instance }}{{ end }}
      • <b>Started:</b> {{ .StartsAt.Format "2006-01-02 15:04:05" }}
      • {{ .Annotations.description }}

      {{ end -}}
      {{ else if eq .Status "resolved" -}}
      ✅ <b>RESOLVED ALERTS</b>: {{ len .Alerts }} alert(s) resolved

      <b>Status:</b> RESOLVED
      <b>Alert:</b> {{ .CommonLabels.alertname }}
      <b>Severity:</b> {{ .CommonLabels.severity | toUpper }}

      <b>Details:</b>
      {{ range .Alerts }}
      • <b>System:</b> {{ if .Labels.name }}{{ .Labels.name }}{{ else }}{{ .Labels.instance }}{{ end }}
      • <b>Started:</b> {{ .StartsAt.Format "2006-01-02 15:04:05" }}
      • <b>Ended:</b> {{ .EndsAt.Format "2006-01-02 15:04:05" }}
        {{ if .Annotations.description_resolved }}
      {{ .Annotations.description_resolved -}}
        {{ else }}
        {{ .Annotations.description -}}
        {{ end -}}
      {{ end -}}
      {{ end -}}
```

#### 6. Start the Monitoring Stack
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now prometheus
sudo systemctl enable --now alertmanager
```

#### 7. Verify and Configure Dashboards
1. **Check Prometheus:**
   - Open `http://<YOUR-LXC-IP>:9090` in a browser
   - Navigate to **Status** → **Targets**
   - Verify all targets show as **UP** (green)

2. **Configure Grafana:**
   - Open `http://<YOUR-LXC-IP>:3000` in a browser
   - Login with default credentials: `admin`/`admin` (change on first login)
   - Add Prometheus as a data source:
     - Go to **Configuration** (gear icon) → **Data Sources** → **Add**
     - Select **Prometheus**
     - URL: `http://localhost:9090`
     - Click **Save & Test**
   - Import the Proxmox dashboard:
     - Go to **Create** (+) → **Import**
     - Enter dashboard ID: `10347`
     - Select your Prometheus data source
     - Click **Import**

### Optional Enhancements
#### Testing Alert Notifications
Create a script to send test alerts:
```bash
vi send_test_alert.sh
```

Add this content:
```bash
#!/bin/bash

# Configuration
name=$RANDOM
instance="$name.example.net"
severity='warning'
summary='Testing Alertmanager'
service='my-service'
AM_URL='http://localhost:9093'

# Function to fire alert via Alertmanager API
fire_alert() {
    curl -s -XPOST "$AM_URL/api/v2/alerts" -H "Content-Type: application/json" -d "[
        {
            \"status\": \"firing\",
            \"labels\": {
                \"alertname\": \"$name\",
                \"service\": \"$service\",
                \"severity\": \"$severity\",
                \"instance\": \"$instance\"
            },
            \"annotations\": {
                \"summary\": \"$summary\",
                \"description\": \"This alert is firing for $instance\",
                \"description_resolved\": \"This alert has been resolved for $instance\"
            },
            \"generatorURL\": \"https://prometheus.local/<generating_expression>\"
        }
    ]"
    echo ""
    echo "Alert fired: $name"
}

# Function to resolve alert via amtool
resolve_alert() {
    amtool --alertmanager.url="$AM_URL" alert resolve \
        alertname="$name" \
        service="$service" \
        instance="$instance" \
        severity="$severity"
    echo "Alert resolved: $name"
}

# Main
fire_alert
read -p "Press enter to resolve alert"
resolve_alert
```

Make it executable and run it:
```bash
chmod +x send_test_alert.sh
./send_test_alert.sh
```

#### Installing Node Exporter for System Metrics
For more detailed system metrics (CPU, memory, disk I/O, temperatures), install Node Exporter on your Proxmox host:
```bash
# Download and extract on your Proxmox host
cd /usr/local/bin
wget https://github.com/prometheus/node_exporter/releases/download/v1.8.2/node_exporter-1.8.2.linux-amd64.tar.gz
tar xvf node_exporter-1.8.2.linux-amd64.tar.gz
mv node_exporter-1.8.2.linux-amd64/node_exporter .
rm -rf node_exporter-1.8.2.linux-amd64*

# Create systemd service
cat <<EOF | tee /etc/systemd/system/node_exporter.service
[Unit]
Description=Prometheus Node Exporter
After=network.target

[Service]
User=nobody
ExecStart=/usr/local/bin/node_exporter \
  --collector.systemd \
  --collector.processes \
  --collector.hwmon

[Install]
WantedBy=multi-user.target
EOF

# Start the service
systemctl daemon-reload
systemctl enable --now node_exporter

# Test that metrics are being exposed
curl http://localhost:9100/metrics
```

#### Monitoring CPU Temperature
Install temperature sensors and update your alert rules:
```bash
# On your Proxmox host
apt update
apt install lm-sensors -y
sensors-detect  # Accept defaults or choose specific sensors

# Restart node_exporter to pick up sensors
systemctl restart node_exporter
```

Add temperature alerts to `/etc/prometheus/pve_rules.yml`:
```yaml
# Node Exporter related alerts
- name: node-exporter-rules
  rules:
  # Alert if Node Exporter is down
  - alert: NodeExporterDown
    expr: up{job=~"pve-node|node-exporter"} == 0
    for: 5m
    labels:
      severity: critical
    annotations:
      summary: "Node Exporter Down"
      description: "The node-exporter target {{ $labels.instance }} has been down for more than 5 minutes."
      description_resolved: "The node-exporter target {{ $labels.instance }} is back up."

  # Warning temperature alert (70°C)
  - alert: HighSensorTemperatureWarning
    expr: avg_over_time(node_hwmon_temp_celsius{job="pve-node"}[2m]) > 70
    for: 2m
    labels:
      severity: warning
    annotations:
      summary: "High Sensor Temperature (Warning)"
      description: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has had an average temperature above 70°C for the last 2 minutes. Current value is {{ $value | printf \"%.2f\" }}°C."
      description_resolved: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has returned to normal levels at {{ $value | printf \"%.2f\" }}°C."

  # Critical temperature alert (80°C)
  - alert: HighSensorTemperatureCritical
    expr: avg_over_time(node_hwmon_temp_celsius{job="pve-node"}[2m]) > 80
    for: 2m
    labels:
      severity: critical
    annotations:
      summary: "High Sensor Temperature (Critical)"
      description: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has had an average temperature above 80°C for the last 2 minutes. This is critical. Current value is {{ $value | printf \"%.2f\" }}°C."
      description_resolved: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has returned to normal levels at {{ $value | printf \"%.2f\" }}°C."
```