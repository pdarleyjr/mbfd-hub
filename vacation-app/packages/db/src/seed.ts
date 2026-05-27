import { getDb } from './client';
import { leaveCodes, ranks } from './schema/index';

const connectionString = process.env.DATABASE_URL ?? process.env.DATABASE_URL_HOST;
if (!connectionString) {
  console.error('DATABASE_URL or DATABASE_URL_HOST must be set');
  process.exit(1);
}

const { db, close } = getDb(connectionString);

type RankSeed = { code: string; label: string; sortOrder: number; isOfficer: boolean };
type LeaveCodeSeed = {
  code: string;
  label: string;
  uiColor: string;
  countsAgainstVacationBalance: boolean;
  countsAgainstFloatingBalance: boolean;
  countsAgainstDailyVacationCapacity: boolean;
  countsAgainstMinimumStaffing: boolean;
  isADayMarker: boolean;
};

const rankSeeds: RankSeed[] = [
  { code: 'DC', label: 'Division Chief', sortOrder: 1, isOfficer: true },
  { code: 'CAPT', label: 'Captain', sortOrder: 2, isOfficer: true },
  { code: 'LT', label: 'Lieutenant', sortOrder: 3, isOfficer: true },
  { code: 'FF', label: 'Firefighter', sortOrder: 4, isOfficer: false },
  { code: 'PROB', label: 'Probationary', sortOrder: 5, isOfficer: false },
];

const leaveCodeSeeds: LeaveCodeSeed[] = [
  // Vacation family
  { code: 'V',   label: 'Vacation',              uiColor: '#B91C1C', countsAgainstVacationBalance: true,  countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  { code: 'VP',  label: 'Vacation Prescheduled', uiColor: '#B91C1C', countsAgainstVacationBalance: true,  countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  { code: 'EV',  label: 'Emergency Vacation',    uiColor: '#B91C1C', countsAgainstVacationBalance: true,  countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  // Holiday family
  { code: 'FH',  label: 'Floating Holiday',      uiColor: '#D97706', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: true,  countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  { code: 'F',   label: 'Birthday / FML Float',  uiColor: '#D97706', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: true,  countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  { code: 'AH',  label: 'Alternate Holiday',     uiColor: '#D97706', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: true,  countsAgainstDailyVacationCapacity: true,  countsAgainstMinimumStaffing: true,  isADayMarker: false },
  { code: 'EF',  label: 'Emergency Floater',     uiColor: '#A16207', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: true,  countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: true,  isADayMarker: false },
  // A-day / R-day
  { code: 'A',   label: 'A-Day / R-Day',         uiColor: '#0369A1', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: true  },
  // Sick
  { code: 'S',   label: 'Sick',                  uiColor: '#374151', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'SIC', label: 'Sick (Telestaff)',      uiColor: '#374151', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  // Operational / assignment
  { code: 'HO',  label: 'Holiday Off',           uiColor: '#16A34A', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'XOFF',label: 'Exchange Off',          uiColor: '#7C3AED', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'EON', label: 'Exchange On',           uiColor: '#16A34A', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'OOC', label: 'Out of Class',          uiColor: '#A8A29E', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'MOC', label: 'Medic OOC',             uiColor: '#A8A29E', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'ROC', label: 'Rescue OOC',            uiColor: '#A8A29E', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
  { code: 'TOC', label: 'Training OOC',          uiColor: '#A8A29E', countsAgainstVacationBalance: false, countsAgainstFloatingBalance: false, countsAgainstDailyVacationCapacity: false, countsAgainstMinimumStaffing: false, isADayMarker: false },
];

try {
  console.log('Seeding ranks…');
  for (const r of rankSeeds) {
    await db.insert(ranks).values(r).onConflictDoNothing({ target: ranks.code });
  }

  console.log('Seeding leave codes…');
  for (const c of leaveCodeSeeds) {
    await db.insert(leaveCodes).values(c).onConflictDoNothing({ target: leaveCodes.code });
  }

  console.log('Seed complete.');
} catch (err) {
  console.error('Seed failed:', err);
  process.exitCode = 1;
} finally {
  await close();
}
