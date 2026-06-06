# Network and Starlink Review

## Verified

- GMKtec uses Cloudflare Tunnel and Tailscale services.
- UFW is active with default deny incoming.
- SSH is hardened and public-key-only.
- Most service ports are loopback-only.
- No direct public DB/Redis bind observed from host metadata.

## Unknown

- Starlink router vs third-party router role.
- Wi-Fi encryption/admin account posture.
- UPnP/port forwards on any third-party router.
- Network segmentation between displays, admin workstations, IoT, and server.
- Whether display devices can reach admin/service ports over LAN.

## Recommendations

1. Confirm Cloudflare Tunnel/Tailscale are the only intended inbound paths.
2. Disable UPnP and remove any port forwards unless explicitly needed.
3. Segment displays/kiosks from admin and server management networks where practical.
4. Restrict LAN admin tools to admin VLAN/Tailscale.
5. Keep Starlink account/app access protected with MFA and owner-controlled devices.
6. Document emergency offline access if Cloudflare/Tailscale are down.
