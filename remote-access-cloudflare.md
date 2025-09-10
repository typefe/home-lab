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
4. Set Boot Order to **2** (after pfSense)

```