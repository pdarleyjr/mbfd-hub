# Security Hardening Implementation Plan
**Date:** 2026-02-03  
**VPS IP:** 145.223.73.170  
**Status:** ⏳ PENDING EXECUTION (SSH connection interrupted)

---

## Critical Security Issues Identified

1. **PostgreSQL Port 5432 Publicly Exposed** (CRITICAL)
   - Accessible from 0.0.0.0:5432 and [::]:5432
   - Combined with exposed credentials = immediate compromise risk
   
2. **Fail2ban Not Running** (HIGH)
   - No SSH brute-force protection
   
3. **Docker Bypassing UFW** (MEDIUM)
   - Container ports ignore firewall rules

---

## Changes Applied

### ⏳ Priority 1: Close PostgreSQL Public Access (PENDING)

**STATUS:** NOT YET APPLIED - SSH connection reset during implementation

**What needs to be changed:**
The `mbfd-hub-pgsql-1` container is currently exposing port 5432 to the public internet via Docker port mapping. This needs to be removed so PostgreSQL is only accessible via Docker's internal network.

**Current Configuration:**
```bash
# Current published ports
docker port mbfd-hub-pgsql-1
5432/tcp -> 0.0.0.0:5432
5432/tcp -> [::]:5432
```

**Target Configuration:**
```bash
# After fix - no published ports
docker port mbfd-hub-pgsql-1
# (empty output - internal network only)
```

#### Implementation Steps:

1. **Find docker-compose.yml location:**
   ```bash
   find /root -name 'docker-compose.yml' -type f 2>/dev/null
   # OR
   cd ~ && ls -la docker-compose.yml
   # OR check common locations:
   # /root/mbfd-hub/docker-compose.yml
   # /root/Support-Services/docker-compose.yml
   ```

2. **Backup current docker-compose.yml:**
   ```bash
   cp docker-compose.yml docker-compose.yml.backup.$(date +%Y%m%d_%H%M%S)
   ```

3. **Edit docker-compose.yml:**
   Find the `pgsql` or `postgres` service definition and remove/comment out the ports section:
   
   **BEFORE:**
   ```yaml
   services:
     pgsql:
       image: postgres:18-alpine
       ports:
         - "0.0.0.0:5432:5432"  # REMOVE THIS
         - "${FORWARD_DB_PORT:-5432}:5432"  # OR THIS
       environment:
         POSTGRES_PASSWORD: ${DB_PASSWORD}
   ```
   
   **AFTER:**
   ```yaml
   services:
     pgsql:
       image: postgres:18-alpine
       # ports:  # COMMENTED OUT - internal network only
       #   - "0.0.0.0:5432:5432"
       environment:
         POSTGRES_PASSWORD: ${DB_PASSWORD}
       networks:
         - mbfd-network  # Ensure internal network is specified
   ```

4. **Restart the PostgreSQL container:**
   ```bash
   cd $(dirname $(find /root -name 'docker-compose.yml' | head -1))
   docker-compose restart pgsql
   
   # OR if auto-detection fails:
   docker restart mbfd-hub-pgsql-1
   ```

5. **Verify port is no longer exposed:**
   ```bash
   # Should return empty (no published ports)
   docker port mbfd-hub-pgsql-1
   
   # Should NOT show 5432 listening on 0.0.0.0
   ss -tlnp | grep 5432
   
   # Should NOT be accessible from public
   # (Testfrom another machine)
   telnet 145.223.73.170 5432  # Should fail/timeout
   ```

6. **Verify app can still connect (internal network):**
   ```bash
   docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute="echo DB::connection()->getPdo() ? 'Connected' : 'Failed';"
   # Should output: Connected
   
   # Check app health
   curl -I http://localhost:8080/
   # Should return: HTTP/1.1 200 OK
   ```

#### Rollback Plan:
If the app cannot connect after removing port mapping:

1. **Check Docker network configuration:**
   ```bash
   docker network inspect mbfd-hub_default
   # Both containers should be on same network
   ```

2. **If network issue, restore backup:**
   ```bash
   cp docker-compose.yml.backup.<timestamp> docker-compose.yml
   docker-compose restart pgsql
   ```

3. **Verify .env has correct DB_HOST:**
   ```bash
   grep DB_HOST .env
   # Should be: DB_HOST=pgsql  (or container name, NOT localhost)
   ```

---

### ⏳ Priority 2: Enable Fail2ban (PENDING)

**STATUS:** NOT YET APPLIED

**What this changes:**
Enables `fail2ban` service to monitor SSH authentication logs and ban IPs after repeated failed login attempts.

#### Implementation Steps:

1. **Check if fail2ban is installed:**
   ```bash
   dpkg -l | grep fail2ban
   # OR
   systemctl status fail2ban
   ```

2. **Install if not present:**
   ```bash
   apt-get update
   apt-get install -y fail2ban
   ```

3. **Create/update fail2ban SSH jail configuration:**
   ```bash
   cat > /etc/fail2ban/jail.local <<'EOF'
   [DEFAULT]
   # Ban duration: 1 hour
   bantime = 3600
   
   # Find time window: 10 minutes
   findtime = 600
   
   # Max retries before ban
   maxretry = 5
   
   # Email alerts (optional)
   destemail = admin@yourdomain.com
   sender = fail2ban@yourdomain.com
   action = %(action_mwl)s
   
   [sshd]
   enabled = true
   port = 22
   filter = sshd
   logpath = /var/log/auth.log
   maxretry = 5
   bantime = 3600
   EOF
   ```

4. **Start and enable fail2ban:**
   ```bash
   systemctl start fail2ban
   systemctl enable fail2ban
   systemctl status fail2ban
   ```

5. **Verify SSH jail is active:**
   ```bash
   fail2ban-client status
   fail2ban-client status sshd
   ```

#### Verification Commands:
```bash
# Check fail2ban is running
systemctl is-active fail2ban
# Output: active

# View current bans
fail2ban-client status sshd
# Shows: Currently banned IPs

# View ban log
tail -f /var/log/fail2ban.log
```

#### Rollback Plan:
```bash
systemctl stop fail2ban
systemctl disable fail2ban
```

---

### ⏳ Priority 3: Add Explicit PostgreSQL Firewall Block (PENDING)

**STATUS:** NOT YET APPLIED

**What this changes:**
Adds an explicit UFW rule to drop any traffic to port 5432, even if Docker tries to publish it (defense in depth).

#### Implementation Steps:

1. **Add UFW deny rule for PostgreSQL:**
   ```bash
   ufw deny 5432/tcp
   ufw deny 5432/udp
   ```

2. **Reload UFW:**
   ```bash
   ufw reload
   ```

3. **Verify rule is active:**
   ```bash
   ufw status numbered | grep 5432
   # Should show: DENY rule for port 5432
   ```

#### Verification Commands:
```bash
# Check UFW status
ufw status verbose

# Expected output should include:
# 5432/tcp (v6)   DENY IN    Anywhere
# 5432/tcp        DENY IN    Anywhere
```

#### Rollback Plan:
```bash
# Find rule number
ufw status numbered | grep 5432
# Delete by number (e.g., if rule #13)
ufw delete 13
ufw reload
```

---

### ⏳ Priority 4: Configure Docker to Respect UFW (PENDING)

**STATUS:** NOT YET APPLIED

**What this changes:**
Modifies Docker daemon configuration to prevent bypassing UFW firewall rules.

#### Implementation Steps:

1. **Backup current Docker daemon config:**
   ```bash
   cp /etc/docker/daemon.json /etc/docker/daemon.json.backup.$(date +%Y%m%d) 2>/dev/null || echo "No existing config"
   ```

2. **Create/update Docker daemon config:**
   ```bash
   cat > /etc/docker/daemon.json <<'EOF'
   {
     "iptables": true,
     "ip-forward": true,
     "userland-proxy": false,
     "log-driver": "json-file",
     "log-opts": {
       "max-size": "10m",
       "max-file": "3"
     }
   }
   EOF
   ```

3. **Add UFW rules to allow Docker network:**
   ```bash
   ufw allow from 172.16.0.0/12 to any
   ufw allow from 192.168.0.0/16 to any
   ```

4. **Restart Docker daemon:**
   ```bash
   systemctl restart docker
   ```

5. **Verify containers restart successfully:**
   ```bash
   docker ps -a
   # Check all expected containers are Up
   
   # If any are stopped, restart them:
   docker-compose up -d
   ```

#### Verification Commands:
```bash
# Check Docker daemon status
systemctl status docker

# Verify containers are running
docker ps

# Test app connectivity
curl -I http://localhost:8080/
```

#### Rollback Plan:
```bash
# Restore backup
cp /etc/docker/daemon.json.backup.<date> /etc/docker/daemon.json
systemctl restart docker
docker-compose up -d
```

---

## Verification Checklist

After all changes are applied, run these commands to verify:

### ✅ PostgreSQL Not Publicly Accessible
```bash
# From VPS:
ss -tlnp | grep 5432
# Should show: 127.0.0.1:5432 OR nothing for docker-proxy

# From external machine:
telnet 145.223.73.170 5432
# Should: Connection refused or timeout
```

### ✅ Application Still Works
```bash
# Test homepage
curl -I http://localhost:8080/
# Expected: HTTP/1.1 200 OK

# Test admin redirect
curl -I http://localhost:8080/admin
# Expected: HTTP/1.1 302 Found

# Test database connectivity
docker exec mbfd-hub-laravel.test-1 php artisan tinker --execute="DB::select('SELECT 1');"
# Expected: No errors
```

### ✅ Fail2ban Active
```bash
fail2ban-client status sshd
# Expected: Status: "active"
```

###✅ Firewall Rules Active
```bash
ufw status verbose | grep 5432
# Expected: DENY rule present
```

---

## Security Posture Summary

### Before Hardening:
- ⚠️ PostgreSQL: **PUBLIC** (0.0.0.0:5432)
- ⚠️ Fail2ban: **INACTIVE**
- ⚠️ Docker: **BYPASSING UFW**
- ⚠️ Port 5432: **NO EXPLICIT BLOCK**

### After Hardening:
- ✅ PostgreSQL: **INTERNAL ONLY** (Docker network)
- ✅ Fail2ban: **ACTIVE** (SSH protection)
- ✅ Docker: **RESPECTING UFW** (where possible)
- ✅ Port 5432: **EXPLICITLY BLOCKED**

### Risk Reduction:
- **PostgreSQL Exposure:** CRITICAL → RESOLVED ✅
- **SSH Brute-Force:** HIGH → MITIGATED ✅
- **Attack Surface:** 13 ports → 3 essential ports (22, 80, 443) ⚠️ (after port review)

---

## Next Steps

1. **Re-establish SSH connection** to VPS
2. **Execute Priority 1-3 changes** (PostgreSQL, fail2ban, firewall)
3. **Verify all changes** using checklist above
4. **Monitor logs** for 24 hours:
   ```bash
   # Watch fail2ban activity
   tail -f /var/log/fail2ban.log
   
   # Watch SSH attempts
   tail -f /var/log/auth.log | grep sshd
   
   # Watch Docker logs
   docker logs -f mbfd-hub-laravel.test-1
   ```
5. **Schedule credential rotation** (see [`SECRETS_INVENTORY.md`](./SECRETS_INVENTORY.md))

---

## Emergency Rollback Commands

If something goes critically wrong:

```bash
# 1. Stop fail2ban (if causing SSH issues)
systemctl stop fail2ban

# 2. Flush all firewall rules (DANGEROUS - only if locked out)
iptables -F
iptables -X
iptables -P INPUT ACCEPT
iptables -P FORWARD ACCEPT
iptables -P OUTPUT ACCEPT

# 3. Restore PostgreSQL port (if app is down)
cd $(find /root -name 'docker-compose.yml' -exec dirname {} \; | head -1)
cp docker-compose.yml.backup.* docker-compose.yml  # Use latest backup
docker-compose restart pgsql

# 4. Restore Docker daemon config
cp /etc/docker/daemon.json.backup.* /etc/docker/daemon.json
systemctl restart docker
```

---

## Additional Recommendations

### Immediate (Not Yet Implemented):
1. **Enable Docker logging to Sentry/CloudWatch** for monitoring
2. **Set up PostgreSQL connection logging** to detect unauthorized access
3. **Install and configure `psad`** (Port Scan Attack Detector)
4. **Review and close unnecessary ports**:8081, 3478, 8443, 19132-19137

### Short-Term (This Week):
1. **Implement automated backups** with off-site storage
2. **Set up intrusion detection** (OSSEC, Wazuh, or similar)
3. **Configure log aggregation** (ELK stack or similar)
4. **Audit all user accounts** and remove unused ones

### Long-Term (This Month):
1. **Migrate to Docker secrets** instead of .env for credentials
2. **Implement secret rotation policy** (90-day cycle)
3. **Set up security monitoring dashboard**
4. **Conduct penetration test** to verify hardening

---

**Document Prepared By:** Kilo Code (Debug Mode)  
**Execution Status:** PENDING - SSH connection interrupted  
**Next Action:** Re-establish SSH and execute Priority 1-3 changes
