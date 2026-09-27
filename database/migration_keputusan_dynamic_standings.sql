-- Migration: Convert table_results to dynamic standings system
-- SAM 2026 - Dynamic Standings Results System
-- This migration converts the fixed 3-place system to dynamic standings based on participant count

USE esportsdb;

-- Step 1: Add standings JSON column
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'standings';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE table_results ADD COLUMN standings JSON NULL COMMENT ''Stores all standings as JSON array: [{"position": 1, "participant_id": "123"}, ...]'' AFTER status',
    'SELECT ''Column standings already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Migrate existing data from tempat_pertama/kedua/ketiga to JSON format
-- This converts existing 3-place records to the new JSON format
UPDATE table_results 
SET standings = JSON_ARRAY(
    IF(tempat_pertama IS NOT NULL AND tempat_pertama != '', 
       JSON_OBJECT('position', 1, 'participant_id', tempat_pertama), 
       NULL),
    IF(tempat_kedua IS NOT NULL AND tempat_kedua != '', 
       JSON_OBJECT('position', 2, 'participant_id', tempat_kedua), 
       NULL),
    IF(tempat_ketiga IS NOT NULL AND tempat_ketiga != '', 
       JSON_OBJECT('position', 3, 'participant_id', tempat_ketiga), 
       NULL)
)
WHERE (tempat_pertama IS NOT NULL AND tempat_pertama != '') 
   OR (tempat_kedua IS NOT NULL AND tempat_kedua != '') 
   OR (tempat_ketiga IS NOT NULL AND tempat_ketiga != '');

-- Remove NULL entries from JSON array
UPDATE table_results 
SET standings = JSON_REMOVE(standings, 
    IF(JSON_EXTRACT(standings, '$[0]') IS NULL, '$[0]', 
    IF(JSON_EXTRACT(standings, '$[1]') IS NULL, '$[1]', 
    IF(JSON_EXTRACT(standings, '$[2]') IS NULL, '$[2]', NULL))))
WHERE standings IS NOT NULL;

-- Step 3: Drop foreign key constraints if they exist (needed before dropping columns)
SET @fk_exists = 0;
SELECT COUNT(*) INTO @fk_exists 
FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME IN ('tempat_pertama', 'tempat_kedua', 'tempat_ketiga')
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Note: The old columns don't have foreign keys, so we can proceed to drop them

-- Step 4: Remove the old columns
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'esportsdb' 
AND TABLE_NAME = 'table_results' 
AND COLUMN_NAME = 'tempat_pertama';

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE table_results 
     DROP COLUMN tempat_pertama,
     DROP COLUMN tempat_kedua,
     DROP COLUMN tempat_ketiga',
    'SELECT ''Columns already removed'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration to dynamic standings completed successfully' AS message;

