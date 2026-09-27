-- Dynamic RBAC Migration Script
-- SAM 2026 - Extends existing schema for database-driven access control

USE esportsdb;

-- ============================================
-- 1. User Roles Table (Many-to-Many)
-- Allows users to have multiple roles
-- ============================================
CREATE TABLE IF NOT EXISTS user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    UNIQUE KEY unique_user_role (user_id, role_id),
    INDEX idx_user_id (user_id),
    INDEX idx_role_id (role_id),
    INDEX idx_is_active (is_active),
    INDEX idx_assigned_by (assigned_by),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. Page Access Rules Table
-- Defines which pages require which roles
-- ============================================
CREATE TABLE IF NOT EXISTS page_access_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_path VARCHAR(255) NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    requires_auth BOOLEAN DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    
    UNIQUE KEY unique_page_path (page_path),
    INDEX idx_is_public (is_public),
    INDEX idx_requires_auth (requires_auth),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by),
    INDEX idx_page_access_public (is_public, requires_auth),
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. Page Role Access Table
-- Maps roles to page access
-- ============================================
CREATE TABLE IF NOT EXISTS page_role_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_rule_id INT NOT NULL,
    role_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    
    UNIQUE KEY unique_page_role (page_rule_id, role_id),
    INDEX idx_page_rule_id (page_rule_id),
    INDEX idx_role_id (role_id),
    INDEX idx_created_by (created_by),
    
    FOREIGN KEY (page_rule_id) REFERENCES page_access_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. Action Permissions Table
-- Defines system actions and their required permissions
-- ============================================
CREATE TABLE IF NOT EXISTS action_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_code VARCHAR(100) NOT NULL UNIQUE,
    action_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    module VARCHAR(50) NULL,
    requires_permission BOOLEAN DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_action_code (action_code),
    INDEX idx_module (module),
    INDEX idx_requires_permission (requires_permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. Action Permission Rules Table
-- Maps permissions to actions
-- ============================================
CREATE TABLE IF NOT EXISTS action_permission_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_action_permission (action_id, permission_id),
    INDEX idx_action_id (action_id),
    INDEX idx_permission_id (permission_id),
    
    FOREIGN KEY (action_id) REFERENCES action_permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. RBAC Cache Table (Optional - for performance)
-- Stores cached access rules
-- ============================================
CREATE TABLE IF NOT EXISTS rbac_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    cache_value TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. Insert Initial Page Access Rules
-- ============================================
INSERT INTO page_access_rules (page_path, is_public, requires_auth, created_by) VALUES
('index.php', FALSE, TRUE, 1),
('pages/contingent.php', FALSE, TRUE, 1),
('pages/sports.php', FALSE, TRUE, 1),
('pages/athletes.php', FALSE, TRUE, 1),
('pages/venues.php', FALSE, TRUE, 1),
('pages/results.php', FALSE, TRUE, 1),
('pages/medal-tally.php', FALSE, TRUE, 1),
('pages/reports.php', FALSE, TRUE, 1),
('pages/settings.php', FALSE, TRUE, 1)
ON DUPLICATE KEY UPDATE requires_auth=TRUE, is_public=FALSE;

-- Role mappings for page access
INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER')
WHERE par.page_path = 'index.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','CONTINGENT')
WHERE par.page_path = 'pages/contingent.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER')
WHERE par.page_path = 'pages/sports.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','CONTINGENT')
WHERE par.page_path = 'pages/athletes.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','VIEWER')
WHERE par.page_path = 'pages/venues.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER')
WHERE par.page_path = 'pages/results.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER')
WHERE par.page_path = 'pages/medal-tally.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN','ORGANIZER','JUDGE','VIEWER')
WHERE par.page_path = 'pages/reports.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

INSERT INTO page_role_access (page_rule_id, role_id, created_by)
SELECT par.id, r.id, 1
FROM page_access_rules par
INNER JOIN roles r ON r.role_code IN ('ADMIN')
WHERE par.page_path = 'pages/settings.php'
AND NOT EXISTS (
    SELECT 1 FROM page_role_access pra
    WHERE pra.page_rule_id = par.id AND pra.role_id = r.id
);

-- ============================================
-- 8. Migrate existing users to user_roles table
-- ============================================
-- Link existing users to their roles based on the role field
INSERT INTO user_roles (user_id, role_id, assigned_by, is_active)
SELECT 
    u.id,
    r.id,
    1, -- Assigned by system
    CASE WHEN u.status = 'active' THEN TRUE ELSE FALSE END
FROM users u
INNER JOIN roles r ON r.role_code = u.role
WHERE NOT EXISTS (
    SELECT 1 FROM user_roles ur 
    WHERE ur.user_id = u.id AND ur.role_id = r.id
);

-- ============================================
-- 9. Insert Initial Permissions (for role assignment)
-- ============================================
INSERT INTO permissions (permission_code, permission_name, description, module) VALUES
('user.create', 'Cipta Pengguna', 'Membuat pengguna baru', 'users'),
('user.edit', 'Edit Pengguna', 'Mengubah maklumat pengguna', 'users'),
('user.delete', 'Padam Pengguna', 'Memadam pengguna', 'users'),
('user.view', 'Lihat Pengguna', 'Melihat senarai pengguna', 'users'),
('role.create', 'Cipta Peranan', 'Membuat peranan baru', 'rbac'),
('role.edit', 'Edit Peranan', 'Mengubah peranan', 'rbac'),
('role.delete', 'Padam Peranan', 'Memadam peranan', 'rbac'),
('role.assign', 'Tugaskan Peranan', 'Menugaskan peranan kepada pengguna', 'rbac'),
('contingent.create', 'Cipta Kontinjen', 'Membuat kontinjen baru', 'contingent'),
('contingent.edit', 'Edit Kontinjen', 'Mengubah maklumat kontinjen', 'contingent'),
('contingent.delete', 'Padam Kontinjen', 'Memadam kontinjen', 'contingent'),
('sports.create', 'Cipta Sukan', 'Membuat sukan baru', 'sports'),
('sports.edit', 'Edit Sukan', 'Mengubah maklumat sukan', 'sports'),
('sports.delete', 'Padam Sukan', 'Memadam sukan', 'sports'),
('results.create', 'Cipta Keputusan', 'Memasukkan keputusan', 'results'),
('results.edit', 'Edit Keputusan', 'Mengubah keputusan', 'results'),
('results.delete', 'Padam Keputusan', 'Memadam keputusan', 'results'),
('settings.edit', 'Edit Tetapan', 'Mengubah tetapan sistem', 'settings')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name);

-- ============================================
-- 10. Insert Initial Action Permissions
-- ============================================
INSERT INTO action_permissions (action_code, action_name, description, module, requires_permission) VALUES
('user.create', 'Cipta Pengguna', 'Membuat pengguna baru', 'users', TRUE),
('user.edit', 'Edit Pengguna', 'Mengubah maklumat pengguna', 'users', TRUE),
('user.delete', 'Padam Pengguna', 'Memadam pengguna', 'users', TRUE),
('user.view', 'Lihat Pengguna', 'Melihat senarai pengguna', 'users', FALSE),
('role.create', 'Cipta Peranan', 'Membuat peranan baru', 'rbac', TRUE),
('role.edit', 'Edit Peranan', 'Mengubah peranan', 'rbac', TRUE),
('role.delete', 'Padam Peranan', 'Memadam peranan', 'rbac', TRUE),
('role.assign', 'Tugaskan Peranan', 'Menugaskan peranan kepada pengguna', 'rbac', TRUE),
('contingent.create', 'Cipta Kontinjen', 'Membuat kontinjen baru', 'contingent', TRUE),
('contingent.edit', 'Edit Kontinjen', 'Mengubah maklumat kontinjen', 'contingent', TRUE),
('contingent.delete', 'Padam Kontinjen', 'Memadam kontinjen', 'contingent', TRUE),
('sports.create', 'Cipta Sukan', 'Membuat sukan baru', 'sports', TRUE),
('sports.edit', 'Edit Sukan', 'Mengubah maklumat sukan', 'sports', TRUE),
('sports.delete', 'Padam Sukan', 'Memadam sukan', 'sports', TRUE),
('results.create', 'Cipta Keputusan', 'Memasukkan keputusan', 'results', TRUE),
('results.edit', 'Edit Keputusan', 'Mengubah keputusan', 'results', TRUE),
('results.delete', 'Padam Keputusan', 'Memadam keputusan', 'results', TRUE),
('settings.edit', 'Edit Tetapan', 'Mengubah tetapan sistem', 'settings', TRUE)
ON DUPLICATE KEY UPDATE action_name=VALUES(action_name);

-- ============================================
-- 11. Grant ADMIN role all permissions
-- ============================================
SET @admin_role_id = (SELECT id FROM roles WHERE role_code = 'ADMIN' LIMIT 1);

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT @admin_role_id, p.id
FROM permissions p
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp 
    WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
);

-- ============================================
-- 11. Create indexes for performance
-- ============================================
-- Additional indexes for common queries (safe for MySQL 8)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'user_roles'
      AND index_name = 'idx_user_roles_active'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_user_roles_active ON user_roles(user_id, is_active)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'page_access_rules'
      AND index_name = 'idx_page_access_public'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_page_access_public ON page_access_rules(is_public, requires_auth)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'user_roles'
      AND index_name = 'idx_user_roles_assigned_by'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_user_roles_assigned_by ON user_roles(assigned_by)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'page_access_rules'
      AND index_name = 'idx_page_access_created_by'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_page_access_created_by ON page_access_rules(created_by)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'page_access_rules'
      AND index_name = 'idx_page_access_updated_by'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_page_access_updated_by ON page_access_rules(updated_by)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'page_role_access'
      AND index_name = 'idx_page_role_access_created_by'
);
SET @sql_stmt = IF(@idx_exists = 0, 'CREATE INDEX idx_page_role_access_created_by ON page_role_access(created_by)', 'SELECT 1');
PREPARE stmt FROM @sql_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================
-- Migration Complete
-- ============================================
