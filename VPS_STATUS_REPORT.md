# VPS Forensic Analysis Report
**Date:** 2026-02-03  
**VPS IP:** 145.223.73.170  
**Analysis Duration:** ~45 minutes  
**System:** Ubuntu 6.8.0-87-generic (6 days uptime at time of analysis)

---

## Executive Summary

✅ **

 CLEAN: No active cryptominer detected**
⚠️ **CRITICAL: PostgreSQL database publicly exposed with known credentials**
⚠️ **HIGH: Fail2ban SSH protection not running**
⚠️ **MEDIUM: Docker bypassing UFW firewall rules**

The VPS shows no evidence of active cryptocurrency mining malware. However, a **critical security vulnerability** exists where the PostgreSQL database is accessible from the internet on port 5432, combined with the fact that database credentials were exposed in chat logs. This creates an immediate risk for data exfiltration or ransomware attacks.

---

## System Health Status

###  CPU & Memory
- **CPU Usage:** 0.0% user, 2.3% system, **97.7% idle** ✅
- **Load Average:** 0.13, 0.05, 0.01 (on 16-core system) ✅
- **Memory:** 15992 MiB total, 5137 MiB free, 1294 MiB used ✅
- **Processes:** 168 total (1 running, 167 sleeping, **0 zombie**) ✅
- **Uptime:** 76 days, 21:47

**Assessment:** System resources are healthy. CPU is mostly idle, indicating no cryptomining activity.

### Malware Indicators: NEGATIVE ✅
Checked for common cryptominer signatures:
```bash
ps aux | grep -E "(python|perl|bash -c|kworker|kinsing|xmrig)"
```
**Result:** Only legitimate processes found:
- `python3` for unattended-upgrades (system package manager)
- `python3` for supervisord (process monitor)
- `kworker` kernel threads (normal Linux kernel workers)
- No suspicious `bash -c`, `kinsing`, `xmrig`, or unauthorized mining processes

### Cron Jobs: CLEAN ✅
**Checked:**
- `/etc/crontab` - Only standard system jobs
- `/etc/cron.d/` - certbot (Let's Encrypt), e2scrub_all, sysstat
- `/etc/cron.hourly/` - Only `.placeholder` file
- No malicious cron entries found

### Temporary Directories: CLEAN ✅
**`/tmp/`:**
- Contains Laravel application temp files (migrations, controllers, resources)
- No suspicious binaries or scripts

**`/var/tmp/`:**
- Only systemd private directories
- No malicious content

### System Binary Integrity: VERIFIED ✅
```bash
debsums 2>&1 | grep -v 'OK$'
```
**Result:** **No failed checksums** - all system binaries are intact and unmodified.

### Disk Usage: HEALTHY ✅
- **Root Partition:** 64G / 193G used (33%) ✅
- **Boot:** 115M / 881M used (14%) ✅
- **EFI:** 6.2M / 105M used (6%) ✅

No evidence of disk space abuse from cryptomining.

### Recent System File Modifications: CLEAN ✅
```bash
find /etc -type f -mtime -3
```
**Result:** Only `/etc/ld.so.cache` modified (normal system cache updates)

---

## Network & Security Analysis

### Open Ports
**Public Internet-Facing:**
| Port | Service | Status | Security Risk |
|------|---------|--------|---------------|
| 22 | SSH | ✅ Open | Acceptable (no fail2ban ⚠️) |
| 80 | HTTP (nginx) | ✅ Open | Acceptable |
| 443 | HTTPS (nginx) | ✅ Open | Acceptable |
| **5432** | **PostgreSQL** | 🚨 **EXPOSED** | **CRITICAL** |
| 8080 | App (Docker) | ✅ Open | Acceptable |
| 8081 | Nextcloud Talk | ⚠️ Open | Review need |
| 3478 | TURN server | ⚠️ Open | Review need |
| 8443 | ? | ⚠️ Open | Review need |
| 19132-19137 | Minecraft Bedrock | ⚠️ Open | Review need |

### 🚨 CRITICAL VULNERABILITY: PostgreSQL Exposed

**Evidence:**
```bash
# Port publicly bound
ss -tlnp | grep 5432
0.0.0.0:5432    users:(("docker-proxy",pid=3485426,fd=7))
[::]:5432       users:(("docker-proxy",pid=3485434,fd=7))

# PostgreSQL listening on all interfaces
docker exec mbfd-hub-pgsql-1 psql -U postgres -c "SHOW listen_addresses;"
listen_addresses = *

# Docker port mapping
docker port mbfd-hub-pgsql-1
5432/tcp -> 0.0.0.0:5432
5432/tcp -> [::]:5432
```

**Risk Assessment:**
- **Severity:** CRITICAL
- **Exploitability:** IMMEDIATE (credentials exposed in chat logs)
- **Impact:** Full database access, data exfiltration, ransomware, data modification
- **Recommendation:** **CLOSE PORT 5432 IMMEDIATELY**

### Firewall Status

**UFW (Uncomplicated Firewall):**
- **Status:** Active
- **Default Policy:** INPUT=DROP, FORWARD=DROP, OUTPUT=ACCEPT
- **Rules:** 12 allow rules (22, 80, 443, 3478, 8443, 19132-19137)

**Problem:** Docker bypasses UFW by manipulating iptables directly. UFW does not control Docker-published ports, which is why PostgreSQL 5432 is accessible despite not being in UFW rules.

**Current iptables chains:**
- `DOCKER` chain accepts traffic to 172.24.0.2:5432 (PostgreSQL container)
- `DOCKER-USER` chain is empty (no custom Docker firewall rules)

### Fail2ban Status: NOT RUNNING ⚠️
```bash
fail2ban-client status
Fail2ban not running
```

**Risk:** SSH port 22 is publicly accessible with no brute-force protection. Combined with exposed credentials, this increases attack surface for automated SSH attacks.

---

## Application Status

### Docker Containers

**Running Containers:**
| Container | Status | Ports | Health |
|-----------|--------|-------|--------|
| mbfd-hub-laravel.test-1 | Up 4 days | 8080→80, 127.0.0.1:5173→5173 | ✅ Healthy |
| mbfd-hub-pgsql-1 | Up 6 days (healthy) | **0.0.0.0:5432→5432** 🚨 | ✅ Healthy |
| nextcloud-aio-talk | Up 3 days (healthy) | 8081→8081 | ✅ Healthy |

**Stopped/Exited Containers:** 14 old containers (forms, eval, bedrock, crafty)

**Docker Images:**
- **Total:** 70 images
- **Active:** 14 images
- **Reclaimable Space:** 35.91GB (80% of 44.53GB total)

**Recommendation:** Clean up unused images and containers.

### Application Health

**HTTP Tests:**
```bash
# Port 8080 (App)
curl -I http://localhost:8080/
HTTP/1.1 200 OK ✅

curl -I http://localhost:8080/admin
HTTP/1.1 302 Found (→ /admin/login) ✅

curl -I http://localhost:8080/daily
HTTP/1.1 200 OK ✅
```

**Application Logs (Laravel):**
- Livewire polling active (`/livewire/update` every 30 seconds)
- No errors in recent logs
- App commit: `6c5eecab42ae38e4b22153951f0eca926aa094e7`

**PostgreSQL Logs:**
- Regular checkpoints every 5 minutes (healthy)
- No connection errors
- No authentication failures (yet - but credentials are exposed)

---

## Security Recommendations

### Priority 1: IMMEDIATE ACTION REQUIRED 🚨

1. **Close PostgreSQL Public Access**
   ```bash
   # Edit docker-compose.yml to remove port publishing:
   # Change:
   #   ports:
   #     - "0.0.0.0:5432:5432"
   # To:
   #   # No ports section (internal Docker network only)
   
   # Restart container
   docker-compose restart pgsql
   ```

2. **Enable Fail2ban**
   ```bash
   systemctl start fail2ban
   systemctl enable fail2ban
   ```

### Priority 2: Within 24 Hours ⚠️

3. **Review Unnecessary Public Ports**
   - Audit need for ports: 3478, 8081, 8443, 19132-19137
   - Remove UFW rules for unused services

4. **Add Explicit PostgreSQL Block (Defense in Depth)**
   ```bash
   ufw deny 5432/tcp
   ufw reload
   ```

5. **Configure Docker Firewall Rules**
   ```bash
   # Add to /etc/docker/daemon.json:
   {
     "iptables": true,
     "ip-forward": true
   }
   ```

### Priority 3: Next Maintenance Window 📅

6. **Rotate All Exposed Credentials**
   - DB_PASSWORD (PostgreSQL)
   - GitHub API tokens
   - Cloudflare API keys
   - Sentry DSN
   - VAPID keys
   - Laravel APP_KEY

7. **Docker Cleanup**
   ```bash
   docker system prune -a --volumes
   ```

8. **Enable SSH Key-Only Authentication (Optional)**
   ```bash
   # Edit /etc/ssh/sshd_config:
   PasswordAuthentication no
   ChallengeResponseAuthentication no
   ```

---

## Cryptominer Investigation: NEGATIVE ✅

### Indicators Checked:
1. ✅ **CPU Usage:** Normal (97.7% idle)
2. ✅ **Suspicious Processes:** None found
3. ✅ **Cron Jobs:** Clean
4. ✅ **Temp Directories:** No malicious binaries
5. ✅ **System Binary Integrity:** All checksums valid
6. ✅ **Recent File Modifications:** None suspicious
7. ✅ **Zombie Processes:** Zero
8. ✅ **Disk Space Abuse:** None

### Conclusion:
**NO EVIDENCE of active or dormant cryptominer malware.** The system appears clean from this threat vector.

---

## Incident Timeline (Hypothetical)

Based on findings, here's the likely security incident scenario:

1. **Credentials Exposed:** App credentials (DB_PASSWORD, API keys) leaked in chat logs
2. **PostgreSQL Exposed:** Docker container published port 5432 to 0.0.0.0
3. **Potential Unauthorized Access:** With exposed port + credentials, unauthorized database access is possible
4. **No Cryptominer Deployed:** Either:
   - Attack hasn't occurred yet, OR
   - Attackers accessed data but didn't install malware (data exfiltration), OR
   - Cryptominer was cleaned by system updates (unlikely given no evidence)

**Most Likely:** The vulnerability exists but hasn't been actively exploited for cryptomining. However, **data exfiltration or unauthorized database access may have occurred without leaving obvious traces**.

---

## Next Steps

1. ✅ **Forensic analysis:** Complete (this report)
2. ⏳ **Close PostgreSQL port:** AWAITING EXECUTION
3. ⏳ **Enable fail2ban:** AWAITING EXECUTION
4. ⏳ **Secrets rotation:** AWAITING PLANNING
5. ⏳ **Security hardening documentation:** AWAITING CREATION

---

## Additional Notes

- **SSH Connection Issues:** During end of analysis, SSH connections began resetting (`Connection reset by peer`). This could indicate:
  - Rate limiting from analysis activity
  - Network instability
  - **Possible active attack in progress** (recommendation: monitor SSH logs)
  
- **Monitoring Recommendation:** Set up real-time alerting for:
  - PostgreSQL connection attempts from non-localhost
  - SSH authentication failures
  - Unusual CPU usage spikes
  - Docker container restarts

---

**Report prepared by:** Kilo Code (Debug Mode)  
**Action Required:** User confirmation to proceed with Priority 1 security hardening
