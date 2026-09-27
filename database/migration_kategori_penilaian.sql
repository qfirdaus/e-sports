-- Migration: Add penilaian field to table_kategori
-- SAM 2026 - Category Evaluation Type
-- This migration adds a field to indicate whether evaluation is group (berkumpulan) or individual (individu)

USE esportsdb;

-- Add penilaian field to table_kategori
ALTER TABLE table_kategori 
ADD COLUMN penilaian ENUM('berkumpulan', 'individu') NULL DEFAULT NULL COMMENT 'Jenis penilaian: berkumpulan (group) atau individu (individual)' AFTER keterangan;

-- Add index for better query performance
ALTER TABLE table_kategori 
ADD INDEX idx_penilaian (penilaian);

