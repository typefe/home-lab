# Transmission Torrent Server Guide

Transmission is a lightweight and popular BitTorrent client. This guide details how to set up a Transmission server inside a Proxmox LXC container, with optional steps for mounting external storage for your downloads.

## 1. Create and Prepare the LXC Container

First, create a new, lightweight container for Transmission.

1.  **In the Proxmox web UI, click `Create CT`**.
2.  **General**: Assign a **Hostname** (e.g., `transmission`) and a secure **Password**.
3.  **Template**: Select a minimal template, such as **Debian** or **Ubuntu**.
4.  **Disks**: An 8GB disk is sufficient for the operating system.
5.  **CPU/Memory**: 1 core and 512MB of RAM is a good starting point.
6.  **Network**: Assign a static IP address to easily access the container (e.g., `192.168.20.60/24`).
7.  **Start the container** after it's created. Open its console from the Proxmox UI.
8.  **Run a full system update**:
    ```bash
    apt update && apt upgrade -y
    ```

## 2. Install Transmission
With the container running, install the Transmission daemon.

1.  **Enter the LXC console** (if you aren't already).
2.  **Install the package**:
    ```bash
    apt install transmission-daemon
    ```

## 3. Configure Transmission
Next, configure the server settings. It's crucial to stop the service before editing the configuration file.

1.  **Stop the Transmission service**:
    ```bash
    systemctl stop transmission-daemon
    ```
2.  **Edit the settings file** with a text editor like `nano`:
    ```bash
    nano /etc/transmission-daemon/settings.json
    ```
3.  **Make the following key changes**:
    *   **Allow remote access** by adding your local network to the whitelist. This allows you to connect from other machines on your network.
        ```json
        "rpc-whitelist": "127.0.0.1,192.168.20.*",
        ```
    *   **Set login credentials** to secure the web interface.
        ```json
        "rpc-authentication-required": true,
        "rpc-username": "your_username",
        "rpc-password": "your_secure_password",
        ```
4.  **Save the file and exit** the editor.

## 4. (Optional) Configure External Storage
If you want to store downloads on a separate, larger drive, follow these steps to create a bind mount. This makes a directory from the Proxmox host accessible inside the LXC container.

### Step 4.1: Prepare Host Directory
1.  On the **Proxmox host shell**, create a directory to store your downloads.
    ```bash
    # Example path on the host
    mkdir -p /DATA/DATA/downloads
    ```

### Step 4.2: Create the Bind Mount
1.  **Shut down the LXC container**:
    ```bash
    # Run from the Proxmox host shell
    pct shutdown <CT_ID>
    ```
2.  **Create the bind mount**. This command maps the host directory (`/DATA/DATA/downloads`) to a mount point (`/mnt/downloads`) inside the container.
    ```bash
    # Run from the Proxmox host shell
    pct set <CT_ID> -mp0 /DATA/DATA/downloads,mp=/mnt/downloads
    ```

### Step 4.3: Set Permissions
For an unprivileged container, you must map the container's user ID to the host.

1.  **Start the container**: `pct start <CT_ID>`
2.  **Find the Transmission User ID** inside the container:
    *   Enter the container: `pct enter <CT_ID>`
    *   Find the user: `ps aux | grep transmission-daemon` (it's often `debian-transmission`).
    *   Get its UID and GID: `id debian-transmission`. Note the **Container UID** (e.g., 103) and **Container GID** (e.g., 112).
3.  **Calculate Host IDs**: Add the default offset of `100000` to the container IDs.
    *   **Host UID** = Container UID + 100000 (e.g., 100103)
    *   **Host GID** = Container GID + 100000 (e.g., 100112)
4.  **Set Ownership on the Host**:
    *   Exit the container console.
    *   On the **Proxmox host shell**, use the calculated **Host IDs** to set ownership on the downloads directory.
        ```bash
        # chown -R <Host_UID>:<Host_GID> /path/to/host/folder
        chown -R 100103:100112 /DATA/DATA/downloads
        ```

### Step 4.4: Update Transmission Paths
1.  **Enter the container** and stop the service:
    ```bash
    pct enter <CT_ID>
    systemctl stop transmission-daemon
    ```
2.  **Edit the settings file** again: `nano /etc/transmission-daemon/settings.json`
3.  **Update the download paths** to point to the mounted directory:
    ```json
    "download-dir": "/mnt/downloads/complete",
    "incomplete-dir": "/mnt/downloads/incomplete",
    "incomplete-dir-enabled": true,
    ```
4.  **Create these new directories** inside the container:
    ```bash
    mkdir -p /mnt/downloads/complete
    mkdir -p /mnt/downloads/incomplete
    ```

## 5. Start and Access Transmission
1.  **Start the service** to apply all changes:
    ```bash
    systemctl start transmission-daemon
    ```
2.  **Access the Web UI** by navigating to `http://<LXC_IP>:9091` in your browser. Log in with the credentials you configured.
