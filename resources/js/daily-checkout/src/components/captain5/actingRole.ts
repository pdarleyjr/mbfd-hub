import { captain5ActingApi } from '../../utils/captain5Api';
import type { ActingRole } from '../acting/types';

/** Captain 5 binds the shared acting control and approvals to /api/captain5/acting-requests. No rank on its shift line. */
export const CAPTAIN_5_ACTING: ActingRole = {
  label: 'Captain 5',
  withRank: false,
  testPrefix: 'captain5',
  idPrefix: 'c5',
  api: captain5ActingApi,
};
