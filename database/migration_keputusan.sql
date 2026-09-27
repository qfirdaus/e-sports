-- Migration: Create/Update table_results for Keputusan (Results) Management
-- SAM 2026 - Results Recording System
-- This migration creates or updates the table_results table to support recording game results

USE esportsdb;

-- Create table_results if it doesn't exist
CREATE TABLE IF NOT EXISTS table_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sukan_id INT NOT NULL,
    kategori_id INT NULL COMMENT 'Category ID - links to table_kategori',
    tarikh DATE NOT NULL COMMENT 'Date of the game/event',
    tempat_pertama VARCHAR(255) NULL COMMENT 'First place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)',
    tempat_kedua VARCHAR(255) NULL COMMENT 'Second place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)',
    tempat_ketiga VARCHAR(255) NULL COMMENT 'Third place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)',
    status ENUM('completed', 'ongoing', 'upcoming') DEFAULT 'completed' COMMENT 'Status of the result',
    
    -- Audit fields
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    deleted_at DATETIME NULL,
    
    -- Indexes
    INDEX idx_sukan_id (sukan_id),
    INDEX idx_kategori_id (kategori_id),
    INDEX idx_tarikh (tarikh),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign keys
    FOREIGN KEY (sukan_id) REFERENCES table_sukan(id) ON DELETE RESTRICT,
    FOREIGN KEY (kategori_id) REFERENCES table_kategori(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add kategori_id column if it doesn't exist (for existing installations)
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'kategori_id';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE table_results ADD COLUMN kategori_id INT NULL COMMENT ''Category ID - links to table_kategori'' AFTER sukan_id,
     ADD INDEX idx_kategori_id (kategori_id),
     ADD FOREIGN KEY (kategori_id) REFERENCES table_kategori(id) ON DELETE SET NULL',
    'SELECT ''Column kategori_id already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add status column if it doesn't exist
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'status';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE table_results ADD COLUMN status ENUM(''completed'', ''ongoing'', ''upcoming'') DEFAULT ''completed'' COMMENT ''Status of the result'' AFTER tempat_ketiga,
     ADD INDEX idx_status (status)',
    'SELECT ''Column status already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure audit fields exist
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'created_by';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE table_results 
     ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
     ADD COLUMN created_by INT NULL AFTER created_at,
     ADD COLUMN updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_by,
     ADD COLUMN updated_by INT NULL AFTER updated_at,
     ADD COLUMN deleted_at DATETIME NULL AFTER updated_by,
     ADD INDEX idx_created_at (created_at),
     ADD INDEX idx_deleted_at (deleted_at),
     ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
     ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT ''Audit fields already exist'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure tarikh column is DATE type (not DATETIME)
SET @col_type = '';
SELECT DATA_TYPE INTO @col_type 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'tarikh';

SET @sql = IF(@col_type = 'datetime' OR @col_type = 'timestamp',
    'ALTER TABLE table_results MODIFY COLUMN tarikh DATE NOT NULL COMMENT ''Date of the game/event''',
    'SELECT ''Column tarikh is already DATE type or does not exist'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure tempat_pertama, tempat_kedua, tempat_ketiga are VARCHAR(255) to support both atlet IDs and pasukan IDs
ALTER TABLE table_results 
MODIFY COLUMN tempat_pertama VARCHAR(255) NULL COMMENT 'First place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)',
MODIFY COLUMN tempat_kedua VARCHAR(255) NULL COMMENT 'Second place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)',
MODIFY COLUMN tempat_ketiga VARCHAR(255) NULL COMMENT 'Third place - stores atlet ID (for individu) or pasukan ID (for berkumpulan)';

-- Add unique constraint to prevent duplicate results for same kategori and date
-- (Only if not already exists)
SET @constraint_exists = 0;
SELECT COUNT(*) INTO @constraint_exists 
FROM information_schema.TABLE_CONSTRAINTS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND CONSTRAINT_NAME = 'unique_kategori_tarikh';

SET @sql = IF(@constraint_exists = 0 AND 
    (SELECT COUNT(*) FROM information_schema.COLUMNS 
     WHERE TABLE_SCHEMA = 'esportsdb' 
     AND TABLE_NAME = 'table_results' 
     AND COLUMN_NAME = 'kategori_id') > 0,
    'ALTER TABLE table_results ADD UNIQUE KEY unique_kategori_tarikh (kategori_id, tarikh, deleted_at)',
    'SELECT ''Constraint already exists or kategori_id column missing'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration completed successfully' AS message;

