# Snipe-IT SAML SSO Setup Guide

## Overview

MBFD Hub acts as a **SAML Identity Provider (IdP)** so that users logged into the Hub can seamlessly access Snipe-IT at `https://inventory.mbfdhub.com/` without re-entering credentials.

**Method chosen**: SAML 2.0 (Snipe-IT has native SAML SP support; MBFD Hub uses `codegreencreative/laravel-samlidp` as the IdP).

### Why SAML?
- Snipe-IT **natively supports SAML** — no code changes to Snipe-IT required.
- SAML is the industry standard for enterprise SSO.
- Other methods (OAuth, shared sessions) are not supported by Snipe-IT out of the box.

---

## Step 1: Install the SAML IdP Package on MBFD Hub

```bash
# On VPS, inside the Laravel container:
docker compose exec laravel.test composer require codegreencreative/laravel-samlidp

# Publish config (already created at config/samlidp.php):
docker compose exec laravel.test php artisan vendor:publish --tag=samlidp_config

# Generate self-signed certificate:
docker compose exec laravel.test php artisan samlidp:cert
```

This creates `storage/samlidp/cert.pem` and `storage/samlidp/key.pem`.

## Step 2: Add SAML IdP Service Provider to Laravel

Add to `config/app.php` providers array (if not auto-discovered):
```php
CodeGreenCreative\SamlIdp\SamlIdpServiceProvider::class,
```

## Step 3: Get MBFD Hub IdP Metadata

After installation, the IdP metadata is available at:
```
https://www.mbfdhub.com/saml/metadata
```

Download or copy this XML — you'll paste it into Snipe-IT.

## Step 4: Configure Snipe-IT as SAML SP

1. Log into Snipe-IT admin at `https://inventory.mbfdhub.com/`
2. Go to **Admin → Settings → SAML**
3. Enable SAML
4. Set the following:

| Setting | Value |
|---|---|
| **SAML Enabled** | Yes |
| **SAML IdP Metadata** | Paste the XML from `https://www.mbfdhub.com/saml/metadata` |
| **Attribute Mapping - Username** | `email` |
| **SAML Force Login** | No (allows both SAML and local login) |
| **SAML SLO** | Yes (enables single logout) |

5. Save settings.

## Step 5: Add Environment Variables

Add to `.env` on VPS:
```env
# SAML IdP Configuration
SAML_IDP_ISSUER=https://www.mbfdhub.com
SNIPEIT_SAML_ENTITY_ID=https://inventory.mbfdhub.com
SNIPEIT_SAML_ACS_URL=https://inventory.mbfdhub.com/saml/acs
SNIPEIT_SAML_SLS_URL=https://inventory.mbfdhub.com/saml/sls
```

## Step 6: Test SSO Flow

1. Log into MBFD Hub at `https://www.mbfdhub.com/admin/login`
2. Click the "Snipe-IT Inventory" link in the sidebar
3. You should be automatically logged into Snipe-IT without entering credentials
4. If prompted, the SAML flow will redirect to MBFD Hub login, authenticate, then redirect back to Snipe-IT

## Troubleshooting

- **"Invalid SAML Response"**: Check that the certificate in `storage/samlidp/` matches what Snipe-IT has in its IdP metadata.
- **User not found in Snipe-IT**: Snipe-IT must have a user with the same email address. Enable "SAML JIT Provisioning" in Snipe-IT settings to auto-create users.
- **Clock skew errors**: Ensure both containers have synchronized time (Docker usually handles this).

## Users with Access

The following users should have matching accounts in both MBFD Hub (admin role) and Snipe-IT:

| Email | MBFD Hub Role |
|---|---|
| MiguelAnchia@miamibeachfl.gov | admin |
| RichardQuintela@miamibeachfl.gov | admin |
| PeterDarley@miamibeachfl.gov | super_admin |
| GreciaTrabanino@miamibeachfl.gov | admin |
| geralddeyoung@miamibeachfl.gov | admin |

Run `php artisan mbfd:ensure-admin-roles` to verify/assign roles.
