#  Bittorrent Client qBittorrent Setup Guide

qBittorrent is a lightweight, open-source, and feature-rich BitTorrent client. This guide details how to set it up in a Proxmox LXC container, using bind mounts for storage and a dedicated user for enhanced security.

## 📝 Table of Contents

- [Bittorrent Client qBittorrent Setup Guide](#bittorrent-client-qbittorrent-setup-guide)
  - [📝 Table of Contents](#-table-of-contents)
  - [Part 1: Create the LXC Container](#part-1-create-the-lxc-container)
  - [Part 2: Prepare Storage and Bind Mounts](#part-2-prepare-storage-and-bind-mounts)
  - [Part 3: Create a Dedicated User](#part-3-create-a-dedicated-user)
  - [Part 4: Install and Configure qBittorrent](#part-4-install-and-configure-qbittorrent)
  - [Part 5: Create the Systemd Service](#part-5-create-the-systemd-service)
  - [Part 6: Final Configuration and Usage](#part-6-final-configuration-and-usage)
  - [7. Start the Service and Configure the Web UI](#7-start-the-service-and-configure-the-web-ui)

---

## Part 1: Create the LXC Container

First, set up a new container for the service.

1.  In the Proxmox UI, click **Create CT**.
2.  **General**: Give it a **Hostname** (e.g., `qbittorrent`) and a secure **Password**.
3.  **Template**: Choose a minimal template like **Debian** or **Ubuntu**.
4.  **Disks**: 8GB is sufficient for the operating system.
5.  **CPU/Memory**: 1 core and 512MB of RAM is a good starting point.
6.  **Network**: Assign a **Static IP address** (e.g., `192.168.20.60/24`).
7.  Start the container, open its console, and perform a full system update:
    ```bash
    apt update && apt upgrade -y
    ```

---

## Part 2: Prepare Storage and Bind Mounts

Create directories on the Proxmox host to store your files and then link them to the container. This ensures your data persists even if the container is destroyed.

1.  On the **Proxmox host shell**, create the folders you need:
    ```bash
    mkdir -p /DATA/DATA/downloads
    ```
2.  **Shut down the container**:
    ```bash
    pct shutdown <CT_ID>
    ```
3.  On the **Proxmox host shell**, add the bind mount. This maps the host directory to a path inside the container.
    ```bash
    # Mount for downloads
    pct set <CT_ID> -mp0 /DATA/DATA/downloads,mp=/mnt/downloads
    ```

> **Note:** For a more detailed guide on managing shared storage and permissions across multiple containers, please refer to the [**Proxmox VE Bind Mount Guide**](../bind-mount.md).

---

## Part 3: Create a Dedicated User

For security, we will run the service with a dedicated, non-root user and grant it access to the storage.

1.  **Start and enter the container**: `pct start <CT_ID>` and then `pct enter <CT_ID>`.
2.  Create a system user named `torrent`:
    ```bash
    adduser --system --group torrent
    ```
3.  The user needs a valid home directory. Set it, create it, and give the user ownership:
    ```bash
    usermod -d /home/torrent torrent
    mkdir -p /home/torrent
    chown -R torrent:torrent /home/torrent
    ```
4.  **Find the User and Group ID (inside the container)**:
    ```bash
    id torrent
    ```
    Note the **UID** and **GID** (e.g., `uid=103`, `gid=112`).

5.  **Set Permissions (on the Proxmox host)**:
    -   Exit the container (`exit`).
    -   On the **Proxmox host shell**, use the UID/GID you noted to set ownership on the downloads folder. For unprivileged containers, Proxmox adds an offset of `100000`.
    ```bash
    # chown -R <Container_UID+100000>:<Container_GID+100000> /path/to/host/folder
    chown -R 100103:100112 /DATA/DATA/downloads
    ```

---

## Part 4: Install and Configure qBittorrent

Install the software and create its initial configuration file *as the correct user*.

1.  Install the package:
    ```bash
    apt install qbittorrent-nox -y
    ```
2.  Run qBittorrent once **as the `torrent` user** to generate the config files in the correct location (`/home/torrent/.config`):
    ```bash
    sudo -u torrent qbittorrent-nox
    ```
3.  You'll be asked to accept the legal terms. Type **`y`** and press **Enter**.
4.  Once you see the Web UI message, stop the process with **`Ctrl + C`**.

---

## Part 5: Create the Systemd Service

Create a service file so `systemd` can manage qBittorrent and start it on boot.

1.  Create and open the file with `nano`:
    ```bash
    nano /etc/systemd/system/qbittorrent-nox.service
    ```
2.  Paste in the following configuration. It's set up to use the `torrent` user.
    ```toml
    [Unit]
    Description=qBittorrent Command Line Client
    After=network.target

    [Service]
    Type=forking
    User=torrent
    Group=torrent
    UMask=007
    ExecStart=/usr/bin/qbittorrent-nox -d --webui-port=8080
    Restart=on-failure

    [Install]
    WantedBy=multi-user.target
    ```

---

## Part 6: Final Configuration and Usage

1.  Reload the systemd daemon to recognize the new service:
    ```bash
    systemctl daemon-reload
    ```
2.  Enable the service to start on boot and start it now:
    ```bash
    systemctl enable --now qbittorrent-nox.service
    ```
3.  **Access the Web UI** by navigating to `http://<YOUR_CONTAINER_IP>:8080`.
4.  **Log in** with the default credentials:
    -   **Username:** `admin`
    -   **Password:** `adminadmin`
5.  **IMPORTANT**: Immediately change the default password. Go to **Tools -> Options -> Web UI** and set a new, strong password.
6.  **Set the Default Save Path**:
    -   Go to **Tools -> Options -> Downloads**.
    -   In the **Saving Management** section, set the **Default Save Path** to the bind-mounted directory: `/mnt/downloads`.
    -   Click **Save**.

Your qBittorrent instance is now ready to use!
## 7. Start the Service and Configure the Web UI
Finally, enable the service and perform the initial Web UI setup.
1. **Enter the container again**: `pct enter <CT_ID>`
2. Reload `systemd`, enable the service for auto-start, and start it now:
```bash
systemctl daemon-reload 
systemctl enable qbittorrent-nox 
systemctl start qbittorrent-nox
```
3. Check the status to confirm it's `active (running)`:
4. **Access the Web UI**:
	 - **URL**: `http://<LXC_IP_ADDRESS>:8080`
	 - **Default Username**: `admin`
	 - **Default Password**: `adminadmin`
5. **Initial Setup**:
	- Go to **Tools -> Options -> Web UI** and change the default username and password immediately.
	- Go to **Tools -> Options -> Downloads** and set the **Default Save Path** to `/mnt/downloads`.