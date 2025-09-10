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
      description: "System '{{ $labels.name }}' storage is {{ $value | printf "%.2f" }}% full. Please investigate."
      description_resolved: "System '{{ $labels.name }}' storage is back to normal levels at {{ $value | printf "%.2f" }}% full."

  # Storage critical (90% full)
  - alert: ProxmoxStorageCritical
    expr: ((pve_disk_usage_bytes / pve_disk_size_bytes) * 100 > 90) * on(id) group_left(name) pve_guest_info
    for: 15m
    labels:
      severity: critical
    annotations:
      summary: "Storage Critical"
      description: "System '{{ $labels.name }}' storage is {{ $value | printf "%.2f" }}% full! IMMEDIATE ACTION REQUIRED."
      description_resolved: "System '{{ $labels.name }}' storage is back to normal levels at {{ $value | printf "%.2f" }}% full."
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
severity=\'warning\'
summary=\'Testing Alertmanager\'
service=\'my-service\'
AM_URL=\'http://localhost:9093\'

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
    amtool --alertmanager.url=\"$AM_URL\" alert resolve \
        alertname=\"$name\" \
        service=\"$service\" \
        instance=\"$instance\" \
        severity=\"$severity\"
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
      description: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has had an average temperature above 70°C for the last 2 minutes. Current value is {{ $value | printf "%.2f" }}°C."
      description_resolved: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has returned to normal levels at {{ $value | printf "%.2f" }}°C."

  # Critical temperature alert (80°C)
  - alert: HighSensorTemperatureCritical
    expr: avg_over_time(node_hwmon_temp_celsius{job="pve-node"}[2m]) > 80
    for: 2m
    labels:
      severity: critical
    annotations:
      summary: "High Sensor Temperature (Critical)"
      description: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has had an average temperature above 80°C for the last 2 minutes. This is critical. Current value is {{ $value | printf "%.2f" }}°C."
      description_resolved: "The sensor '{{ $labels.sensor }}' on host {{ $labels.instance }} has returned to normal levels at {{ $value | printf "%.2f" }}°C."
```