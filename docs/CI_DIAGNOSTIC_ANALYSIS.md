# CI/CD Pipeline Diagnostic Analysis

**Date**: 2026-02-03  
**Status**: Diagnostics Added - Awaiting Test Run Results

## Problem Statement

The MBFD Support Hub GitHub Actions CI pipeline is failing with:

1. **PostgreSQL Schema Permissions Error**: `SQLSTATE 42501 permission denied for schema public`
2. **Failing Feature Tests**: `AdminMetricsApiTest` tests

## Root Cause Analysis

### Possible Sources Investigated (7 total)

1. **PostgreSQL user ownership mismatch** - CI uses `postgres` user, but schema might belong to different user
2. **Missing POSTGRES_USER in service definition** - Service defaults to `postgres` without explicit configuration
3. **Schema permissions not explicitly granted** ✅ - `postgres` creates DB but Laravel migrations may lack schema access
4. **Database name mismatch** ✅ - `phpunit.xml` uses `testing` while CI workflow uses `mbfd_test`
5. **Missing explicit migration step** ✅ - No `php artisan migrate` before tests, RefreshDatabase does everything
6. **Test data creation failing** - AdminMetricsApiTest creates models without factories
7. **Enum/status field constraints** - Apparatus model status field might have constraints

### Primary Root Causes (Distilled)

#### 1. Database Name Mismatch (CONFIRMED)
- **File**: [`phpunit.xml`](../phpunit.xml:25)
  ```xml
  <env name="DB_DATABASE" value="testing"/>
  ```
- **File**: [`.github/workflows/ci.yml`](../.github/workflows/ci.yml:64)
  ```yaml
  DB_DATABASE: mbfd_test
  ```
- **Impact**: Laravel may connect to wrong database or fail to create `testing` database

#### 2. Schema Permission + Missing Migration Step (LIKELY)
- CI workflow doesn't explicitly run migrations before tests
- The `postgres` superuser creates database, but when RefreshDatabase trait runs:
  1. It tries to drop all tables
  2. Encounter "permission denied for schema public"
- Missing explicit `GRANT ALL ON SCHEMA public TO postgres;` command

## Diagnostic Steps Added

Added two diagnostic steps to `.github/workflows/ci.yml`:

### Step 1: Check Database Configuration
- Verifies environment variables
- Checks PHPUnit configuration for DB_DATABASE
- Lists all PostgreSQL databases
- Checks if `mbfd_test` database exists
- Examines schema permissions
- Identifies public schema owner

### Step 2: Test Migration
- Attempts `php artisan migrate:fresh --force --verbose`
- Lists tables after migration
- Captures any migration errors

## Expected Diagnostic Outputs

### If Database Name Mismatch:
```
DB_DATABASE (from workflow): mbfd_test
PHPUnit Configuration: <env name="DB_DATABASE" value="testing"/>
```

### If Permission Issue:
```
ERROR: permission denied for schema public
```
or
```
public schema owner: postgres (but migrations fail anyway)
```

### If Migration Success:
```
Migration table created successfully.
Migrating: 2026_01_20_170835_create_stations_table
Migrated:  2026_01_20_170835_create_stations_table
[...tables listed successfully...]
```

## Next Steps (After Diagnostic Run)

### If Diagnosis Confirmed - Implement These Fixes:

1. **Fix Database Name Mismatch**
   ```xml
   <!-- phpunit.xml -->
   <env name="DB_DATABASE" value="mbfd_test"/>
   ```

2. **Add Explicit Database Setup in CI**
   ```yaml
   - name: Setup Database
     run: |
       PGPASSWORD=password psql -h 127.0.0.1 -U postgres -c "GRANT ALL ON SCHEMA public TO postgres;"
       PGPASSWORD=password psql -h 127.0.0.1 -U postgres -c "GRANT ALL PRIVILEGES ON DATABASE mbfd_test TO postgres;"
   ```

3. **Add Explicit Migration Step**
   ```yaml
   - name: Run Migrations
     run: php artisan migrate:fresh --force
     env:
       DB_CONNECTION: pgsql
       DB_DATABASE: mbfd_test
       DB_USERNAME: postgres
       DB_PASSWORD: password
   ```

4. **Verify Postgres Service User**
   ```yaml
   services:
     postgres:
       env:
         POSTGRES_USER: postgres  # Explicit
         POSTGRES_PASSWORD: password
         POSTGRES_DB: mbfd_test
   ```

## How to Trigger Diagnostic Run

1. Commit the diagnostic changes:
   ```bash
   git add .github/workflows/ci.yml docs/CI_DIAGNOSTIC_ANALYSIS.md
   git commit -m "ci: add diagnostic logging for schema permission investigation"
   git push origin main
   ```

2. Monitor GitHub Actions output for diagnostic information

3. Review diagnostic output to confirm root causes

4. Implement fixes based on confirmed diagnosis

## Files Modified

- `.github/workflows/ci.yml` - Added diagnostic steps
- `docs/CI_DIAGNOSTIC_ANALYSIS.md` - This file

## Files to Fix (After Confirmation)

- `.github/workflows/ci.yml` - Add setup and migration steps
- `phpunit.xml` - Fix DB_DATABASE value
- Possibly: `tests/TestCase.php` - Add explicit DB connection setup

## References

- [Laravel Testing Database](https://laravel.com/docs/11.x/database-testing)
- [GitHub Actions PostgreSQL Service](https://docs.github.com/en/actions/using-containerized-services/creating-postgresql-service-containers)
- [PostgreSQL Schema Privileges](https://www.postgresql.org/docs/current/ddl-schemas.html#DDL-SCHEMAS-PRIV)
