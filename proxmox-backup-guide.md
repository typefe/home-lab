# Proxmox Backup Guide

This guide provides best practices for backing up your Proxmox containers and VMs efficiently.

## 1. Exclude Large Data Disks From Backups

To save space and speed up backup times, it's crucial to exclude large data disks or bind mounts that don't need to be part of the main backup. This is common for services that handle large amounts of data, like Nextcloud, Transmission, or databases.

**Why exclude data disks?**
- **Faster Backups**: Smaller backup sizes mean quicker completion.
- **Reduced Storage**: Avoids storing redundant or non-essential data.
- **Focus on Essentials**: Ensures that your backups contain the critical root filesystem and configurations, making restores cleaner.

**How to exclude a disk:**

1.  Select the container or VM in the Proxmox GUI.
2.  Go to the **Hardware** tab.
3.  Find the disk (or mount point) you want to exclude.
4.  Select the disk and click **Edit**.
5.  Uncheck the **backup** option (or set `backup=0`).
6.  Click **OK**.

✅ **This ensures only the container/VM rootfs and configuration are included in backups.**

**Examples:**

-   **Nextcloud**: Exclude the mount point for user data (e.g., `/mnt/DATA`).
-   **Transmission**: Exclude the directory where downloads are stored.
-   **PostgreSQL**: Exclude the data mount if you only need to back up the configuration and can restore data separately.

## 2. Create a Scheduled Backup Job

Automated, scheduled backups are essential for a reliable home lab. Proxmox makes it easy to set up a recurring backup job.

**How to create a backup job:**

1.  In the Proxmox GUI, navigate to **Datacenter → Backup**.
2.  Click the **Add** button to create a new backup job.
3.  Configure the backup settings:
    -   **Node**: Select your Proxmox node (e.g., `pve`).
    -   **Storage**: Choose your designated backup storage (e.g., `data`, `local`, or a Proxmox Backup Server `pbs`).
    -   **Schedule**: Set a regular schedule. A weekly or daily backup is recommended. For example, to run every Sunday at 3:00 AM, you would set it to `Sun 03:00`.
    -   **Selection mode**: Choose **All** to automatically include any new containers or VMs in the backup job.
    -   **Mode**: Select **Snapshot**. This is the preferred mode as it allows for backups of running machines with minimal interruption.
    -   **Compression**: Choose **ZSTD (fast & small)**. It offers a great balance of speed and compression ratio.
    -   **Retention**: Define how many backups to keep. `Keep last 2` is a reasonable starting point, but you can adjust this based on your storage capacity and needs.
4.  **Enable "Repeat missed"**: This is a crucial option that ensures the backup job runs if the node was offline during its scheduled time.
5.  Click **Create**.

✅ Your backup job is now configured and will run automatically. You can monitor its status from the **Tasks** panel at the bottom of the Proxmox GUI.
