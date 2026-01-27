-- Fix apparatus data based on CSV from 1/23/2026
-- Fixes designation and class_description columns

-- Front Line Station 1
UPDATE apparatuses SET designation = 'E 1', class_description = 'ENGINE' WHERE unit_id = 'E1';
UPDATE apparatuses SET designation = 'L 1', class_description = 'LADDER' WHERE unit_id = 'L1';
UPDATE apparatuses SET designation = 'R 1', class_description = 'RESCUE' WHERE unit_id = 'R1';
UPDATE apparatuses SET designation = 'R 11', class_description = 'RESCUE' WHERE unit_id = 'R11';

-- Front Line Station 2
UPDATE apparatuses SET designation = 'A 1', class_description = 'AIR TRUCK' WHERE unit_id = 'A1';
UPDATE apparatuses SET designation = 'A 2', class_description = 'AIR TRUCK' WHERE unit_id = 'A2';
UPDATE apparatuses SET designation = 'E 2', class_description = 'ENGINE' WHERE unit_id = 'E2';
UPDATE apparatuses SET designation = 'R 2', class_description = 'RESCUE' WHERE unit_id = 'R2';
UPDATE apparatuses SET designation = 'R 22', class_description = 'RESCUE' WHERE unit_id = 'R22';

-- Front Line Station 3
UPDATE apparatuses SET designation = 'E 3', class_description = 'ENGINE' WHERE unit_id = 'E3';
UPDATE apparatuses SET designation = 'L 3', class_description = 'LADDER' WHERE unit_id = 'L3';
UPDATE apparatuses SET designation = 'R 3', class_description = 'RESCUE' WHERE unit_id = 'R3';

-- Front Line Station 4
UPDATE apparatuses SET designation = 'E 4', class_description = 'ENGINE' WHERE unit_id = 'E4';
UPDATE apparatuses SET designation = 'R 4', class_description = 'RESCUE' WHERE unit_id = 'R4';
UPDATE apparatuses SET designation = 'R 44', class_description = 'RESCUE' WHERE unit_id = 'R44';

-- Reserve Fleet
UPDATE apparatuses SET designation = 'E 11', class_description = 'ENGINE', assignment = 'Reserve' WHERE unit_id = 'E11';
UPDATE apparatuses SET designation = 'E 21', class_description = 'ENGINE', assignment = 'Reserve' WHERE unit_id = 'E21';
UPDATE apparatuses SET designation = 'E 31', class_description = 'ENGINE', assignment = 'Reserve' WHERE unit_id = 'E31';
UPDATE apparatuses SET designation = 'L 11', class_description = 'LADDER', assignment = 'Reserve' WHERE unit_id = 'L11';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-1033';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-1034';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-1035';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-1036';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-14500';
UPDATE apparatuses SET designation = NULL, class_description = 'RESCUE', assignment = 'Reserve' WHERE unit_id = 'R-14501';
