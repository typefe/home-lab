# ☁️ Nextcloud Installation and Configuration

This guide details the setup of a self-hosted Nextcloud instance within a Proxmox environment. It uses a dedicated PostgreSQL database, Nginx as the web server, and Cloudflare Tunnel for secure remote access.

## 📝 Table of Contents

- [Part 1: Lab Environment Assumptions](#part-1-lab-environment-assumptions)
- [Part 2: PostgreSQL Database Setup](#part-2-postgresql-database-setup)
- [Part 3: Nextcloud Installation](#part-3-nextcloud-installation)
- [Part 4: Nginx Configuration](#part-4-nginx-configuration)
- [Part 5: Initial Nextcloud Setup](#part-5-initial-nextcloud-setup)
- [Part 6: Nextcloud Configuration (`config.php`)](#part-6-nextcloud-configuration-configphp)
- [Part 7: Secure Remote Access with Cloudflare](#part-7-secure-remote-access-with-cloudflare)
- [Part 8: Performance Tuning and Maintenance](#part-8-performance-tuning-and-maintenance)

---

## Part 1: Lab Environment Assumptions

- **Virtualization:** Proxmox VE is running, and you can create LXC containers or VMs.
- **Operating System:** Debian 12 is the OS for both Nextcloud and PostgreSQL containers.
- **Networking:**
  - PostgreSQL LXC IP: `192.168.20.20`
  - Nextcloud LXC IP: `192.168.20.55`
- **Remote Access:** Cloudflared (Cloudflare Tunnel) is used for external access.

---

## Part 2: PostgreSQL Database Setup

We will use a separate LXC container for the PostgreSQL database for better resource isolation.

#### 1. Install PostgreSQL

Log into your PostgreSQL LXC and run the following commands:

```bash
apt update
apt install postgresql postgresql-contrib -y
```

#### 2. Allow TCP/IP Connections

By default, PostgreSQL only listens on the local host. We need to allow it to accept connections from the Nextcloud container.

1.  Edit `/etc/postgresql/15/main/postgresql.conf`:

    ```ini
    # Listen on the local interface and the container's LAN IP
    listen_addresses = 'localhost,192.168.20.20'
    ```

2.  Restart PostgreSQL to apply the change:

    ```bash
    systemctl restart postgresql
    ```

#### 3. Create the Nextcloud Database and User

1.  Switch to the `postgres` user and open the PostgreSQL shell:

    ```bash
    su postgres
    psql
    ```

2.  Execute the following SQL commands to create the database and user for Nextcloud:

    ```sql
    -- Create a dedicated user for Nextcloud
    CREATE USER nextcloud_user WITH ENCRYPTED PASSWORD 'Your_Strong_Password';

    -- Create the database with UTF8 encoding
    CREATE DATABASE nextcloud TEMPLATE template0 ENCODING 'UTF8';

    -- Grant ownership of the database to the new user
    ALTER DATABASE nextcloud OWNER TO nextcloud_user;

    -- Grant all privileges on the database to the user
    GRANT ALL PRIVILEGES ON DATABASE nextcloud TO nextcloud_user;

    -- Exit the PostgreSQL shell
    \q
    ```

#### 4. Configure Client Authentication

Now, we need to tell PostgreSQL to accept connections from the Nextcloud container using the newly created credentials.

1.  Add the following line to `/etc/postgresql/15/main/pg_hba.conf`. This rule allows `nextcloud_user` to connect to the `nextcloud` database from the Nextcloud server's IP (`192.168.20.55`) using password authentication.

    ```conf
    # TYPE  DATABASE        USER            ADDRESS                 METHOD
    host    nextcloud       nextcloud_user  192.168.20.55/32        scram-sha-256
    ```

2.  Restart PostgreSQL again:

    ```bash
    systemctl restart postgresql
    ```

#### 5. Test the Connection

From the **Nextcloud LXC/VM**, test the connection to the database. You may need to install the PostgreSQL client first (`apt install postgresql-client`).

```bash
psql -h 192.168.20.20 -U nextcloud_user -d nextcloud
```

If the connection is successful, you will be prompted for the password and see the PostgreSQL shell prompt. If it fails, double-check `listen_addresses` in `postgresql.conf` and the rule in `pg_hba.conf`.

---

## Part 3: Nextcloud Installation

#### 1. Prepare the Nextcloud LXC

In your dedicated Nextcloud LXC/VM, install PHP, required extensions, and other necessary tools.

```bash
apt update && apt upgrade -y
apt install -y wget unzip curl \
    php8.2-fpm php8.2-cli php8.2-gd php8.2-mbstring \
    php8.2-pgsql php8.2-curl php8.2-xml php8.2-zip \
    php8.2-intl php8.2-bcmath php8.2-gmp php-redis
```

> **Note:** The PHP version (`8.2`) should match the version available in your distribution. Adjust if necessary.

#### 2. Download and Extract Nextcloud

1.  Navigate to the web root directory:

    ```bash
    cd /var/www
    ```

2.  Download the latest version of Nextcloud:

    ```bash
    wget https://download.nextcloud.com/server/releases/latest.zip
    ```

3.  Unzip the archive and set the correct permissions:

    ```bash
    unzip latest.zip
    chown -R www-data:www-data nextcloud
    ```

---

## Part 4: Nginx Configuration

#### 1. Install Nginx

```bash
apt install nginx -y
```

#### 2. Create Nginx Configuration File

Create a new Nginx site configuration file at `/etc/nginx/sites-available/nextcloud`.

<details>
<summary>Click to expand Nginx configuration</summary>

```nginx
# Version 2024-07-17

upstream php-handler {
    server unix:/run/php/php8.2-fpm.sock;
    server 127.0.0.1:9000 backup;
}

# Set the `immutable` cache control options only for assets with a cache busting `v` argument
map $arg_v $asset_immutable {
    "" "";
    default ", immutable";
}

server {
    listen 80;
    listen [::]:80;
    server_name 192.168.20.55 cloud.typefe.pro;

    # Prevent nginx HTTP Server Detection
    server_tokens off;

    # Path to the root of your installation
    root /var/www/nextcloud;

    # HSTS settings
    # WARNING: Only add the preload option once you read about
    # the consequences in https://hstspreload.org/. This option
    # will add the domain to a hardcoded list that is shipped
    # in all major browsers and getting removed from this list
    # could take several months.
    #add_header Strict-Transport-Security "max-age=15768000; includeSubDomains; preload" always;

    # set max upload size and increase upload timeout:
    client_max_body_size 512M;
    client_body_timeout 300s;
    fastcgi_buffers 64 4K;

    # Enable gzip but do not remove ETag headers
    gzip on;
    gzip_vary on;
    gzip_comp_level 4;
    gzip_min_length 256;
    gzip_proxied expired no-cache no-store private no_last_modified no_etag auth;
    gzip_types application/atom+xml text/javascript application/javascript application/json application/ld+json application/manifest+json application/rss+xml application/vnd.geo+json application/vnd.ms-fontobject application/wasm application/x-font-ttf application/x-web-app-manifest+json application/xhtml+xml application/xml font/opentype image/bmp image/svg+xml image/x-icon text/cache-manifest text/css text/plain text/vcard text/vnd.rim.location.xloc text/vtt text/x-component text/x-cross-domain-policy;

    # Pagespeed is not supported by Nextcloud, so if your server is built
    # with the `ngx_pagespeed` module, uncomment this line to disable it.
    #pagespeed off;

    # The settings allows you to optimize the HTTP2 bandwidth.
    # See https://blog.cloudflare.com/delivering-http-2-upload-speed-improvements/
    # for tuning hints
    client_body_buffer_size 512k;

    # HTTP response headers borrowed from Nextcloud `.htaccess`
    add_header Referrer-Policy                   "no-referrer"       always;
    add_header X-Content-Type-Options            "nosniff"           always;
    add_header X-Frame-Options                   "SAMEORIGIN"        always;
    add_header X-Permitted-Cross-Domain-Policies "none"              always;
    add_header X-Robots-Tag                      "noindex,nofollow"  always;
    add_header X-XSS-Protection                  "1; mode=block"     always;


    # Remove X-Powered-By, which is an information leak
    fastcgi_hide_header X-Powered-By;

    # Set .mjs and .wasm MIME types
    # Either include it in the default mime.types list
    # and include that list explicitly or add the file extension
    # only for Nextcloud like below:
    include mime.types;
    types {
        text/javascript mjs;
	application/wasm wasm;
    }

    # Specify how to handle directories -- specifying `/index.php$request_uri`
    # here as the fallback means that Nginx always exhibits the desired behaviour
    # when a client requests a path that corresponds to a directory that exists
    # on the server. In particular, if that directory contains an index.php file,
    # that file is correctly served; if it doesn't, then the request is passed to
    # the front-end controller. This consistent behaviour means that we don't need
    # to specify custom rules for certain paths (e.g. images and other assets,
    # `/updater`, `/ocs-provider`), and thus
    # `try_files $uri $uri/ /index.php$request_uri`
    # always provides the desired behaviour.
    index index.php index.html /index.php$request_uri;

    # Rule borrowed from `.htaccess` to handle Microsoft DAV clients
    location = / {
        if ( $http_user_agent ~ ^DavClnt ) {
            return 302 /remote.php/webdav/$is_args$args;
        }
    }

    location = /robots.txt {
        allow all;
        log_not_found off;
        access_log off;
    }
}
```

</details>

#### 3. Enable the Site and Restart Nginx

1.  Create a symbolic link to enable the site:

    ```bash
    ln -s /etc/nginx/sites-available/nextcloud /etc/nginx/sites-enabled/
    ```

2.  Test the Nginx configuration and reload the service:

    ```bash
    nginx -t && systemctl reload nginx
    ```

---

## Part 5: Initial Nextcloud Setup (Web Installer)

#### 1. Access Nextcloud via LAN

Open your browser and navigate to your Nextcloud server's IP address: `http://192.168.20.55/`. You should see the Nextcloud setup page.

#### 2. Configure Admin User and Database

1.  Create an administrator account by choosing a username and password.
2.  Expand the **Storage & database** section.
3.  Select **PostgreSQL** as the database type.
4.  Enter the database credentials you configured earlier:
    - **Database user:** `nextcloud_user`
    - **Database password:** `Your_Strong_Password`
    - **Database name:** `nextcloud`
    - **Database host:** `192.168.20.20:5432`

Click **Install** to complete the setup. Once finished, you will be redirected to the Nextcloud dashboard.

---

## Part 6: Nextcloud Configuration (`config.php`)

After the initial web-based setup, the core configuration is stored in `/var/www/nextcloud/config/config.php`. You need to add several parameters to ensure it works correctly with a reverse proxy like Cloudflare Tunnel.

Edit the file and add/modify the following settings:

<details>
<summary>Click to expand `config.php` example</summary>

```php
<?php
$CONFIG = array (
  'instanceid' => '',
  'passwordsalt' => '',
  'secret' => '',
  'trusted_domains' => 
  array (
    0 => 'localhost',
    1 => '192.168.20.55',       // LAN address of Nextcloud
    2 => 'cloud.typefe.pro',    // Your public domain
  ),
  'trusted_proxies' => [
    0 => '127.0.0.1',           // Loopback for Cloudflared
    1 => '192.168.20.55',       // Nextcloud's own IP
  ],
  'overwrite.cli.url' => 'https://cloud.typefe.pro',
  'overwritehost' => 'cloud.typefe.pro',
  'overwriteprotocol' => 'https',
  'overwritecondaddr' => '^(192\.168\.|127\.0\.0\.1)',
  'memcache.local' => '\\OC\\Memcache\\Redis',
  'memcache.locking' => '\\OC\\Memcache\\Redis',
  'redis' => [
    'host' => 'localhost',
    'port' => 6379,
  ],
  'htaccess.RewriteBase' => '/', 
  'datadirectory' => '/mnt/DATA/nextcloud',
  'dbtype' => 'pgsql',
  'version' => '31.0.9.1',
  'dbname' => 'nextcloud',
  'dbhost' => '192.168.20.20',
  'dbport' => '',
  'dbtableprefix' => 'oc_',
  'dbuser' => '',
  'dbpassword' => '',
  'installed' => true,
);
```

</details>

- **`trusted_domains`**: A list of all domains and IPs used to access Nextcloud.
- **`trusted_proxies`**: Tells Nextcloud to trust headers from Cloudflared.
- **`overwritehost`**: Ensures Nextcloud generates URLs with your public domain.
- **`overwriteprotocol`**: Enforces `https` in generated URLs.

---

## Part 7: Secure Remote Access with Cloudflare

This setup uses Cloudflare Zero Trust and the WARP client to provide secure, seamless access to Nextcloud from mobile and desktop apps without exposing any ports on your firewall.

### 1. Using a One-Time PIN (for Browser Access)

You can configure a Cloudflare Access policy that requires a one-time PIN sent to your email for browser-based access. This is a great fallback method.

### 2. Using the WARP Client (for Mobile & Desktop Apps)

Mobile apps often don't support web-based login prompts from services like Cloudflare Access. The WARP client solves this by creating a secure tunnel directly from your device to Cloudflare's network.

#### Step 1: Enable WARP Checks in Zero Trust

1.  **Enable the Network Proxy:**
    - In your Zero Trust dashboard, go to **Settings > Network**.
    - Ensure that **Proxy** is enabled.

2.  **Activate the WARP Client Check:**
    - Go to **Settings > WARP Client**.
    - Under **WARP client checks**, click **Add new**.
    - Select "WARP" and give it a name like `WARP Check`.

3.  **Set Up Device Enrollment Rules:**
    - Go to **Settings > WARP Client** and click **Manage** under **Device enrollment**.
    - Add a rule to allow your email address to enroll devices.

#### Step 2: Set up the WARP Client on Your Device

1.  **Install the App:** Download the "**1.1.1.1: Faster Internet**" app.
2.  **Log in to Zero Trust:**
    - In the app, go to **Settings > Account > Login with Cloudflare for Teams**.
    - Enter your organization's team name (found under **Settings > General** in the Zero Trust dashboard).
    - Complete the one-time login with your email.

#### Step 3: Create the Access Policy

1.  In Zero Trust, go to **Access > Applications** and edit the policy for your Nextcloud application.
2.  **Create a "Bypass for WARP" Policy:**
    - Click **Add a policy**.
    - Set the **Action** to **Bypass**.
    - Give it a name like `Allow Enrolled WARP Devices`.
    - Create an **Include** rule with the **Selector** set to `WARP`.
3.  **Order Your Policies:** Drag this new Bypass policy to the **top of the list (Order #1)**. This ensures it's evaluated first.
4.  **(Optional) Create a Fallback:** Keep your second policy (Order #2) as an "Allow" rule that requires an Email OTP. This will be your fallback for browser access.

#### Step 4: Verification

1.  With WARP connected on your phone, open a browser and go to `https://cloudflare.com/cdn-cgi/trace`. You should see `warp=on`.
2.  Open the Nextcloud app. It should connect instantly without a login prompt.
3.  On a computer without WARP, try to access your Nextcloud domain. You should see the Cloudflare email login page.

---

## Part 8: Performance Tuning and Maintenance

After installation, you may see several warnings in the **Administration > Overview** page. Here’s how to fix them.

### 1. Essential Command-Line Maintenance with `occ`

Nextcloud's `occ` tool is crucial for maintenance. When running commands as `root` in an LXC container, you must execute them as the web server user (`www-data`).

The correct syntax is:
`su -s /bin/bash www-data -c 'php occ <command>'`

> **Note:** The `-s /bin/bash` specifies the shell, `www-data` is the user, and `-c` passes the command to execute.

### 2. Fixing Missing Database Indices

**Warning:** `Detected some missing optional indices...`

This warning means Nextcloud can optimize database performance by adding indices to certain tables.

**Solution:**

```bash
# Navigate to the Nextcloud directory
cd /var/www/nextcloud

# Enable maintenance mode
su -s /bin/bash www-data -c 'php occ maintenance:mode --on'

# Add the missing indices
su -s /bin/bash www-data -c 'php occ db:add-missing-indices'

# Disable maintenance mode
su -s /bin/bash www-data -c 'php occ maintenance:mode --off'
```

### 3. Fixing Mimetype Migrations

**Warning:** `One or more mimetype migrations are available... Use the command occ maintenance:repair --include-expensive...`

**Solution:**

```bash
cd /var/www/nextcloud

# Enable maintenance mode
su -s /bin/bash www-data -c 'php occ maintenance:mode --on'

# Run the expensive repair job (this can take a long time)
su -s /bin/bash www-data -c 'php occ maintenance:repair --include-expensive'

# Disable maintenance mode
su -s /bin/bash www-data -c 'php occ maintenance:mode --off'
```

### 4. Fixing the PHP Memory Limit

**Warning:** `The PHP memory limit is below the recommended value of 512 MB.`

**Solution:**

1.  Find your `php.ini` files. You can locate them with `php --ini`. You need to edit both the FPM and CLI versions.
    - **Web Server (FPM):** `/etc/php/8.2/fpm/php.ini`
    - **Command-Line (CLI):** `/etc/php/8.2/cli/php.ini`

2.  In each file, find the `memory_limit` line and change its value to `512M`.

    ```ini
    ; From
    memory_limit = 128M
    ; To
    memory_limit = 512M
    ```

3.  Restart the PHP-FPM service to apply the change:

    ```bash
    systemctl restart php8.2-fpm
    ```

### 5. Fixing the PHP Environment Variable Warning

**Warning:** `PHP does not seem to be setup properly to query system environment variables.`

This means your PHP-FPM service is not passing the `PATH` variable to Nextcloud.

**Solution:**

1.  Edit your PHP-FPM pool configuration file, typically at `/etc/php/8.2/fpm/pool.d/www.conf`.

2.  Add the following line near the other `env[...]` directives:

    ```ini
    env[PATH] = /usr/local/bin:/usr/bin:/bin
    ```

3.  Restart the PHP-FPM service:

    ```bash
    systemctl restart php8.2-fpm
    ```

### 6. Performance Tuning with Caching (Redis & OPcache)

Caching is the most effective way to improve Nextcloud's performance.

#### Step 1: Set Up Redis for Memory Caching

1.  Install Redis and the PHP extension (already included in the installation command in Part 3).

    ```bash
    apt install redis-server -y
    ```

2.  Configure Nextcloud to use Redis by adding the following to `/var/www/nextcloud/config/config.php`:

    ```php
    'memcache.local' => '\\OC\\Memcache\\Redis',
    'memcache.locking' => '\\OC\\Memcache\\Redis',
    'redis' => [
      'host' => 'localhost',
      'port' => 6379,
    ],
    ```

#### Step 2: Optimize PHP OPcache

**Warning:** `The PHP OPcache module is not properly configured...`

1.  Edit the OPcache configuration file, typically at `/etc/php/8.2/fpm/conf.d/10-opcache.ini`.

2.  Add the officially recommended settings:

    ```ini
    opcache.enable=1
    opcache.enable_cli=1
    opcache.interned_strings_buffer=16
    opcache.max_accelerated_files=10000
    opcache.memory_consumption=128
    opcache.save_comments=1
    opcache.revalidate_freq=1
    ```

3.  Restart the PHP-FPM service:

    ```bash
    systemctl restart php8.2-fpm
    ```
