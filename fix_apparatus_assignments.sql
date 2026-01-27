-- Fix assignments based on CSV data from 1/23/2026
-- Front Line Station 1
UPDATE apparatuses SET assignment = 'Station 1' WHERE unit_id = 'E1';
UPDATE apparatuses SET assignment = 'Station 1' WHERE unit_id = 'L1';
UPDATE apparatuses SET assignment = 'Station 1' WHERE unit_id = 'R1';
UPDATE apparatuses SET assignment = 'Station 1' WHERE unit_id = 'R11';

-- Front Line Station 2
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id = 'A1';
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id = 'A2';
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id = 'E2';
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id = 'R2';
UPDATE apparatuses SET assignment = 'Station 2' WHERE unit_id = 'R22';

-- Front Line Station 3
UPDATE apparatuses SET assignment = 'Station 3' WHERE unit_id = 'E3';
UPDATE apparatuses SET assignment = 'Station 3' WHERE unit_id = 'L3';
UPDATE apparatuses SET assignment = 'Station 3' WHERE unit_id = 'R3';

-- Front Line Station 4
UPDATE apparatuses SET assignment = 'Station 4' WHERE unit_id = 'E4';
UPDATE apparatuses SET assignment = 'Station 4' WHERE unit_id = 'R4';
UPDATE apparatuses SET assignment = 'Station 4' WHERE unit_id = 'R44';
