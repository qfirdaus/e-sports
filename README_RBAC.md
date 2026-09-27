# Role-Based Access Control (RBAC) Implementation
## SAM 2026 - Phase 1

## Overview
This document describes the RBAC implementation for the SAM 2026 Tournament Management System. Phase 1 focuses on database structure and administrator authentication only.

## Database Structure

### Users Table
The `users` table supports RBAC with the following key features:

- **Authentication Fields:**
  - `username` - Unique username
  - `email` - Unique email address
  - `password_hash` - Hashed password using PHP's `password_hash()`
  - `last_login` - Timestamp of last login
  - `last_login_ip` - IP address of last login
  - `login_attempts` - Number of failed login attempts
  - `locked_until` - Account lockout expiration

- **Role Management:**
  - `role` - ENUM field with values: ADMIN, ORGANIZER, JUDGE, CONTINGENT, VIEWER
  - Currently only ADMIN role is active in Phase 1

- **Status Management:**
  - `status` - ENUM field: active, inactive, suspended, pending

- **Audit Fields:**
  - `created_at`, `created_by`, `updated_at`, `updated_by`, `deleted_at`

### Supporting Tables (For Future Phases)
- `roles` - Role definitions
- `permissions` - Permission definitions
- `role_permissions` - Role-permission mappings
- `user_sessions` - Session management
- `audit_logs` - Activity logging

## Installation

### Step 1: Create Database
1. Open phpMyAdmin or MySQL command line
2. Run the SQL script: `database/schema.sql`
   - Or use the installation script: `database/install.php?key=install_sam2026_2026`

### Step 2: Configure Database
Edit `config/database.php` and update:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sam2026');
```

### Step 3: Default Administrator Account
After installation, use these credentials:
- **Username:** `admin`
- **Password:** `admin123`
- **Role:** `ADMIN`

**⚠️ IMPORTANT:** Change the password immediately after first login!

## Authentication Flow

### Login Process
1. User submits credentials on `/auth/login.php`
2. System validates username/email and password
3. Checks account status (must be 'active')
4. Checks if account is locked (too many failed attempts)
5. Verifies password using `password_verify()`
6. **Phase 1:** Only ADMIN role can login
7. Creates session and logs activity
8. Redirects to dashboard

### Session Management
- Sessions use PHP's native session handling
- Session lifetime: 1 hour (configurable in `config/auth.php`)
- Session regeneration on login for security
- Session data stored in `$_SESSION`:
  - `user_id`
  - `user_username`
  - `user_email`
  - `user_name`
  - `user_role`
  - `logged_in_at`

### Security Features
- **Password Hashing:** Uses PHP's `password_hash()` with `PASSWORD_DEFAULT`
- **Account Lockout:** After 5 failed attempts, account locked for 15 minutes
- **Session Security:** HTTP-only cookies, secure flag (when HTTPS), SameSite=Strict
- **IP Tracking:** Records IP address on login
- **Audit Logging:** All login/logout activities logged

## File Structure

```
sam2026/
├── auth/
│   ├── login.php          # Login page
│   └── logout.php         # Logout handler
├── config/
│   ├── database.php       # Database connection
│   └── auth.php           # Authentication logic
├── database/
│   ├── schema.sql         # Database schema
│   └── install.php        # Installation script
└── includes/
    ├── layout.php         # Layout with auth check
    └── topbar.php         # Topbar with user info
```

## Usage

### Protecting Pages
All pages are automatically protected via `includes/layout.php`. To skip authentication check:
```php
define('SKIP_AUTH_CHECK', true);
```

### Checking Authentication
```php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$auth = getAuth();

// Check if logged in
if ($auth->isLoggedIn()) {
    // User is authenticated
}

// Get current user
$user = $auth->getUser();

// Require authentication
$auth->requireAuth();

// Require specific role (for future phases)
$auth->requireRole('ADMIN');
```

### Login/Logout
- **Login:** `/auth/login.php`
- **Logout:** `/auth/logout.php`

## Phase 1 Limitations

- ✅ Only ADMIN role can login
- ✅ Full authentication flow implemented
- ✅ Session management working
- ✅ Account lockout protection
- ✅ Audit logging
- ❌ Other roles (ORGANIZER, JUDGE, CONTINGENT, VIEWER) cannot login yet
- ❌ Permission system not yet implemented
- ❌ Role-based page access not yet implemented

## Next Phases

### Phase 2 (Planned)
- Enable other roles to login
- Implement permission checking
- Role-based page access control
- User management interface

### Phase 3 (Planned)
- Advanced permission system
- Custom role creation
- Permission assignment interface
- Activity monitoring dashboard

## Security Notes

1. **Password Storage:** Never store plain text passwords. Always use `password_hash()`
2. **SQL Injection:** All queries use prepared statements
3. **Session Hijacking:** Sessions regenerate on login
4. **XSS Protection:** All user input is escaped with `htmlspecialchars()`
5. **CSRF Protection:** To be implemented in future phases

## Troubleshooting

### Cannot Login
1. Check database connection in `config/database.php`
2. Verify database and tables exist
3. Check if user account exists and is active
4. Verify password hash is correct
5. Check PHP error logs

### Session Issues
1. Check `session.save_path` in php.ini
2. Verify session cookies are being set
3. Check browser console for cookie issues

### Database Errors
1. Verify MySQL/MariaDB is running
2. Check database credentials
3. Ensure database exists: `sam2026`
4. Run schema.sql again if tables missing

## Support

For issues or questions, check:
- Database logs: Check MySQL error log
- PHP logs: Check PHP error log
- Application logs: Check `audit_logs` table

