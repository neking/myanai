# MyanAi Onboarding & Email System

## 📋 Overview

This document outlines the new onboarding and email notification system for MyanAi tenant signup and activation.

## ✨ New Features

### 1. **Email Notifications** (`mailer.php`)
- ✅ Welcome email with setup instructions
- ✅ Verification email template
- ✅ Beautiful HTML email templates with brand styling
- ✅ Error handling and logging

### 2. **Onboarding Guide** (`onboarding.html`)
- 📖 Interactive 5-step setup guide
- 🎯 Progressive step navigation
- 📚 Feature explanations for each section
- 💡 Pro tips and best practices
- ❓ FAQ section with common questions
- 🔗 Support links (docs, video, email, FAQ)

### 3. **Email Verification** (`verify-email.php`)
- 🔐 Email verification workflow
- 📧 Secure token-based verification
- ✓ Verification status tracking in database

## 🔄 Signup Flow (Updated)

```
1. User fills signup form (signup.html)
   ↓
2. API creates tenant account (tenant_api.php::signup)
   ↓
3. Welcome email sent to owner_email
   ↓
4. User redirected to onboarding.html
   ↓
5. User completes 5-step setup guide
   ↓
6. Dashboard access with pre-populated menu
```

## 📧 Email Features

### Welcome Email (`sendWelcomeEmail`)
Sent immediately after account creation with:
- 🎉 Welcome message
- 🚀 Quick setup instructions (2 minutes)
- 📋 Account credentials summary
- 🔗 Dashboard login link
- 💡 Pro tips
- 📚 Getting started resources
- 🎁 Trial period information

### Verification Email (`sendVerificationEmail`)
- 🔐 Secure verification link
- 📧 Professional HTML template
- ✓ One-click verification

## 📖 Onboarding Steps

### Step 1: Dashboard Overview
- What dashboard includes
- Key features overview
- Quick familiarization

### Step 2: Add Menu Items
- How to update sample items
- Setting prices in MMK
- Adding categories
- Inventory management
- Links to menu management page

### Step 3: Create Staff Accounts
- PIN-based authentication (4-digit)
- Role assignments (Cashier, Manager, Admin)
- Permission control
- Activity tracking

### Step 4: POS Terminal
- Terminal features
- Touchscreen optimization
- Order processing flow
- Multi-channel support (Dine-in, Takeaway, Delivery)
- Receipt printing

### Step 5: Settings & Support
- Business branding
- Online ordering
- Reports and analytics
- FAQ section
- Support links

## 🛠️ Technical Implementation

### Files Modified/Added

1. **mailer.php** (Enhanced)
   - Added `welcomeOnboardingEmail()` function
   - Added `sendWelcomeEmail()` function
   - Added `sendVerificationEmail()` function

2. **tenant_api.php** (Enhanced)
   - Added `require_once 'mailer.php'`
   - Added welcome email sending after signup
   - Error handling for email failures

3. **signup.html** (Enhanced)
   - Updated success message
   - Redirect to onboarding.html instead of admin.php

4. **onboarding.html** (New)
   - Interactive 5-step guide
   - Progress tracking
   - Navigation buttons
   - FAQ section
   - Support links

5. **verify-email.php** (New)
   - Email verification handler
   - Token validation
   - Status update in database

## 🚀 Deployment Instructions

### 1. Push Code to Production
```bash
cd /var/www/myanai
sudo git add -A
sudo git commit -m "feat: add onboarding and email system"
sudo git push origin main --force
```

### 2. Clear Cache
```bash
sudo sed -i "s/signup.html?v=[0-9]*/signup.html?v=$(date +%s)/" /var/www/myanai/admin.php
```

### 3. Test the Flow
1. Visit https://myanai.net/signup.html
2. Fill in signup form
3. Submit
4. Check email inbox for welcome email
5. Follow onboarding guide
6. Complete all 5 steps
7. Click "Get Started" → Admin Dashboard

## 📊 Database Updates

### New Fields (if needed)
- `tenants.settings` now includes:
  - `email_verified` (boolean)
  - `verified_at` (timestamp)
  - `onboarded` (boolean)

## 🔧 Configuration

### Email Settings
- From: `noreply@myanai.net`
- Reply-To: `noreply@myanai.net`
- Content-Type: `text/html; charset=UTF-8`
- Uses PHP `mail()` function

### Verification Link Format
```
https://myanai.net/verify-email.php?slug={slug}&token={base64_encoded_email}
```

## ✅ Testing Checklist

- [ ] Signup form accepts all fields
- [ ] Welcome email is sent
- [ ] Email arrives within 5 minutes
- [ ] Email is properly formatted
- [ ] Onboarding page loads correctly
- [ ] All 5 steps are navigable
- [ ] FAQ items expand/collapse
- [ ] Support links work
- [ ] Dashboard link works
- [ ] Verification email link works
- [ ] Email verified status updates in database

## 🎯 Future Enhancements

- [ ] Email template customization
- [ ] Resend verification email link
- [ ] SMS onboarding notifications
- [ ] Video tutorial embeds
- [ ] Personalized setup recommendations
- [ ] Progress tracking across sessions
- [ ] Email marketing automation
- [ ] Trial expiration reminders

## 📞 Support

For issues or questions about the onboarding system:
- Email: support@myanai.net
- Docs: https://myanai.net/docs
- Video: https://myanai.net/video-guide

---

**Last Updated:** 2024
**Version:** 1.0
