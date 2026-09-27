# SAM 2026 - Documentation Index

## Overview
This directory contains comprehensive documentation for the SAM 2026 Tournament Management System.

## Documentation Files

### 📋 System Analysis
- **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** - Complete system analysis including:
  - Architecture overview
  - Component breakdown
  - Database schema
  - Authentication & authorization
  - API structure
  - Security considerations

### 📊 Flow Diagrams
- **[SYSTEM_FLOW_DIAGRAMS.md](./SYSTEM_FLOW_DIAGRAMS.md)** - Visual flow representations:
  - Application request flow
  - Authentication flow
  - Page access control flow
  - Contingent registration flow
  - RBAC decision tree
  - Database connection flow
  - Session management flow
  - Navigation menu flow

### 📝 Feature Documentation
- **[CONTINGENT_REGISTRATION_FLOW.md](./CONTINGENT_REGISTRATION_FLOW.md)** - Contingent registration process
- **[CONTINGENT_REGISTRATION_SUMMARY.md](./CONTINGENT_REGISTRATION_SUMMARY.md)** - Summary of registration features

### 🔐 RBAC Documentation
- **[../README_RBAC.md](../README_RBAC.md)** - Role-Based Access Control overview
- **[../README_RBAC_DYNAMIC.md](../README_RBAC_DYNAMIC.md)** - Dynamic RBAC implementation

### 📖 General Documentation
- **[../README.md](../README.md)** - Project overview and setup instructions

## Quick Navigation

### For Developers
1. Start with **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** for architecture understanding
2. Review **[SYSTEM_FLOW_DIAGRAMS.md](./SYSTEM_FLOW_DIAGRAMS.md)** for flow understanding
3. Check **[README_RBAC.md](../README_RBAC.md)** for access control details

### For System Administrators
1. Review **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** - Security Considerations section
2. Check **[README_RBAC_DYNAMIC.md](../README_RBAC_DYNAMIC.md)** for RBAC management
3. Review database schema in **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** - Database Schema section

### For Project Managers
1. Review **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** - System Overview
2. Check **[SYSTEM_ANALYSIS.md](./SYSTEM_ANALYSIS.md)** - Future Enhancements section
3. Review feature documentation for current capabilities

## Key Concepts

### Authentication Flow
```
User → Login → Auth::login() → Session Creation → Role-based Redirect
```

### Page Access Control
```
Request → RBAC Check → Database/Static Rules → Allow/Deny/Redirect
```

### Contingent Registration
```
Modal → 5-Step Wizard → Validation → LocalStorage → Backend (Future)
```

## System Components

### Core Components
- **config.php** - Site configuration
- **auth.php** - Authentication system
- **database.php** - Database connection
- **rbac.php** - Access control
- **layout.php** - Page template

### Main Pages
- **dashboard.php** - Overview
- **contingent.php** - Contingent management
- **sports.php** - Sports management
- **athletes.php** - Athlete management
- **venues.php** - Venue management
- **results.php** - Results entry
- **medal-tally.php** - Medal tracking
- **reports.php** - Reports
- **settings.php** - System settings
- **users.php** - User management

### API Endpoints
- **api/rbac/users.php** - User-role management
- **api/rbac/roles.php** - Role management
- **api/rbac/permissions.php** - Permission management
- **api/rbac/pages.php** - Page access rules

## Database Tables

### Core Tables
- `users` - User accounts
- `roles` - Role definitions
- `permissions` - Permission definitions
- `role_permissions` - Role-permission mappings

### RBAC Tables (Dynamic)
- `user_roles` - User-role assignments
- `page_access_rules` - Page access rules
- `page_role_access` - Role-page mappings

### Audit Tables
- `audit_logs` - Activity logging
- `user_sessions` - Session management

## Security Features

✅ Password hashing (bcrypt)
✅ SQL injection prevention (PDO)
✅ XSS prevention (htmlspecialchars)
✅ Session security (HTTPOnly, SameSite)
✅ Account locking (brute force protection)
✅ Session regeneration

## Technology Stack

- **Backend**: PHP 7.4+
- **Database**: MySQL (InnoDB)
- **Frontend**: CoreUI Bootstrap 4.3.0
- **Session**: PHP Native Sessions
- **Security**: PDO, password_hash()

## Getting Started

1. **Setup**: Follow instructions in [README.md](../README.md)
2. **Database**: Run schema.sql from `app/database/`
3. **RBAC**: Install dynamic RBAC (optional) via `install_rbac.php`
4. **Login**: Use default admin account (see schema.sql)

## Support

For questions or issues:
1. Review relevant documentation files
2. Check code comments in source files
3. Review system logs for errors

---

**Last Updated**: 2026  
**Documentation Version**: 1.0

