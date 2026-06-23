# POS Session Timeout Implementation

## Overview
Auto-logout inactive POS sessions after 30 minutes to prevent unauthorized access if terminal is left unattended.

## Features
- ⏱️ 30-minute inactivity timeout (configurable)
- ⚠️ 5-minute warning before logout (configurable)
- 📱 Tracks all user input (mouse, keyboard, touch, scroll)
- 🔒 Secure logout with redirect to login page
- 📊 Non-intrusive - only shows warning if approaching timeout

## Usage

### For index.html (POS Terminal)
Add this script to your HTML file:

```html
<!-- In <body> section, before closing </body> tag -->
<script src="session-timeout.js"></script>
```

### For other terminals (waiter.html, kds.html, etc.)
```html
<script src="session-timeout.js"></script>
```

## Configuration
To change timeout duration, edit `session-timeout.js`:

```javascript
const CONFIG = {
    TIMEOUT_MINUTES: 30,       // ← Change this
    WARNING_MINUTES: 5,        // Show warning this many min before
    CHECK_INTERVAL_SECONDS: 30, // Check frequency
};
```

## How It Works

1. **Activity Tracking**: Script listens to user inputs (mouse, keyboard, touch)
2. **Inactivity Timer**: Counts seconds of inactivity
3. **Warning Stage**: When 5 min remaining, shows modal with countdown
4. **Logout**: After 30 minutes of inactivity, forces logout and redirect

## User Experience

### Stage 1: No Warning (0-25 min)
- User works normally, no interruption
- Activity resets the timer

### Stage 2: Warning (25-30 min)
- Modal shows with 5-minute countdown
- User can click "Stay Connected" to extend session
- Or click "Logout Now" for immediate logout

### Stage 3: Timeout (30+ min)
- Automatic logout
- Redirect to login page
- Session cleared

## Server-Side Requirements
No additional server configuration needed - uses client-side session timeout only.

For more security, consider implementing server-side session checks as well.

## Browser Compatibility
- Chrome 50+
- Firefox 40+
- Safari 10+
- Edge 15+
- Mobile browsers (iOS Safari, Chrome Mobile)

## Privacy & Security Benefits
✓ Prevents unauthorized use of unattended terminals  
✓ Protects customer data from shoulder surfers  
✓ Reduces fraud risk in multi-user environments  
✓ Complies with PCI DSS security requirements  
✓ No sensitive data stored in timeout tracking  

## Customization Examples

### 15-Minute Timeout
```javascript
const CONFIG = {
    TIMEOUT_MINUTES: 15,
    WARNING_MINUTES: 2,
    CHECK_INTERVAL_SECONDS: 15,
};
```

### 1-Hour Timeout with 10-Min Warning
```javascript
const CONFIG = {
    TIMEOUT_MINUTES: 60,
    WARNING_MINUTES: 10,
    CHECK_INTERVAL_SECONDS: 60,
};
```

