# SAM 2026 - System Analysis & Flow Documentation

## Table of Contents
1. [System Overview](#system-overview)
2. [Architecture](#architecture)
3. [System Flow](#system-flow)
4. [Component Breakdown](#component-breakdown)
5. [Database Schema](#database-schema)
6. [Authentication & Authorization](#authentication--authorization)
7. [API Structure](#api-structure)
8. [Page Structure](#page-structure)

---

## System Overview

**SAM 2026** (Sukan Asasi Malaysia 2026) is a tournament management system built with PHP, MySQL, and CoreUI Bootstrap admin template. The system manages contingents, athletes, sports, venues, results, and medal tallies for a Malaysian university sports tournament.

### Key Features
- ✅ Role-Based Access Control (RBAC) - Static and Dynamic
- ✅ User Authentication with Session Management
- ✅ Contingent Registration System
- ✅ Tournament Management (Sports, Athletes, Venues)
- ✅ Results & Medal Tally Tracking
- ✅ Reporting System
- ✅ Multi-role User Support

---

## Architecture

### Technology Stack
- **Backend**: PHP 7.4+ (PDO, OOP)
- **Database**: MySQL (InnoDB, UTF8MB4)
- **Frontend**: CoreUI Bootstrap 4.3.0 (Light Theme)
- **Session**: PHP Native Sessions
- **Security**: Password Hashing (bcrypt), SQL Injection Prevention (PDO Prepared Statements)

### Directory Structure
```
sam2026/
├── app/
│   ├── api/              # REST API endpoints
│   │   └── rbac/        # RBAC management APIs
│   ├── assets/           # Static assets (CSS, JS, images)
│   ├── auth/             # Authentication pages
│   ├── config/           # Configuration files
│   ├── database/         # Database scripts & migrations
│   ├── includes/         # Reusable PHP components
│   ├── pages/            # Main application pages
│   └── ajax/             # AJAX handlers
├── docker/               # Docker configuration
└── docs/                 # Documentation
```

---

## System Flow

### 1. Application Initialization Flow

```
User Request → index.php
    ↓
config.php (Site Configuration)
    ├── BASE_URL detection
    ├── SITE_NAME, SITE_DESCRIPTION
    ├── Navigation menu setup
    └── Session initialization
        ↓
auth.php (Authentication Config)
    ├── Session::start()
    └── Auth class initialization
        ↓
database.php (Database Connection)
    ├── Database singleton pattern
    └── PDO connection with error handling
        ↓
rbac.php (Access Control)
    ├── RBAC class initialization
    ├── Check database tables (dynamic RBAC)
    └── Fallback to static config
        ↓
layout.php (Page Layout)
    ├── requirePageAccess() check
    ├── Load header.php
    ├── Load sidebar.php
    └── Render page content
```

### 2. Authentication Flow

```
Login Request → auth/login.php
    ↓
POST: email + password
    ↓
Auth::login()
    ├── Validate user exists
    ├── Check account status (active/inactive/locked)
    ├── Verify password (password_verify)
    ├── Check login attempts (max 5)
    ├── Lock account if exceeded (15 min)
    └── Create session
        ├── Session::regenerate()
        ├── Set user_id, user_role, user_name
        └── Update last_login
            ↓
Redirect based on role:
    ├── ADMIN/ORGANIZER → dashboard.php
    ├── JUDGE → results.php
    ├── CONTINGENT → contingent.php
    └── VIEWER → index.php
```

### 3. Page Access Control Flow

```
Page Request → Any page (e.g., pages/contingent.php)
    ↓
layout.php included
    ↓
rbac.php loaded
    ↓
RBAC::requirePageAccess()
    ├── Normalize page path
    ├── Check if public page
    │   └── If public → Allow access
    ├── Check if user logged in
    │   └── If not → Redirect to login
    ├── Check database RBAC (if enabled)
    │   ├── Query user_roles table
    │   ├── Query page_role_access table
    │   └── Check if user's role has access
    └── Fallback to static config
        ├── Check pageAccessRules array
        └── Verify user_role matches allowed roles
            ↓
Access Decision:
    ├── Allowed → Render page
    ├── Denied → Redirect to access-denied.php
    └── Requires Auth → Redirect to login.php
```

### 4. Contingent Registration Flow

```
User clicks "Daftar Kontinjen Baru"
    ↓
JavaScript: showRegistrationForm()
    ├── Open modal (5-step wizard)
    └── Load saved data from localStorage
        ↓
Step 1: Institution Selection
    ├── Select from dropdown (15 universities)
    └── Validate selection
        ↓
Step 2: Basic Information
    ├── Short name (2-50 chars)
    ├── Head of delegation name
    └── Head position
        ↓
Step 3: Officer Information
    ├── Officer 1 (name, position, phone, email)
    ├── Officer 2 (name, position, phone, email)
    └── Validate phone format (01X-XXXXXXX)
        ↓
Step 4: Contact Details
    ├── Office phone
    ├── Fax
    └── Office address (10-500 chars)
        ↓
Step 5: Review & Confirm
    ├── Display all entered data
    ├── Confirmation checkbox
    └── Submit button
        ↓
submitRegistration()
    ├── Validate all steps
    ├── Save to localStorage (backup)
    └── AJAX POST to backend
        ↓
Backend Processing (Future)
    ├── Validate data
    ├── Insert into contingents table
    └── Return success/error
```

---

## Component Breakdown

### Configuration Files

#### `config/config.php`
- **Purpose**: Site-wide configuration
- **Key Features**:
  - Auto-detects BASE_URL (works in subfolders)
  - Defines site constants (SITE_NAME, SITE_DESCRIPTION)
  - Navigation menu structure (`$nav_items`, `$nav_sections`)
  - Helper functions (`asset()`, `url()`, `logo()`)
  - Active menu detection

#### `config/database.php`
- **Purpose**: Database connection management
- **Pattern**: Singleton pattern
- **Features**:
  - PDO connection with error handling
  - UTF8MB4 charset support
  - Prepared statements enabled
  - Connection reuse via `getDB()` helper

#### `config/auth.php`
- **Purpose**: Authentication & session management
- **Classes**:
  - `Session`: Session wrapper with safety checks
  - `Auth`: Authentication logic
- **Features**:
  - Login attempt tracking (max 5 attempts)
  - Account locking (15 minutes)
  - Password verification (bcrypt)
  - Session regeneration on login
  - Activity logging (audit_logs table)

#### `config/rbac.php`
- **Purpose**: Role-Based Access Control
- **Features**:
  - Static page access rules (fallback)
  - Dynamic database-driven RBAC (if tables exist)
  - Role hierarchy (VIEWER < CONTINGENT < JUDGE < ORGANIZER < ADMIN)
  - Page path normalization
  - Navigation visibility control

### Authentication System

#### Login Process
1. User submits email + password
2. `Auth::login()` validates credentials
3. Checks account status and lock status
4. Verifies password hash
5. Creates session with user data
6. Resets login attempts
7. Logs activity
8. Redirects based on role

#### Session Management
- Session name: `SAM2026_SESSION`
- Lifetime: 3600 seconds (1 hour)
- Security: HTTPOnly, SameSite=Strict
- Regeneration: On login (prevents session fixation)

#### Security Features
- Password hashing: `password_hash()` with PASSWORD_DEFAULT
- SQL injection prevention: PDO prepared statements
- XSS prevention: `htmlspecialchars()` on output
- CSRF protection: Session-based (can be enhanced)
- Account locking: After 5 failed attempts

### RBAC System

#### Static RBAC (Default)
- Defined in `rbac.php` → `$pageAccessRules` array
- Maps page paths to allowed roles
- Fast, no database queries
- Hard to modify without code changes

#### Dynamic RBAC (Optional)
- Database-driven access control
- Tables: `user_roles`, `page_access_rules`, `page_role_access`
- Allows multiple roles per user
- Admin can modify via UI
- Falls back to static if tables don't exist

#### Role Hierarchy
```
VIEWER (1)      - Read-only access
CONTINGENT (2)   - Manage own contingent data
JUDGE (3)        - Enter/verify results
ORGANIZER (4)    - Manage tournaments
ADMIN (5)        - Full system access
```

### Page Structure

#### Layout System
```
layout.php (Base Template)
    ├── header.php (HTML head, CSS, JS)
    ├── sidebar.php (Navigation menu)
    ├── Page Content (from $content variable)
    └── footer.php (Closing tags, JS)
```

#### Page Template Pattern
```php
<?php
require_once __DIR__ . '/../config.php';
$page_title = 'Page Title';

ob_start();
?>
<!-- HTML Content -->
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
```

### API Structure

#### RBAC APIs (`app/api/rbac/`)
- **users.php**: User-role assignment
  - GET: List users with roles
  - POST: Assign role to user
  - DELETE: Remove role from user
- **roles.php**: Role management
- **permissions.php**: Permission management
- **pages.php**: Page access rule management

#### AJAX Handlers (`app/ajax/`)
- **university_save.php**: Save university data
- **university_delete.php**: Delete university

---

## Database Schema

### Core Tables

#### `users`
- User accounts with authentication
- Fields: id, username, email, password_hash, full_name, role, status
- Security: login_attempts, locked_until, last_login
- Soft delete: deleted_at

#### `roles`
- Role definitions
- Fields: id, role_code, role_name, description, is_system_role
- Default roles: ADMIN, ORGANIZER, JUDGE, CONTINGENT, VIEWER

#### `permissions`
- Permission definitions (for future expansion)
- Fields: id, permission_code, permission_name, module

#### `role_permissions`
- Maps permissions to roles (many-to-many)

### RBAC Tables (Dynamic)

#### `user_roles`
- Many-to-many: Users ↔ Roles
- Fields: user_id, role_id, assigned_by, expires_at, is_active
- Allows multiple roles per user

#### `page_access_rules`
- Defines which pages require access control
- Fields: id, page_path, requires_auth, description

#### `page_role_access`
- Maps roles to page access
- Fields: page_rule_id, role_id

### Audit & Session Tables

#### `audit_logs`
- Tracks user actions
- Fields: user_id, action, entity_type, entity_id, description, ip_address

#### `user_sessions`
- Session management (for future expansion)
- Fields: user_id, session_token, ip_address, expires_at

---

## Authentication & Authorization

### Authentication Flow
1. **Login**: `auth/login.php` → `Auth::login()`
2. **Session Creation**: Stores user_id, user_role, user_name
3. **Access Check**: `layout.php` → `RBAC::requirePageAccess()`
4. **Logout**: `auth/logout.php` → `Auth::logout()`

### Authorization Levels

#### Public Pages
- `auth/login.php`
- `auth/logout.php`
- `auth/ajax-login.php`
- `pages/access-denied.php`

#### Role-Based Pages
- **Dashboard**: All authenticated users
- **Contingent**: ADMIN, ORGANIZER, CONTINGENT
- **Sports**: All authenticated users
- **Athletes**: ADMIN, ORGANIZER, JUDGE, CONTINGENT
- **Venues**: ADMIN, ORGANIZER, JUDGE, VIEWER
- **Results**: All authenticated users
- **Medal Tally**: All authenticated users
- **Reports**: ADMIN, ORGANIZER, JUDGE, VIEWER
- **Settings**: ADMIN only
- **Users**: ADMIN only

### Access Control Methods

#### Page-Level Protection
```php
// In layout.php (automatic)
$rbac->requirePageAccess($relativePath);
```

#### Navigation Visibility
```php
// In sidebar.php
if ($rbac->isNavItemVisible($item['url'])) {
    // Show menu item
}
```

#### Manual Checks
```php
// Check if user has role
if ($auth->hasRole('ADMIN')) {
    // Admin-only code
}

// Check minimum role level
if ($rbac->hasMinimumRole('ORGANIZER')) {
    // Organizer+ code
}
```

---

## API Structure

### REST API Endpoints

#### User-Role Management (`api/rbac/users.php`)
```
GET  /api/rbac/users.php?action=list
     → List all users with their roles

GET  /api/rbac/users.php?action=get&id=1
     → Get single user with roles

POST /api/rbac/users.php?action=assign
     Body: {user_id: 1, role_id: 2, expires_at: null}
     → Assign role to user

DELETE /api/rbac/users.php?action=remove&user_id=1&role_id=2
     → Remove role from user
```

### Authentication Required
- All API endpoints require authentication
- Admin role required for RBAC APIs
- Returns JSON responses
- Error handling with HTTP status codes

---

## Page Structure

### Main Pages (`app/pages/`)

#### Dashboard (`dashboard.php`)
- Overview statistics (contingents, sports, athletes, events)
- Recent activity feed
- Quick actions

#### Contingent (`contingent.php`)
- 5-step registration wizard
- Contingent list with search
- Modal-based form
- LocalStorage backup

#### Sports (`sports.php`)
- Sports management (future implementation)

#### Athletes (`athletes.php`)
- Athlete management (future implementation)

#### Venues (`venues.php`)
- Venue management (future implementation)

#### Results (`results.php`)
- Results entry and verification
- Judge role primary access

#### Medal Tally (`medal-tally.php`)
- Medal count by contingent
- Leaderboard display

#### Reports (`reports.php`)
- Various tournament reports
- Export functionality (future)

#### Settings (`settings.php`)
- System configuration
- RBAC management (if dynamic RBAC enabled)
- Admin only

#### Users (`users.php`)
- User management
- Role assignment
- Admin only

---

## Key Design Patterns

### Singleton Pattern
- **Database**: `Database::getInstance()`
- **Auth**: `getAuth()` (static instance)
- **RBAC**: `getRBAC()` (static instance)

### Template Pattern
- **Layout**: `layout.php` wraps all pages
- **Output Buffering**: `ob_start()` / `ob_get_clean()`
- **Content Injection**: `$content` variable

### Factory Pattern
- **Helper Functions**: `getDB()`, `getAuth()`, `getRBAC()`

### Strategy Pattern
- **RBAC**: Static vs Dynamic RBAC (auto-detection)

---

## Security Considerations

### Implemented
✅ Password hashing (bcrypt)
✅ SQL injection prevention (PDO prepared statements)
✅ XSS prevention (htmlspecialchars)
✅ Session security (HTTPOnly, SameSite)
✅ Account locking (brute force protection)
✅ Session regeneration (session fixation prevention)

### Recommendations
⚠️ Add CSRF tokens for forms
⚠️ Implement rate limiting for API endpoints
⚠️ Add input validation middleware
⚠️ Implement password complexity requirements
⚠️ Add 2FA for admin accounts
⚠️ Implement audit logging for sensitive operations

---

## Future Enhancements

### Planned Features
- [ ] Complete contingent registration backend
- [ ] Sports management CRUD
- [ ] Athlete management CRUD
- [ ] Venue management CRUD
- [ ] Results entry system
- [ ] Medal tally calculation
- [ ] Report generation (PDF/Excel)
- [ ] Email notifications
- [ ] File upload (logos, photos)
- [ ] Real-time updates (WebSocket)

### Technical Improvements
- [ ] API versioning
- [ ] Caching layer (Redis/Memcached)
- [ ] Queue system for background jobs
- [ ] Unit tests
- [ ] Integration tests
- [ ] API documentation (OpenAPI/Swagger)
- [ ] Docker deployment
- [ ] CI/CD pipeline

---

## Conclusion

SAM 2026 is a well-structured tournament management system with:
- **Modular architecture** for easy maintenance
- **Flexible RBAC** (static + dynamic options)
- **Security-first** approach
- **Scalable design** for future growth

The system follows PHP best practices and provides a solid foundation for tournament management operations.

---

**Document Version**: 1.0  
**Last Updated**: 2026  
**Author**: System Analysis

