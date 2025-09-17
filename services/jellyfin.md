# Jellyfin LXC Installation Guide for Proxmox

This guide details how to set up a Jellyfin media server in an **unprivileged** LXC container on Proxmox VE, with a focus on direct play (no transcoding) and proper permissions management using a bind mount.

## Prerequisites

1.  **Proxmox VE Host**: A running Proxmox server.
2.  **Debian LXC Template**: Ensure the Debian 12 (Bookworm) template is downloaded.
3.  **Media Storage**: A dedicated directory on your Proxmox host where your media files are stored (e.g., `/mnt/DATA/media`).

---

## Part 1: Create the LXC Container

First, create the container that will host Jellyfin.

1.  In the **Proxmox Web UI**, click **Create CT**.
2.  Fill out the container settings:
    -   **General**:
        -   **Hostname**: `jellyfin`
        -   **Password**: Set a secure root password.
    -   **Template**:
        -   **Storage**: `local`
        -   **Template**: `debian-12-standard_...`
    -   **Disks**:
        -   **Root Disk Size**: 8 GB (sufficient for the OS and Jellyfin).
    -   **CPU**:
        -   **Cores**: 2 (adjust based on your hardware).
    -   **Memory**:
        -   **Memory**: 1024 MB (1 GB is a good starting point for direct play).
    -   **Network**:
        -   **Name**: `eth0`
        -   **Bridge**: `vmbr0`
        -   **VLAN Tag**: (Optional, if you use VLANs).
        -   **IPv4/CIDR**: Set a static IP (e.g., `192.168.1.10/24`) or use DHCP.
        -   **Gateway (IPv4)**: Your network's gateway.
    -   **DNS**:
        -   **DNS Domain**: (Optional, your local domain).
        -   **DNS Servers**: Your DNS resolver (e.g., your router's IP or `1.1.1.1`).
3.  Confirm and click **Finish**.

---

## Part 2: Configure Media Bind Mount and Permissions

To give the Jellyfin container access to your media files without compromising security, we will use a bind mount and map permissions correctly.

For a more detailed explanation, refer to the [Proxmox Bind Mount Guide](../bind-mount.md).

### Step 1: Create the Bind Mount

On the **Proxmox Host shell**, run the following command to mount your host's media directory into the container.

-   Replace `<container-id>` with your Jellyfin container's ID (e.g., `101`).
-   Replace `/mnt/DATA/media` with the actual path to your media on the host.

```bash
# On the Proxmox Host shell
pct set <container-id> -mp0 /mnt/DATA/media,mp=/media
```

This command mounts the host's `/mnt/DATA/media` directory to `/media` inside the Jellyfin container.

### Step 2: Create a Shared Group in the Container

We will create a `media` group with a consistent GID (`1010`) that can be shared across multiple containers.

1.  **Start the container** and open its console.
2.  Create the `media` group.

    ```bash
    # In the LXC shell
    groupadd -g 1010 media
    ```

### Step 3: Set Permissions on the Proxmox Host

Now, we align the host directory's permissions with the container's group. Because the container is unprivileged, Proxmox maps the container's GID `1010` to `101010` on the host.

1.  On the **Proxmox Host shell**, change the group ownership and set permissions.

    ```bash
    # On the Proxmox Host shell
    # The GID is 101010 (100000 + 1010 from the container)
    chown -R root:101010 /mnt/DATA/media
    
    # Set the 'setgid' bit to ensure new files inherit the group
    chmod -R 2775 /mnt/DATA/media
    ```

    The `2775` permission ensures that any new file or folder created in `/mnt/DATA/media` will automatically be assigned to the `101010` group, preventing future permission issues.

---

## Part 3: Install Jellyfin

With permissions handled, we can now install Jellyfin inside the container.

1.  **Update the container's packages**:

    ```bash
    # In the LXC shell
    apt update && apt upgrade -y
    ```

2.  **Install dependencies**:

    ```bash
    apt install apt-transport-https software-properties-common gnupg -y
    ```

3.  **Add the Jellyfin repository**:

    ```bash
    # Import the GPG key
    wget -O - https://repo.jellyfin.org/debian/jellyfin_team.gpg.key | apt-key add -
    
    # Add the repository source
    echo "deb [arch=amd64] https://repo.jellyfin.org/debian bookworm main" | tee /etc/apt/sources.list.d/jellyfin.list
    ```

4.  **Install Jellyfin**:

    ```bash
    apt update
    apt install jellyfin -y
    ```

5.  **Enable and check the Jellyfin service**:

    ```bash
    systemctl enable --now jellyfin
    systemctl status jellyfin
    ```

---

## Part 4: Grant Jellyfin Access to the Media Group

By default, the `jellyfin` user runs the service. We need to add this user to our shared `media` group. Additionally, `systemd` services require an explicit override to recognize supplementary groups.

1.  **Add the `jellyfin` user to the `media` group**:

    ```bash
    # In the LXC shell
    usermod -a -G media jellyfin
    ```

2.  **Create a systemd override file**:
    This ensures the Jellyfin process starts with the correct group permissions.

    ```bash
    # Create the directory
    mkdir -p /etc/systemd/system/jellyfin.service.d
    
    # Create and edit the override file
    nano /etc/systemd/system/jellyfin.service.d/jellyfin.service.conf
    ```

3.  **Add the following content** to the file:

    ```toml
    [Service]
    # The primary user and group remain jellyfin
    User=jellyfin
    Group=jellyfin
    
    # Add 'media' as a supplementary group
    SupplementaryGroups=media
    ```

4.  **Reload systemd and restart Jellyfin**:

    ```bash
    systemctl daemon-reload
    systemctl restart jellyfin
    ```

✅ This step is crucial. It guarantees that the Jellyfin service can read and write to the `/media` directory.

---

## Part 5: Configure Jellyfin Media Library

1.  Access the Jellyfin web interface at `http://<container-ip>:8096`.
2.  Complete the initial setup wizard.
3.  Go to **Admin Dashboard → Libraries → Add Media Library**.
4.  Configure your library:
    -   **Content Type**: Movies, TV Shows, etc.
    -   **Folder**: Click the `+` and add the path `/media`.
5.  Save the library. Jellyfin will begin scanning your files.

---

## Part 6: Disable Transcoding (Optional)

If your clients can direct play all your media, you can disable transcoding to save CPU resources.

1.  Go to **Admin Dashboard → Playback**.
2.  Under the **Transcoding** section, set the following:
    -   **Transcoding → Hardware Acceleration**: `None`
    -   **Enable fallback to software transcoding**: Uncheck.
3.  Scroll down and **Save**.