# Stirling PDF LXC Installation Guide for Proxmox

This guide details how to set up [Stirling PDF](https://stirlingpdf.com/), a powerful self-hosted PDF manipulation tool, in a dedicated LXC container on Proxmox VE.

## Prerequisites

1.  **Proxmox VE Host**: A running Proxmox server.
2.  **Debian LXC Template**: Ensure the Debian 12 (Bookworm) template is downloaded.

---

## Part 1: Create the LXC Container

First, create the container that will host Stirling PDF.

1.  In the **Proxmox Web UI**, click **Create CT**.
2.  Fill out the container settings:
    -   **General**:
        -   **Hostname**: `stirling-pdf`
        -   **Password**: Set a secure root password.
    -   **Template**:
        -   **Storage**: `local`
        -   **Template**: `debian-12-standard_...`
    -   **Disks**:
        -   **Root Disk Size**: 16 GB (to accommodate Java, LibreOffice, and other dependencies).
    -   **CPU**:
        -   **Cores**: 2 (or more, depending on expected load).
    -   **Memory**:
        -   **Memory**: 2048 MB (2 GB is recommended due to Java and LibreOffice).
    -   **Network**:
        -   **Name**: `eth0`
        -   **Bridge**: `vmbr1`
        -   **IPv4/CIDR**: Set a static IP
        -   **Gateway (IPv4)**: Your network's gateway.
    -   **DNS**:
        -   **DNS Servers**: Your DNS resolver.
3.  Confirm and click **Finish**.

---

## Part 2: Install Dependencies

Start the newly created container and open its console to begin the installation process.

### Step 1: Install System Dependencies

First, update your package lists and install the essential packages required for building and running Stirling PDF.

```bash
# In the LXC shell
# Update package lists and install required packages
apt-get update
apt-get install -y git automake autoconf libtool \
    libleptonica-dev pkg-config zlib1g-dev make g++ \
    openjdk-17-jdk python3 python3-pip
```

> [!NOTE]
> The official Stirling PDF guide recommends `openjdk-21-jdk`. However, on Debian 12, `openjdk-17-jdk` is the recommended and fully supported LTS version.

### Step 2: Install jbig2enc (for OCR)

`jbig2enc` is an encoder used for optimizing PDF file sizes, especially in scanned documents.

```bash
# In the LXC shell
# Create a directory for git clones
mkdir -p ~/.git && cd ~/.git

# Clone and build jbig2enc
git clone https://github.com/agl/jbig2enc.git
cd jbig2enc
./autogen.sh
./configure
make && make install
```

### Step 3: Install Document Conversion Tools

Install LibreOffice for document conversion and Tesseract for OCR language support.

```bash
# In the LXC shell
# Install LibreOffice and Tesseract OCR
apt-get install -y libreoffice-writer libreoffice-calc libreoffice-impress \
    tesseract-ocr tesseract-ocr-eng tesseract-ocr-tur
```

### Step 4: Install Python Libraries

These Python libraries are dependencies for `unoserver` (which interfaces with LibreOffice) and other document processing tasks.

```bash
# In the LXC shell
# Install required Python packages
pip3 install uno opencv-python-headless unoserver pngquant WeasyPrint --break-system-packages
```

---

## Part 3: Install Stirling PDF

Now, we will download the Stirling PDF application and its necessary scripts.

> [!TIP]
> Stirling PDF is available in two versions:
> - **Normal**: `https://files.stirlingpdf.com/Stirling-PDF.jar`
> - **With Login**: `https://files.stirlingpdf.com/Stirling-PDF-with-login.jar` (for basic security)
>
> This guide uses the normal version.

```bash
# In the LXC shell
# Create installation directory
mkdir -p /opt/stirling-pdf

# Download the JAR file
wget -O /opt/stirling-pdf/Stirling-PDF.jar https://files.stirlingpdf.com/Stirling-PDF.jar

# Clone the official repository to get helper scripts
cd /tmp
git clone https://github.com/Stirling-Tools/Stirling-PDF.git
mv Stirling-PDF/scripts/ /opt/stirling-pdf/
```

---

## Part 4: Create a Dedicated User

For security, it's best to run Stirling PDF under a dedicated, non-root user.

```bash
# In the LXC shell
# Create the 'stirling' user
useradd -m -s /bin/bash stirling

# Set ownership of the installation directory
chown -R stirling:stirling /opt/stirling-pdf
```

---

## Part 5: Set Up Systemd Services

We will create two `systemd` services: one for `unoserver` (to handle document conversion) and one for Stirling PDF itself. This ensures they run automatically on boot.

### Step 1: Create `unoserver` Service

This service allows Stirling PDF to use LibreOffice for converting documents.

- Create the service file inside the container:
  ```bash
  # In the LXC shell
  nano /etc/systemd/system/unoserver.service
  ```

- Copy and paste the following configuration. You can also find this file in the repository at `../configs/stirling-pdf/unoserver.service`.
  ```toml
  [Unit]
  Description=UnoServer Service
  After=network.target

  [Service]
  User=stirling
  Group=stirling
  Environment="JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64"
  ExecStart=/usr/local/bin/unoserver --interface 127.0.0.1 --port 2003
  Restart=always
  RestartSec=5

  [Install]
  WantedBy=multi-user.target
  ```

### Step 2: Create `stirling-pdf` Service

This service runs the main Stirling PDF application.

- Create the service file inside the container:
  ```bash
  # In the LXC shell
  nano /etc/systemd/system/stirling-pdf.service
  ```

- Copy and paste the following configuration. You can also find this file in the repository at `../configs/stirling-pdf/stirling-pdf.service`.
  ```toml
  [Unit]
  Description=Stirling PDF Service
  After=network.target unoserver.service
  Requires=unoserver.service

  [Service]
  Type=simple
  User=stirling
  Group=stirling
  WorkingDirectory=/opt/stirling-pdf
  ExecStart=/usr/bin/java -jar /opt/stirling-pdf/Stirling-PDF.jar
  Environment="JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64"
  SuccessExitStatus=143
  Restart=always
  RestartSec=5

  [Install]
  WantedBy=multi-user.target
  ```

---

## Part 6: Enable and Start Services

Finally, enable the services to start on boot and start them now.

```bash
# In the LXC shell
# Reload the systemd daemon to recognize the new services
systemctl daemon-reload

# Enable and start the services
systemctl enable --now unoserver.service
systemctl enable --now stirling-pdf.service

# Check their status
systemctl status unoserver.service
systemctl status stirling-pdf.service
```

Your Stirling PDF instance should now be running and accessible on port `8080` of your container's IP address.