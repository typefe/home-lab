# Proxmox VE Bind Mount Guide for LXC Containers

This guide provides a comprehensive approach to managing shared storage and permissions between multiple **unprivileged** LXC containers on a Proxmox VE host.

## The Core Concept: UID/GID Mapping

Proxmox enhances security for unprivileged containers by mapping user and group IDs. When you create a resource inside a container with a specific UID or GID (e.g., GID `1010`), Proxmox adds a large offset (typically `100000`) to it on the host.

-   **Inside Container:** GID `1010`
-   **On Proxmox Host:** GID `101010` (100000 + 1010)

We will leverage this mapping to create a "shared" group that works across multiple containers without creating any new groups on the Proxmox host itself.

## Step-by-Step Solution

### Step 1: Create a Shared Group in an LXC Container

First, decide on a common group and GID. In this example, we'll use the group `media` with GID `1010`.

1.  **Log into one of your LXC containers** (e.g., your torrent container).
2.  Create the new group with your chosen GID.

    ```bash
    # In the LXC shell
    groupadd -g 1010 media
    ```

3.  Add the user that runs your application to this new group. For Transmission, the user is often `debian-transmission`.

    ```bash
    # Replace <user> with your application's user
    usermod -a -G media <user>
    ```

### Step 2: Set Permissions on the Proxmox Host

Now, we'll configure the bind-mounted directory on the host to use the mapped GID.

1.  **Log into the Proxmox Host shell.**
2.  Change the group ownership of the shared directory. The GID you use here must be the **container GID + 100000**.

    ```bash
    # On the Proxmox Host shell
    # chown -R <user>:<gid_on_host> /path/to/directory
    # The user can be root. The GID is 101010 (100000 + 1010).
    chown -R root:101010 /mnt/DATA/DATA
    ```

3.  Set the `setgid` permission. This is crucial as it ensures all new files and folders created in this directory automatically inherit the correct group (`101010`), preventing future permission issues.

    ```bash
    # On the Proxmox Host shell
    chmod -R 2775 /mnt/DATA/DATA
    ```

    **What does `2775` mean?**
    -   `775`: The owner (`root`) can read/write/execute, the group (`101010`) can read/write/execute, and others can read/execute.
    -   `2` (the "setgid" bit): Forces all new files/folders to inherit the parent directory's group.

### Step 3: Configure Other LXC Containers

For every other container that needs access to the shared data, you simply repeat the process of creating the group and adding the user.

1.  **Log into your other LXC container** (e.g., your media server).
2.  Create the **exact same group with the same GID**.

    ```bash
    # In the other LXC shell
    groupadd -g 1010 media
    ```

3.  Add the application's user in this container to the `media` group.

    ```bash
    # Replace <media-server-user> with the correct user (e.g., jellyfin)
    usermod -a -G media <media-server-user>
    ```

Now, both containers share a common group ID for the bind-mounted directory, allowing them to read and write files without permission conflicts.