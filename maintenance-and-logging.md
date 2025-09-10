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
