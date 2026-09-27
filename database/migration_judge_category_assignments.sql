-- Migration: Create judge_category_assignments table
-- SAM 2026 - Judge Category Restriction Feature
-- This migration creates the table to store judge-to-category assignments

USE esportsdb;

-- Create judge_category_assignments table
CREATE TABLE IF NOT EXISTS judge_category_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'The judge user (FK to users.id)',
    kategori_id INT NOT NULL COMMENT 'The assigned category (FK to table_kategori.id)',
    assigned_by INT NULL COMMENT 'Admin/Organizer who made the assignment (FK to users.id)',
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When assignment was made',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Soft delete flag',
    
    -- Constraints
    UNIQUE KEY unique_user_kategori (user_id, kategori_id),
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_kategori_id (kategori_id),
    INDEX idx_is_active (is_active),
    INDEX idx_assigned_by (assigned_by),
    
    -- Foreign keys
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (kategori_id) REFERENCES table_kategori(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migration completed successfully - judge_category_assignments table created' AS message;

