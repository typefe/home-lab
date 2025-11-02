# 🔐 Securing Services with mTLS Client Certificates

This guide details how to secure applications and services using mutual TLS (mTLS), ensuring that only devices with a valid client certificate can access them.

## 📚 References

- [Enable mTLS](https://developers.cloudflare.com/ssl/client-certificates/enable-mtls/)
- [Create a Client Certificate](https://developers.cloudflare.com/ssl/client-certificates/create-a-client-certificate/)
- [Configure mTLS for API Shield](https://developers.cloudflare.com/api-shield/security/mtls/configure/)
- [Validate Client Certificates in WAF](https://developers.cloudflare.com/learning-paths/mtls/mtls-app-security/#3-validate-the-client-certificate-in-the-waf)
- [Configure for Mobile or IoT](https://developers.cloudflare.com/ssl/client-certificates/configure-your-mobile-app-or-iot-device/)

---

## 1️⃣ Step 1: Enable mTLS on the Zone

First, enforce mTLS across your domain. This requires every HTTPS connection to present a valid client certificate before Cloudflare forwards the traffic.

- **Path**: `Cloudflare Dashboard` → `SSL/TLS` → `Client Certificates`
- **Action**: Enable mTLS for your desired hostname (e.g., `*.example.com`).

---

## 2️⃣ Step 2: Create Client Certificates

A Client CA (Certificate Authority) acts as the trust anchor for your client certificates.

### Generate a Key and CSR

Generate an RSA 2048 client key and a Certificate Signing Request (CSR) locally on your device.

```bash
# Generate a private key
openssl genrsa -out my-laptop.key 2048

# Create a CSR
openssl req -new -key my-laptop.key -out my-laptop.csr -subj "/CN=my-laptop"
```

### Issue the Certificate

1.  **Path**: `Cloudflare Dashboard` → `SSL/TLS` → `Client Certificates` → `Create Certificate`.
2.  **Action**: Select `Use my private key and CSR`.
3.  **Upload**: Paste the contents of your `my-laptop.csr` file.
4.  **Receive**: Cloudflare will return the signed client certificate (e.g., `my-laptop.pem`).

---

## 3️⃣ Step 3: Import and Install the Certificate

To use the certificate, you must bundle it with the private key and import it into your device's trust store.

### For Browsers (macOS, Windows, Linux)

Create a `.p12` bundle and import it.

```bash
openssl pkcs12 -export \
  -in my-laptop.pem \
  -inkey my-laptop.key \
  -out my-laptop.p12 \
  -name "my-laptop" \
  -password pass:YOUR_PASSWORD
```

Import the generated `my-laptop.p12` file into your browser or operating system's keychain.

### For Android

1.  **Transfer**: Copy the `.p12` file to your phone's storage.
2.  **Install**:
    - Go to `Settings` → `Security` → `Encryption & credentials` → `Install from storage`.
    - Select the `.p12` file and enter its password.
    - When prompted, name the certificate and choose **VPN and apps** for credential use.

Chrome and other system apps will now automatically present the certificate when required.

---

## 4️⃣ Step 4: Add mTLS to the Access Policy Chain

Integrate mTLS into your Zero Trust policy to grant trusted devices immediate access.

- **Path**: `Cloudflare Zero Trust` → `Access` → `Applications` → `Your App` → `Policies`.
- **Action**: Add a new policy at the top of the chain with the following rules:
  - **Action**: `Service Auth`
  - **Selector**: `Valid Certificate` (optionally filter by Common Name, e.g., `my-laptop`).
  - **Description**: Grants immediate access for valid mTLS certificates, bypassing OTP/IdP.

### Recommended Policy Order

Ensure the mTLS policy is at the top of the chain to prioritize certificate-based authentication.

| Order | Action       | Selector                            | Description                                                |
| :---- | :----------- | :---------------------------------- | :--------------------------------------------------------- |
| 🥇 #1  | Service Auth | Valid Certificate (CN: `my-laptop`) | Grants immediate access for valid mTLS cert; no OTP / IdP. |
| 🥈 #2  | Bypass       | WARP client                         | Allows enrolled WARP devices; no OTP.                      |
| 🥉 #3  | Allow        | Everyone                            | Fallback → triggers normal OTP / IdP login.                |


After completing these steps, visiting a protected site without the certificate will result in an error, while access with the certificate will succeed seamlessly.

---

## ⚠️ Important: Browser mTLS Workaround

You might encounter an issue where your browser redirects to an OTP login page without prompting for your mTLS certificate. This race condition occurs because the OTP redirect happens before the browser is challenged for its certificate.

To resolve this, you need to "train" your browser once by following these steps:

1.  **Isolate the mTLS Policy**: In your Cloudflare Access application, temporarily disable all policies except for your mTLS `Service Auth` rule.
2.  **Trigger the Certificate Prompt**: Visit your website. Your browser should now correctly prompt you to select the client certificate.
3.  **Remember the Certificate**: Select your certificate and ensure you check the box to **"Always allow"** or **"Remember this decision"** for the site.
4.  **Restore Policies**: Once the site loads successfully, you can re-enable your other Access policies (e.g., OTP, WARP).

Your browser will now remember to send the certificate on the first request, ensuring it passes the mTLS check before other rules are evaluated.

**Note on Application Support**: This workaround is specific to web browsers. Desktop applications (like Jellyfin or the NextCloud sync client) will still fail unless they have a built-in setting to configure and send a client certificate.
