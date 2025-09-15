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
