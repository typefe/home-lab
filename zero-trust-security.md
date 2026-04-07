## 🛡️ Zero Trust Security Implementation
Cloudflare Zero Trust adds an authentication layer to your services, ensuring only authorized users can access sensitive applications while keeping others publicly accessible.

### Understanding Zero Trust
Zero Trust security operates on the principle of "never trust, always verify." Instead of relying solely on network-level security, it adds application-level authentication for sensitive services.

**Benefits:**
- **Granular Access Control**: Different authentication requirements per service
- **Identity-Based Security**: Users authenticate before accessing applications
- **Audit Trail**: Track who accesses what and when
- **Multi-Factor Authentication**: Additional security layers available

### Configuring Zero Trust for Proxmox
#### Step 1: Access Zero Trust Dashboard

1. In your Cloudflare dashboard, navigate to **"Zero Trust"**
2. If this is your first time, you may need to set up a Zero Trust account

#### Step 2: Create an Application
1. Go to **Access** → **Applications**
2. Click **"Add an application"**
3. Select **"Self-hosted"** application type

#### Step 3: Configure Application Settings
**Application Configuration:**
- **Application name**: `Proxmox Admin` (or any descriptive name)
- **Subdomain**: `proxmox`
- **Domain**: Select your domain (e.g., `example.com`)
- **Session Duration**: How long users stay logged in (default: 24 hours)

#### Step 4: Create Access Policy
1. **Policy name**: `Admin Access` (descriptive name for this policy)
2. **Action**: Select **"Allow"**
3. **Configure rules**: Define who can access this application

**Common Rule Options:**
- **Email**: Specific email addresses that can access
- **Email domain**: Allow entire domains (e.g., @yourcompany.com)
- **Country**: Geographic restrictions
- **IP ranges**: Specific IP address ranges

**Example Configuration:**
```
Rule Type: Include
Selector: Emails
Value: your-email@example.com
```

#### Step 5: Complete Setup
1. Review your configuration
2. Click **"Add application"** to save

#### Step 6: Test Zero Trust
1. Try accessing `https://proxmox.example.com`
2. You should be redirected to a Cloudflare login page
3. Enter your email address
4. Check your email for a verification code or magic link
5. After verification, you'll be redirected to Proxmox

### Advanced Zero Trust Configuration
#### Multiple Authentication Methods
You can configure additional authentication methods:

1. **Google OAuth**: Allow Google account logins
2. **GitHub OAuth**: Use GitHub accounts for authentication
3. **SAML**: Integration with enterprise identity providers
4. **Multi-factor Authentication**: Require additional verification

#### Session Management
- **Session Duration**: Control how long users stay authenticated
- **Re-authentication**: Require periodic re-authentication for sensitive apps
- **Device Trust**: Remember trusted devices

#### Granular Policies
Create different policies for different user groups:
- **Administrators**: Full access to all services
- **Read-Only Users**: Limited access to monitoring dashboards
- **Contractors**: Time-limited access with additional restrictions

> **🔐 Important**: Zero Trust is now protecting your Proxmox access. Only users with authorized email addresses can access the web interface.

---

## 🚀 Secure Application Access with Cloudflare WARP

This guide explains how to set up Cloudflare's WARP client to provide secure, seamless access to your self-hosted services. By using **Device Authentication Identity**, you can allow trusted, enrolled devices to access applications without an OTP prompt, while still maintaining full identity-based security.

### How It Works

We use **Device Authentication Identity** to provide seamless access. When a user is logged into the WARP client on an enrolled device, Cloudflare shares the user's identity with the Access application. This allows the user to skip the browser-based OTP prompt as long as their WARP session is valid, maintaining full security without extra login steps.

**The Rule of Thumb:**
- **Logged into WARP + Device Authentication Identity enabled** → No OTP prompt (seamless access).
- **Not logged into WARP / Session expired** → Normal Access login page with OTP.
- **WARP connected but Device Authentication Identity disabled** → OTP prompt required every time.

### Step 1: Enable WARP Checks and Enrollment

1.  **Enable the Network Proxy:**
    - In your Zero Trust dashboard, go to **Settings > Network**.
    - Ensure that **Proxy** is enabled.

2.  **Activate the WARP Client Check:**
    - Go to **Settings > WARP Client**.
    - Under **WARP client checks**, click **Add new**.
    - Select "WARP" and give it a name like `WARP Check`.

3.  **Set Up Device Enrollment Rules:**
    - Go to **Settings > WARP Client** and click **Manage** under **Device enrollment**.
    - Add a rule to allow your email address (or your users' emails) to enroll devices.

### Step 2: Enable Device Authentication Identity (Global)

This setting allows Cloudflare to use the WARP client's login for application authentication.

1.  Go to **Settings > WARP Client**.
2.  Click **Manage** under **Device enrollment**.
3.  Go to the **Login methods** tab.
4.  Find **Device authentication identity** and click **Edit**.
5.  Enable the toggle for **Device authentication identity**.
6.  Set a **Session duration** (e.g., 24 hours or 7 days). This determines how long the "silent" authentication remains valid.

### Step 3: Configure the Access Application

You must enable the identity sharing for each specific application you want to access seamlessly.

1.  In Zero Trust, go to **Access > Applications** and edit your application.
2.  Go to the **Authentication** tab.
3.  **Disable Binding Cookie:** Ensure "Binding Cookie" is **Disabled**. Device authentication identity will NOT work if Binding Cookie is enabled.
4.  **Enable Device Authentication Identity:** Scroll down and ensure the **Device authentication identity** toggle is turned **ON**.
5.  Save the application.

### Step 4: Set up the WARP Client on Your Device

1.  **Install the App:** Download the "**1.1.1.1: Faster Internet**" app on your mobile or desktop device.
2.  **Log in to Zero Trust:**
    - In the app, go to **Settings > Account > Login with Cloudflare for Teams**.
    - Enter your organization's team name.
    - Complete the one-time login with your email.

### Step 5: Verification

1.  **With WARP connected:** Open a browser and go to your application URL. It should load immediately without an OTP prompt.
2.  **Check Identity:** Go to `https://<your-team-name>.cloudflareaccess.com/cdn-cgi/access/tokens` to see your active session and verify it's associated with your email.
3.  **Without WARP:** Disconnect WARP or try from a different device. You should see the standard Cloudflare login page requesting an OTP.
