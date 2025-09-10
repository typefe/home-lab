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
