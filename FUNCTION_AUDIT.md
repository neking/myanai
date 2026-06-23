# MyanAi Functions Audit & Issues Found

## Critical Functions Analysis

### 1. SIGNUP FLOW (tenant_api.php)
✓ **Status:** WORKING but has issues

**Issues Found:**
```
1. Email sending can fail silently
   Line: try { sendWelcomeEmail(...); } catch (Exception $e) { ... }
   Impact: Customer doesn't get welcome email, doesn't know why
   Fix: Notify user and log error

2. Database transaction not atomic
   - INSERT tenant, branch, staff, menu separately
   - If any fails, partial data created
   Fix: Wrap in BEGIN/COMMIT/ROLLBACK

3. No duplicate check on phone
   - Same phone can create multiple accounts
   Fix: Add unique constraint on owner_phone
```

---

### 2. ORDER PROCESSING (order_handler.php)
✓ **Status:** WORKING but performance issues

**Issues Found:**
```
1. N+1 Query Problem
   - Loads order header, then loops through items
   - 1 order with 5 items = 6 queries
   Fix: Use JOIN to fetch in 1 query

2. No inventory validation
   - Can sell more items than available
   Fix: Check stock before confirming order

3. Concurrent order bug
   - Two orders at same time might both deduct stock
   Fix: Use database locks or atomic operations
```

---

### 3. PAYMENT VERIFICATION (Not yet implemented)
✗ **Status:** MISSING

**Current Gap:**
```
- No payment gateway integration
- Manual verification needed
- No transaction ID tracking
- No payment failure handling
```

---

### 4. STAFF LOGIN (admin.php)
⚠️ **Status:** WORKING but security gap

**Issues Found:**
```
1. PIN is 4 digits only
   - Weak security (only 10,000 combinations)
   - Vulnerable to brute force
   Fix: Implement rate limiting, longer PIN options

2. No session timeout in POS
   - Staff logged in indefinitely
   - Privacy risk if device stolen
   Fix: Add auto-logout after 30 min inactivity

3. No PIN change mechanism
   - Same PIN for months
   Fix: Force PIN change on first login
```

---

### 5. MULTI-BRANCH LOGIC (tenant.php)
⚠️ **Status:** WORKING but has gaps

**Issues Found:**
```
1. Branch context sometimes lost
   - Switching branches might show wrong data
   - Caching issue: branch ID not always updated
   Fix: Add validation on each page load

2. Cross-branch orders possible
   - Not validating tenant ownership
   Fix: Always check tenant_id in WHERE clause

3. No branch-level reports
   - Only overall reports available
   Fix: Add branch filter to all reports
```

---

### 6. CUSTOMER LOYALTY (loyalty.php)
✓ **Status:** WORKING fine

**Minor Issues:**
```
1. No expiry for loyalty stamps
   - Customer never loses stamps
   - Business logic unclear
   Fix: Add stamp expiration (1 year?)

2. No bulk operations
   - Can't reset loyalty for closed customers
   Fix: Add admin bulk action
```

---

### 7. INVENTORY TRACKING (stock_api.php)
⚠️ **Status:** WORKING but data integrity issues

**Issues Found:**
```
1. Stock can go negative
   - Order more items than available
   - Causes negative inventory
   Fix: Add constraint: stock >= 0

2. No stock alert system
   - Staff doesn't know when running low
   Fix: Send notifications at thresholds

3. No stock count verification
   - No way to audit actual vs system count
   Fix: Add periodic count reconciliation
```

---

### 8. REPORT GENERATION (reports_api.php)
⚠️ **Status:** WORKING but slow

**Issues Found:**
```
1. Reports load all data then filter
   - 10,000 orders loaded into memory
   - PHP timeout on large datasets
   Fix: Add server-side filtering & pagination

2. No scheduled reports
   - Manual generation only
   Fix: Add cron jobs for daily/weekly reports

3. Report caching missing
   - Same report called multiple times
   Fix: Cache for 1 hour
```

---

### 9. DATA BACKUP (backup_api.php)
⚠️ **Status:** WORKING but risky

**Issues Found:**
```
1. Backup stored locally
   - Lost if server crashes
   Fix: Upload to S3 or cloud storage

2. No retention policy
   - Backups accumulate indefinitely
   Fix: Delete backups > 30 days old

3. No restore testing
   - Backup might be corrupt
   Fix: Automated restore test weekly
```

---

### 10. ADMIN IMPERSONATION (admin.php)
⚠️ **Status:** WORKING but security risk

**Issues Found:**
```
1. No audit log
   - Can't see who impersonated whom
   - When did they make changes
   Fix: Log all admin actions with timestamps

2. No time limit
   - Admin can stay impersonated indefinitely
   Fix: Auto-exit after 30 minutes

3. No permission boundaries
   - Admin can do anything as tenant
   Fix: Restrict to read-only if needed
```

---

## SUMMARY OF ISSUES BY SEVERITY

### 🔴 CRITICAL (Fix immediately)
1. Database transactions not atomic (data corruption risk)
2. Stock can go negative (business logic broken)
3. No payment gateway (can't actually sell)
4. N+1 queries (will break at scale)

### 🟡 WARNING (Fix before scaling)
1. Concurrent order bug (race condition)
2. No admin audit log (compliance issue)
3. Backup not offsite (disaster recovery)
4. PIN too weak (security risk)
5. Session doesn't timeout (privacy risk)

### 🟢 MINOR (Nice to fix)
1. Loyalty stamps don't expire
2. Report generation slow
3. Cross-branch data validation
4. Stock alerts missing

---

## RECOMMENDED FIX PRIORITY

### Week 1 (Critical)
- Add database transactions to signup
- Add stock validation before order
- Implement order concurrent locks
- Add admin audit log

### Week 2 (Security)
- Add rate limiting to PIN login
- Auto-logout inactive sessions
- Add payment gateway stub
- Audit all SQL queries for injection

### Week 3 (Performance)
- Fix N+1 queries with JOINs
- Add report caching
- Optimize branch query filters
- Add database indexes

### Week 4 (Quality)
- Add data validation tests
- Implement backup to S3
- Create restore testing
- Document all functions

---

## RISK ASSESSMENT

**If launched as-is:** 🔴 HIGH RISK
- Data corruption possible (signup, orders)
- Security vulnerabilities (PIN, audit logs)
- Performance issues (N+1 queries)
- Compliance gaps (no audit logs)

**Recommended Action:** 
Fix critical issues first (Week 1), then launch with beta warning.

**Timeline:** 4 weeks for production-ready
