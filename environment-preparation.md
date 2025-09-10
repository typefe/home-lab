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
