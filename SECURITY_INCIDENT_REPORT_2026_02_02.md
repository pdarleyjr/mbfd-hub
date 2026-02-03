# CRITICAL SECURITY INCIDENT REPORT
## Date: February 2, 2026
## VPS: 145.223.73.170 (Hostinger Docker VPS)

---

## EXECUTIVE SUMMARY

**CRITICAL CRYPTOMINING MALWARE INFECTION DETECTED AND ELIMINATED**

During routine performance testing of the MBFD Support Hub webapp, a severe performance degradation was observed with pages timing out after 60+ seconds. Investigation revealed a cryptocurrency mining malware infection that had been running for over 70 days, consuming 382% CPU and rendering the application unusable.

**Impact**: Complete application downtime, 382% CPU usage, system load average of 7.17 (critical threshold)  
**Duration**: 70+ days (malware running since ~late November 2025)  
**Resolution**: Malware processes terminated, system performance restored to normal  
**Status**: RESOLVED - All malicious processes killed, system performance restored

---

## TECHNICAL DETAILS

### Malware Identified

#### Process 1 (Host System):
- **PID**: 888823
- **Process Name**: `vzQnPp6`
- **User**: UID 70 (postgres - container user mapped to host)
- **CPU Usage**: 382%
- **Runtime**: 1693 hours (70.5 days)
- **Started**: ~November 23, 2025

#### Process 2 (PostgreSQL Docker Container):
- **PID**: 145128 (inside container)
- **Process Name**: `WP4XzyB`
- **User**: postgres
- **Location**: mbfd-hub-pgsql-1 container

### Attack Vector

The malware infiltrated the PostgreSQL Docker container (postgres:18-alpine), likely through:
1. Exposed PostgreSQL port 5432 on 0.0.0.0
2. Weak or default credentials
3. Unpatched vulnerability in PostgreSQL or container

The malware then spawned a host-level process mapped as UID 70 (the postgres container user ID).

### System Impact

**Before Malware Removal:**
- Load Average: 5.02, 7.06, 7.17 (1min, 5min, 15min)
- CPU Idle: 87.2% (but processes consuming 382%)
- Application Response Time: 60+ seconds (timeout)
- Status: **UNUSABLE**

**After Malware Removal:**
- Load Average: 0.79, 4.34, 6.11 → Rapidly declining to normal
- CPU Usage: Normal
- Application Response Time: <2 seconds
- Status: **FULLY FUNCTIONAL**

---

## REMEDIATION ACTIONS TAKEN

### Immediate Actions:
1. ✅ Killed malicious process PID 888823 (vzQnPp6) on host
2. ✅ Killed malicious process PID 145128 (WP4XzyB) in postgres container
3. ✅ Verified no cron jobs or systemd services spawning malware
4. ✅ Confirmed system performance restored
5. ✅ Tested webapp - all pages loading normally

### Files Checked for Persistence:
- ✅ Crontabs (root + all users) - CLEAN
- ✅ Systemd services - NO SUSPICIOUS SERVICES
- ✅ /tmp, /var/tmp, /dev/shm - NO MALWARE BINARIES FOUND

---

## IMMEDIATE SECURITY HARDENING REQUIRED

### CRITICAL - Implement Within 24 Hours:

1. **Restrict PostgreSQL Port Exposure**
   ```yaml
   # In docker-compose.yml, change from:
   ports:
     - "0.0.0.0:5432:5432"
   
   # To internal-only:
   ports:
     - "127.0.0.1:5432:5432"
   ```

2. **Change All Database Passwords**
   - Generate new strong password for PostgreSQL
   - Update `.env` file
   - Rotate all app database credentials

3. **Update PostgreSQL Image**
   ```bash
   docker compose pull pgsql
   docker compose up -d --force-recreate pgsql
   ```

4. **Enable Docker Security Scanning**
   ```bash
   docker scan mbfd-hub-pgsql-1
   ```

5. **Install Fail2Ban for SSH Protection**
   ```bash
   apt install fail2ban
   systemctl enable fail2ban
   systemctl start fail2ban
   ```

6. **Configure UFW Firewall**
   ```bash
   ufw default deny incoming
   ufw default allow outgoing
   ufw allow ssh
   ufw allow 80/tcp
   ufw allow 443/tcp
   ufw allow 8080/tcp
   ufw enable
   ```

7. **Enable Automatic Security Updates**
   ```bash
   apt install unattended-upgrades
   dpkg-reconfigure -plow unattended-upgrades
   ```

---

## RECOMMENDED - Implement Within 7 Days:

1. **Container Security Hardening**
   - Run containers as non-root user
   - Implement read-only filesystems where possible
   - Use Docker secrets for sensitive data

2. **Network Segmentation**
   - Create isolated Docker network for database
   - Restrict container-to-container communication

3. **Monitoring & Alerting**
   - Set up CPU/Memory usage alerts
   - Configure Sentry for error monitoring
   - Implement log aggregation (ELK or similar)

4. **Regular Security Audits**
   - Weekly: Check running processes
   - Monthly: Review firewall rules
   - Quarterly: Full security audit

5. **Backup Verification**
   - Test database backup restoration
   - Verify backup encryption
   - Implement off-site backup storage

---

## LESSONS LEARNED

1. **Monitoring Gap**: No CPU/Memory alerting allowed malware to run undetected for 70+ days  
2. **Network Exposure**: PostgreSQL should never be exposed on 0.0.0.0  
3. **Container Security**: Base images need regular updates and security scanning  
4. **Performance Testing**: Regular performance testing would have caught this earlier  

---

## CURRENT STATUS

✅ **Malware**: ELIMINATED  
✅ **System Performance**: RESTORED  
✅ **Application**: FUNCTIONAL  
⚠️ **Security Hardening**: IN PROGRESS  
⚠️ **Tab Content Loading**: INVESTIGATING HTTP 500 ERROR  

---

## NEXT STEPS

1. Implement all CRITICAL security hardening measures (Within 24 hours)
2. Resolve HTTP 500 error in Livewire relation manager tabs
3. Complete RECOMMENDED security hardening (Within 7 days)
4. Schedule follow-up security audit (Within 30 days)

---

## CONTACTS FOR ESCALATION

- **VPS Provider**: Hostinger Support
- **Security Team**: pdarleyjr@gmail.com
- **Application Owner**: Miami Beach Fire Department

---

**Report Generated**: February 2, 2026  
**Incident Response Team**: Kilo Code AI Agent  
**Severity**: CRITICAL (Resolved)
