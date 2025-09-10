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
