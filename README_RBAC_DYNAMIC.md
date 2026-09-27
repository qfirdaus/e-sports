# Dynamic Role-Based Access Control (RBAC)
## SAM 2026 - Database-Driven Access Control System

## Overview

This document describes the dynamic, database-driven RBAC implementation for the SAM 2026 Tournament Management System. The system allows administrators to manage roles, assign roles to users, and configure page and action access rules dynamically through the Settings interface.

## Features

- ✅ **Dynamic Role Management**: Create, edit, and delete roles through UI
- ✅ **User-Role Assignment**: Assign multiple roles to users
- ✅ **Page Access Rules**: Configure which pages require which roles
- ✅ **Action Permissions**: Define system actions and their required permissions
- ✅ **Admin Lockout Prevention**: Ensures at least one admin always exists
- ✅ **Database-Driven**: All access rules stored in database
- ✅ **Caching Support**: Performance optimization with cache table
- ✅ **Backward Compatible**: Falls back to static config if database tables don't exist

## Database Schema

### New Tables

1. **user_roles** - Many-to-many relationship between users and roles
2. **page_access_rules** - Defines page access requirements
3. **page_role_access** - Maps roles to page access
4. **action_permissions** - Defines system actions
5. **action_permission_rules** - Maps permissions to actions
6. **rbac_cache** - Caching table for performance

### Existing Tables Used

- **users** - User accounts
- **roles** - Role definitions
- **permissions** - Permission definitions
- **role_permissions** - Role-permission mappings

## Installation

### Step 1: Run Migration Script

1. Navigate to: `http://localhost/sam2026/database/install_rbac.php?key=install_rbac_2026`
2. The script will:
   - Create all necessary tables
   - Insert initial page access rules
   - Migrate existing users to user_roles table
   - Set up initial permissions

### Step 2: Verify Installation

Check that all tables were created:
- `user_roles`
- `page_access_rules`
- `page_role_access`
- `action_permissions`
- `action_permission_rules`
- `rbac_cache`

### Step 3: Access Settings

Navigate to Settings → "Pengguna & Akses" tab to manage RBAC.

## Usage

### Managing Roles

1. Go to Settings → "Pengguna & Akses"
2. Click "Peranan Baru" to create a role
3. Fill in:
   - **Kod Peranan**: Unique code (e.g., MANAGER)
   - **Nama Peranan**: Display name (e.g., Pengurus)
   - **Penerangan**: Description
   - **Peranan Sistem**: Check if it's a system role (cannot be deleted)
   - **Kebenaran**: Select permissions for this role

### Assigning Roles to Users

1. Go to Settings → "Pengguna & Akses"
2. Select a user from the dropdown
3. View current roles
4. Add/remove roles as needed

**Note**: System prevents removing the last ADMIN role to avoid lockout.

### Configuring Page Access Rules

1. Go to Settings → "Pengguna & Akses"
2. Scroll to "Peraturan Akses Halaman"
3. Click "Peraturan Baru" to create a rule
4. Configure:
   - **Laluan Halaman**: Page path (e.g., `pages/settings.php`)
   - **Halaman Awam**: Check if page is public (no login required)
   - **Memerlukan Pengesahan**: Check if authentication is required
   - **Peranan yang Dibenarkan**: Select which roles can access

### Example: Making a Page Admin-Only

1. Create page rule for `pages/admin-panel.php`
2. Uncheck "Halaman Awam"
3. Check "Memerlukan Pengesahan"
4. Select only "ADMIN" role

## API Endpoints

### Roles API
- `GET /api/rbac/roles.php?action=list` - List all roles
- `GET /api/rbac/roles.php?action=get&id={id}` - Get single role
- `POST /api/rbac/roles.php?action=create` - Create role
- `PUT /api/rbac/roles.php?action=update&id={id}` - Update role
- `DELETE /api/rbac/roles.php?action=delete&id={id}` - Delete role

### Users API
- `GET /api/rbac/users.php?action=list` - List all users with roles
- `GET /api/rbac/users.php?action=get&id={id}` - Get user with roles
- `POST /api/rbac/users.php?action=assign` - Assign role to user
- `DELETE /api/rbac/users.php?action=remove&user_id={id}&role_id={id}` - Remove role from user

### Pages API
- `GET /api/rbac/pages.php?action=list` - List all page rules
- `GET /api/rbac/pages.php?action=get&id={id}` - Get single page rule
- `POST /api/rbac/pages.php?action=create` - Create page rule
- `PUT /api/rbac/pages.php?action=update&id={id}` - Update page rule
- `DELETE /api/rbac/pages.php?action=delete&id={id}` - Delete page rule

## Security Features

### Admin Lockout Prevention

The system includes multiple safeguards:

1. **Cannot delete ADMIN role**: System prevents deletion of ADMIN role
2. **Cannot remove last admin**: Prevents removing ADMIN role from last admin user
3. **At least one admin check**: `hasAtLeastOneAdmin()` method verifies admin exists
4. **System role protection**: System roles cannot be deleted

### Access Control

- All API endpoints require ADMIN access
- Page access rules enforced consistently:
  - Navigation bar visibility
  - Direct URL access
  - System actions

## Performance

### Caching

- RBAC cache table stores frequently accessed rules
- Cache expires automatically
- Can be cleared via `clearCache()` method

### Database Queries

- Optimized queries with proper indexes
- Minimal database calls per request
- Fallback to static config if database unavailable

## Migration from Static RBAC

The system is backward compatible:

1. **If database tables exist**: Uses dynamic RBAC
2. **If database tables don't exist**: Falls back to static config in `config/rbac.php`

This ensures smooth transition and no breaking changes.

## Troubleshooting

### Tables Not Created

1. Check database connection in `config/database.php`
2. Verify database user has CREATE TABLE permissions
3. Run migration script again: `install_rbac.php?key=install_rbac_2026`

### Access Rules Not Working

1. Clear RBAC cache
2. Verify page paths are normalized correctly
3. Check user has active roles assigned
4. Verify page rule exists in database

### Admin Lockout

If you're locked out:
1. Access database directly
2. Insert admin role assignment:
   ```sql
   INSERT INTO user_roles (user_id, role_id, assigned_by, is_active)
   SELECT u.id, r.id, 1, TRUE
   FROM users u, roles r
   WHERE u.username = 'admin' AND r.role_code = 'ADMIN';
   ```

## Future Enhancements

- [ ] Permission-based action control
- [ ] Role expiration dates
- [ ] Audit logging for RBAC changes
- [ ] Role templates
- [ ] Bulk role assignment
- [ ] Role hierarchy inheritance

## Support

For issues or questions:
1. Check this documentation
2. Review API error logs
3. Verify database schema
4. Check RBAC cache

---

**Version**: 1.0.0  
**Last Updated**: 2026  
**Maintained By**: SAM 2026 Development Team

