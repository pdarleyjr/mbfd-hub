import { command300ActingApi } from '../../utils/command300ShiftApi';
import type { ActingRole } from './types';

/** The 300 (Division Chief) binds the shared acting control and approvals to /api/command-300. Rank is shown. */
export const COMMAND_300_ACTING: ActingRole = {
  label: '300',
  withRank: true,
  testPrefix: 'command',
  idPrefix: 'c300',
  api: command300ActingApi,
};
