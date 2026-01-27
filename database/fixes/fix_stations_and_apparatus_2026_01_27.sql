-- Fix Script: Station Addresses and Apparatus Data
-- Date: 2026-01-27
-- Author: Automated Fix
-- Description: Corrects station addresses and apparatus assignments/designations

-- =============================================
-- STATION ADDRESS FIXES
-- =============================================
UPDATE stations SET address = '1051 Jefferson Ave.', zip_code = '33139', phone = '305.673.7135' WHERE station_number = '1';
UPDATE stations SET address = '2300 Pine Tree Dr.', zip_code = '33140', phone = '305.673.7171' WHERE station_number = '2';
UPDATE stations SET address = '5303 Collins Ave.', zip_code = '33140', phone = '305.673.7179' WHERE station_number = '3';
UPDATE stations SET address = '6880 Indian Creek Dr.', zip_code = '33141', phone = '305.673.7136' WHERE station_number = '4';

-- =============================================
-- APPARATUS DESIGNATION FIXES
-- =============================================
UPDATE apparatuses SET designation = 'E 1', class_description = 'ENGINE' WHERE unit_id = 'E1';
UPDATE apparatuses SET designation = 'E 2', class_description = 'ENGINE' WHERE unit_id = 'E2';
UPDATE apparatuses SET designation = 'E 3', class_description = 'ENGINE' WHERE unit_id = 'E3';
UPDATE apparatuses SET designation = 'E 4', class_description = 'ENGINE' WHERE unit_id = 'E4';
UPDATE apparatuses SET designation = 'E 11', class_description = 'ENGINE' WHERE unit_id = 'E11';
UPDATE apparatuses SET designation = 'E 21', class_description = 'ENGINE' WHERE unit_id = 'E21';
UPDATE apparatuses SET designation = 'E 31', class_description = 'ENGINE' WHERE unit_id = 'E31';
UPDATE apparatuses SET designation = 'L 1', class_description = 'LADDER' WHERE unit_id = 'L1';
UPDATE apparatuses SET designation = 'L 3', class_description = 'LADDER' WHERE unit_id = 'L3';
UPDATE apparatuses SET designation = 'L 11', class_description = 'LADDER' WHERE unit_id = 'L11';
UPDATE apparatuses SET designation = 'R 1', class_description = 'RESCUE' WHERE unit_id = 'R1';
UPDATE apparatuses SET designation = 'R 2', class_description = 'RESCUE' WHERE unit_id = 'R2';
UPDATE apparatuses SET designation = 'R 3', class_description = 'RESCUE' WHERE unit_id = 'R3';
UPDATE apparatuses SET designation = 'R 4', class_description = 'RESCUE' WHERE unit_id = 'R4';
UPDATE apparatuses SET designation = 'R 11', class_description = 'RESCUE' WHERE unit_id = 'R11';
UPDATE apparatuses SET designation = 'R 22', class_description = 'RESCUE' WHERE unit_id = 'R22';
UPDATE apparatuses SET designation = 'R 44', class_description = 'RESCUE' WHERE unit_id = 'R44';
UPDATE apparatuses SET designation = 'A 1', class_description = 'AIR TRUCK' WHERE unit_id = 'A1';
UPDATE apparatuses SET designation = 'A 2', class_description = 'AIR TRUCK' WHERE unit_id = 'A2';

-- =============================================
-- APPARATUS ASSIGNMENT FIXES (Front-line units)
-- =============================================
UPDATE apparatuses SET assignment = 'Station 1' WHERE unit_id IN ('E1', 'L1', 'R1', 'R11');
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id IN ('E2', 'R2', 'R22', 'A1', 'A2');
UPDATE apparatuses SET assignment = 'Station 3' WHERE unit_id IN ('E3', 'L3', 'R3');
UPDATE apparatuses SET assignment = 'Station 4' WHERE unit_id IN ('E4', 'R4', 'R44');
UPDATE apparatuses SET assignment = 'Reserve' WHERE unit_id IN ('E11', 'E21', 'E31', 'L11', 'R-1033', 'R-1034', 'R-1035', 'R-1036', 'R-14500', 'R-14501');
