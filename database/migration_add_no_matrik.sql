-- Migration: Add no_matrik field to table_pasukan_atlet
-- SAM 2026 - Add matriculation number field for athletes

USE esportsdb;

-- Add no_matrik column to table_pasukan_atlet
ALTER TABLE table_pasukan_atlet 
ADD COLUMN no_matrik VARCHAR(50) NULL AFTER no_kad_pengenalan,
ADD INDEX idx_no_matrik (no_matrik);

