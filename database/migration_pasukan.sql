-- Migration: Create Pasukan (Team) Tables
-- SAM 2026 - Team Management System
-- This migration creates tables for managing teams (pasukan) with managers, coaches, and athletes

USE esportsdb;

-- Main teams table
CREATE TABLE IF NOT EXISTS table_pasukan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kontinjen_id INT NOT NULL,
    sukan_id INT NOT NULL,
    nama_pasukan VARCHAR(200) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=active, 0=inactive',
    
    -- Audit fields
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    deleted_at DATETIME NULL,
    
    -- Indexes
    INDEX idx_kontinjen_id (kontinjen_id),
    INDEX idx_sukan_id (sukan_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign keys
    FOREIGN KEY (kontinjen_id) REFERENCES table_kontinjen(id) ON DELETE RESTRICT,
    FOREIGN KEY (sukan_id) REFERENCES table_sukan(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team managers table
CREATE TABLE IF NOT EXISTS table_pasukan_pengurus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pasukan_id INT NOT NULL,
    nama VARCHAR(200) NOT NULL,
    no_kad_pengenalan VARCHAR(20) NULL,
    no_telefon VARCHAR(20) NULL,
    emel VARCHAR(100) NULL,
    
    -- Audit fields
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    
    -- Indexes
    INDEX idx_pasukan_id (pasukan_id),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign keys
    FOREIGN KEY (pasukan_id) REFERENCES table_pasukan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team coaches table
CREATE TABLE IF NOT EXISTS table_pasukan_jurulatih (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pasukan_id INT NOT NULL,
    nama VARCHAR(200) NOT NULL,
    no_kad_pengenalan VARCHAR(20) NULL,
    no_telefon VARCHAR(20) NULL,
    emel VARCHAR(100) NULL,
    
    -- Audit fields
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    
    -- Indexes
    INDEX idx_pasukan_id (pasukan_id),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign keys
    FOREIGN KEY (pasukan_id) REFERENCES table_pasukan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team athletes table
CREATE TABLE IF NOT EXISTS table_pasukan_atlet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pasukan_id INT NOT NULL,
    nama VARCHAR(200) NOT NULL,
    no_kad_pengenalan VARCHAR(20) NULL,
    
    -- Audit fields
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    
    -- Indexes
    INDEX idx_pasukan_id (pasukan_id),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign keys
    FOREIGN KEY (pasukan_id) REFERENCES table_pasukan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

