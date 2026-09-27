-- Migration: Add kategori_id to table_pasukan_atlet
-- SAM 2026 - Link athletes to categories
-- This migration adds the kategori_id column to table_pasukan_atlet if it doesn't exist

USE esportsdb;

-- Check if kategori_id column exists, if not add it
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_pasukan_atlet' 
AND COLUMN_NAME = 'kategori_id';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE table_pasukan_atlet 
     ADD COLUMN kategori_id INT NULL COMMENT ''Category ID - links to table_kategori'' AFTER no_matrik,
     ADD INDEX idx_kategori_id (kategori_id),
     ADD FOREIGN KEY (kategori_id) REFERENCES table_kategori(id) ON DELETE SET NULL',
    'SELECT ''Column kategori_id already exists in table_pasukan_atlet'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration completed successfully' AS message;

