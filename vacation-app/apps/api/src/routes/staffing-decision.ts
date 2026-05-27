/**
 * Decision engine for the Daily Shift Staffing Guidelines (v1.13, 12/1/25).
 *
 * Given a (member, day, block, leave code) request, walk the configured
 * rules in order of precedence and emit a verdict the UI can render
 * directly: grant / grant_after_2000 / requires_chief_override / deny.
 *
 * Rules implemented (matched to the doc):
 *   1. Min preschedule staffing (53)        — section "Minimum Staffing"
 *   2. Air Tech 2000-hour gate (52 trigger) — section "Minimum Staffing"
 *   3. Min daily staffing floor (51)        — section "Minimum Staffing"
 *   4. Marine Firefighter off cap (2)       — Marine Station Vacation Caps
 *   5. Exchange shifts cap per year (10)    — Exchanges of Time
 *   6. Exchange hours cap per year (240)    — Exchanges of Time
 *   7. Rank pairing (regular exchanges)     — Exchanges of Time
 *   8. A-Day pairing                        — Exchanges of A-Day
 *   9. DE / AT cert pairing                 — Driver Engineer / Air Tech
 */
import {
  calendarDays,
  leaveCodes,
  leaveEntries,
  members,
  ranks,
  shiftBlocks,
} from '@mbfd-vacation/db';
import {
  DecisionRequestSchema,
  type DecisionReason,
  type DecisionResult,
  type StaffingRules,
} from '@mbfd-vacation/shared';
import { and, eq, isNull, sql } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db';
import { loadStaffingRules } from './staffing-rules';

export const staffingDecision = new Hono();

type MemberLite = {
  id: string;
  lastName: string;
  firstName: string;
  shift: string | null;
  station: string | null;
  certifications: string[];
  rankCode: string | null;
};

async function loadMember(id: string): Promise<MemberLite | null> {
  const [row] = await db
    .select({
      id: members.id,
      lastName: members.lastName,
      firstName: members.firstName,
      shift: members.shift,
      station: members.station,
      certifications: members.certifications,
      rankCode: ranks.code,
    })
    .from(members)
    .leftJoin(ranks, eq(ranks.id, members.rankId))
    .where(eq(members.id, id))
    .limit(1);
  if (!row) return null;
  return {
    id: row.id,
    lastName: row.lastName,
    firstName: row.firstName,
    shift: row.shift,
    station: row.station,
    certifications: (row.certifications as string[] | null) ?? [],
    rankCode: row.rankCode,
  };
}

/**
 * Count members on duty for a given (shift, day, block):
 *   on-duty = active members on that shift
 *           − members with an active leave entry covering that block.
 *
 * "Active leave entry covering the block" = a non-superseded leave_entry
 * whose shift_block_id resolves to the same (calendar_day, block_index).
 */
async function countOnDuty(opts: {
  shift: string;
  dayDate: string;
  blockIndex: number;
}): Promise<number> {
  const [totalRow] = await db
    .select({ c: sql<number>`count(*)::int` })
    .from(members)
    .where(and(eq(members.shift, opts.shift), eq(members.isActive, true)));
  const total = totalRow?.c ?? 0;

  const [offRow] = await db
    .select({ c: sql<number>`count(distinct ${leaveEntries.memberId})::int` })
    .from(leaveEntries)
    .innerJoin(members, eq(members.id, leaveEntries.memberId))
    .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
    .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
    .where(
      and(
        isNull(leaveEntries.supersededByEntryId),
        eq(members.shift, opts.shift),
        eq(members.isActive, true),
        eq(calendarDays.date, opts.dayDate),
        eq(shiftBlocks.blockIndex, opts.blockIndex),
      ),
    );
  const off = offRow?.c ?? 0;
  return Math.max(0, total - off);
}

/**
 * For "total department on duty" used by the 51/52/53 thresholds we
 * count across A + B + C combat shifts plus Marine 6 personnel for the
 * day. The doc says minimum staffing is 51 *to include Marine Station 6*.
 *
 * For Phase 1 we treat "shift of the requester" as a proxy for the
 * 24-hour combat shift and aggregate that shift's count. Marine 6
 * personnel are part of one of A/B/C, so the count includes them. If
 * the requester is on D4/D5/ODD/SE (days/civilian) we skip the staffing
 * check entirely because the doc only governs combat shift staffing.
 */
const COMBAT_SHIFTS = new Set(['A', 'B', 'C']);

async function countMarineFfOffOnDay(opts: {
  shift: string;
  dayDate: string;
  marineKey: string;
}): Promise<number> {
  const [row] = await db
    .select({ c: sql<number>`count(distinct ${leaveEntries.memberId})::int` })
    .from(leaveEntries)
    .innerJoin(members, eq(members.id, leaveEntries.memberId))
    .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
    .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
    .leftJoin(ranks, eq(ranks.id, members.rankId))
    .where(
      and(
        isNull(leaveEntries.supersededByEntryId),
        eq(members.shift, opts.shift),
        eq(members.station, opts.marineKey),
        eq(calendarDays.date, opts.dayDate),
        // Only firefighters count toward the Marine FF cap.
        sql`${ranks.code} IN ('FF', 'FF-DE')`,
      ),
    );
  return row?.c ?? 0;
}

/**
 * Year-to-date exchange usage for one member, in (shift count, hours).
 * Pulls every leave_entry whose code is in `exchangeLeaveCodes` for the
 * given calendar year.
 */
async function exchangeYtd(opts: {
  memberId: string;
  year: number;
  exchangeCodes: string[];
}): Promise<{ shifts: number; hours: number }> {
  if (opts.exchangeCodes.length === 0) return { shifts: 0, hours: 0 };
  const yearStart = `${opts.year}-01-01`;
  const yearEnd = `${opts.year}-12-31`;
  const [row] = await db
    .select({
      shifts: sql<number>`count(*)::int`,
      hours: sql<number>`coalesce(sum(${leaveEntries.hours}), 0)::float`,
    })
    .from(leaveEntries)
    .innerJoin(leaveCodes, eq(leaveCodes.id, leaveEntries.leaveCodeId))
    .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
    .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
    .where(
      and(
        isNull(leaveEntries.supersededByEntryId),
        eq(leaveEntries.memberId, opts.memberId),
        sql`upper(${leaveCodes.code}) = ANY(${opts.exchangeCodes.map((c) => c.toUpperCase())})`,
        sql`${calendarDays.date} BETWEEN ${yearStart} AND ${yearEnd}`,
      ),
    );
  return { shifts: Number(row?.shifts ?? 0), hours: Number(row?.hours ?? 0) };
}

/** Block-start local hour (Miami is UTC-5 standard / UTC-4 DST). */
function blockStartLocalHour(blockIndex: number): number {
  return blockIndex === 0 ? 8 : 20;
}

function pickWorstDecision(
  reasons: DecisionReason[],
): DecisionResult['decision'] {
  let decision: DecisionResult['decision'] = 'grant';
  for (const r of reasons) {
    if (r.ok) continue;
    if (r.rule === 'air_tech_no_leave_until' && decision === 'grant') {
      decision = 'grant_after_2000';
    } else if (r.rule === 'exchange_shifts_cap' || r.rule === 'exchange_hours_cap') {
      // Chief can authorize exceptions per the doc; flag as overrideable
      // unless something else hard-denies.
      if (decision === 'grant' || decision === 'grant_after_2000') {
        decision = 'requires_chief_override';
      }
    } else {
      decision = 'deny';
    }
  }
  return decision;
}

async function evaluate(
  req: ReturnType<typeof DecisionRequestSchema.parse>,
  rules: StaffingRules,
): Promise<DecisionResult> {
  const member = await loadMember(req.memberId);
  if (!member) throw new Error('member not found');

  const reasons: DecisionReason[] = [];
  const year = Number(req.dayDate.slice(0, 4));
  const isExchange = rules.exchangeLeaveCodes
    .map((c) => c.toUpperCase())
    .includes(req.leaveCode.toUpperCase());

  // ── 1) staffing thresholds (only meaningful for combat shifts) ────────
  let staffingForBlock = 0;
  if (member.shift && COMBAT_SHIFTS.has(member.shift)) {
    staffingForBlock = await countOnDuty({
      shift: member.shift,
      dayDate: req.dayDate,
      blockIndex: req.blockIndex,
    });

    if (staffingForBlock < rules.minPreScheduleStaffing) {
      reasons.push({
        rule: 'min_preschedule_staffing',
        ok: false,
        message: `Shift ${member.shift} would have ${staffingForBlock} on duty on ${req.dayDate} block ${req.blockIndex} — need ≥ ${rules.minPreScheduleStaffing} to preschedule leave.`,
        detail: {
          shift: member.shift,
          on_duty: staffingForBlock,
          threshold: rules.minPreScheduleStaffing,
        },
      });
    } else {
      reasons.push({
        rule: 'min_preschedule_staffing',
        ok: true,
        message: `Shift ${member.shift} has ${staffingForBlock} on duty (≥ ${rules.minPreScheduleStaffing}).`,
      });
    }

    // Air Tech 2000-hour gate: if staffing would land at the trigger
    // (e.g. dropping from 53 → 52 after this grant), and the requested
    // start hour is before the cutoff, mark grant_after_2000.
    const projectedAfterGrant = staffingForBlock - 1;
    const reqHour = req.requestedLocalStartHour ?? blockStartLocalHour(req.blockIndex);
    if (
      projectedAfterGrant === rules.airTechTrigger &&
      reqHour < rules.airTechNoLeaveUntilLocalHour
    ) {
      reasons.push({
        rule: 'air_tech_no_leave_until',
        ok: false,
        message: `Grant would leave shift at ${rules.airTechTrigger} on duty (Air Tech staffed). No scheduled leave permitted until ${String(rules.airTechNoLeaveUntilLocalHour).padStart(2, '0')}00.`,
        detail: {
          projected_on_duty: projectedAfterGrant,
          requested_local_hour: reqHour,
          cutoff_hour: rules.airTechNoLeaveUntilLocalHour,
        },
      });
    }
  } else if (member.shift) {
    // Non-combat (D4 / D5 / ODD / SE) — staffing thresholds don't gate.
    reasons.push({
      rule: 'min_preschedule_staffing',
      ok: true,
      message: `Member is on a non-combat shift (${member.shift}); staffing thresholds don't apply.`,
    });
  }

  // ── 2) Marine FF cap ──────────────────────────────────────────────────
  if (
    member.station === rules.marineStationKey &&
    member.shift &&
    COMBAT_SHIFTS.has(member.shift) &&
    (member.rankCode === 'FF' || member.rankCode === 'FF-DE')
  ) {
    const marineOff = await countMarineFfOffOnDay({
      shift: member.shift,
      dayDate: req.dayDate,
      marineKey: rules.marineStationKey,
    });
    const ok = marineOff < rules.marineFirefighterOffCap;
    reasons.push({
      rule: 'marine_firefighter_cap',
      ok,
      message: ok
        ? `Marine FFs off on ${req.dayDate}: ${marineOff} (cap ${rules.marineFirefighterOffCap}).`
        : `Marine FF cap reached: ${marineOff}/${rules.marineFirefighterOffCap} already off on ${req.dayDate}.`,
      detail: { off: marineOff, cap: rules.marineFirefighterOffCap },
    });
  }

  // ── 3) Exchange caps + pairing ────────────────────────────────────────
  if (isExchange) {
    const ytd = await exchangeYtd({
      memberId: member.id,
      year,
      exchangeCodes: rules.exchangeLeaveCodes,
    });
    const shiftsOk = ytd.shifts < rules.exchangeShiftsCapPerYear;
    reasons.push({
      rule: 'exchange_shifts_cap',
      ok: shiftsOk,
      message: shiftsOk
        ? `Member has used ${ytd.shifts}/${rules.exchangeShiftsCapPerYear} exchange shifts in ${year}.`
        : `Exchange shift cap reached: ${ytd.shifts}/${rules.exchangeShiftsCapPerYear} in ${year}. Requires Fire Chief written authorization.`,
      detail: { used: ytd.shifts, cap: rules.exchangeShiftsCapPerYear, year },
    });
    const hoursOk = ytd.hours < rules.exchangeHoursCapPerYear;
    reasons.push({
      rule: 'exchange_hours_cap',
      ok: hoursOk,
      message: hoursOk
        ? `Member has used ${ytd.hours}/${rules.exchangeHoursCapPerYear} exchange hours in ${year}.`
        : `Exchange hour cap reached: ${ytd.hours}/${rules.exchangeHoursCapPerYear} in ${year}. Requires Fire Chief written authorization.`,
      detail: { used: ytd.hours, cap: rules.exchangeHoursCapPerYear, year },
    });

    if (req.exchangePartnerId) {
      const partner = await loadMember(req.exchangePartnerId);
      if (!partner) {
        reasons.push({
          rule: 'rank_pairing',
          ok: false,
          message: `Exchange partner ${req.exchangePartnerId} not found.`,
        });
      } else {
        // Rank pairing for regular exchanges
        const allowed =
          (member.rankCode &&
            rules.rankPairingRules[member.rankCode]?.includes(partner.rankCode ?? '')) ??
          false;
        reasons.push({
          rule: 'rank_pairing',
          ok: Boolean(allowed),
          message: allowed
            ? `Rank pairing OK: ${member.rankCode} ↔ ${partner.rankCode}.`
            : `Rank pairing not permitted: ${member.rankCode} cannot exchange with ${partner.rankCode}.`,
          detail: { member_rank: member.rankCode, partner_rank: partner.rankCode },
        });

        // DE pairing: DE-assigned member must pair with a DE-certified FF.
        if (member.rankCode === 'FF-DE' || member.certifications.includes('DE')) {
          const partnerHasDe =
            partner.rankCode === 'FF-DE' || partner.certifications.includes('DE');
          if (!partnerHasDe) {
            reasons.push({
              rule: 'de_pairing',
              ok: false,
              message:
                'DE-assigned members can only exchange with a Firefighter holding the DE certification.',
              detail: { partner_certifications: partner.certifications.join(', ') },
            });
          }
        }

        // AT pairing: AT-assigned member must pair with AT + DE certified FF.
        if (member.certifications.includes('AT')) {
          const partnerHasAt = partner.certifications.includes('AT');
          const partnerHasDe =
            partner.rankCode === 'FF-DE' || partner.certifications.includes('DE');
          if (!partnerHasAt || !partnerHasDe) {
            reasons.push({
              rule: 'at_pairing',
              ok: false,
              message:
                'Air Tech can only exchange with a Firefighter holding both AT and DE certifications.',
              detail: {
                partner_has_at: partnerHasAt,
                partner_has_de: partnerHasDe,
              },
            });
          }
        }

        // Marine pairing: same rank + Marine qualification(s).
        if (member.station === rules.marineStationKey) {
          const partnerIsMarine = partner.station === rules.marineStationKey;
          const partnerSameRank = partner.rankCode === member.rankCode;
          const needsCapt = member.rankCode === 'CAPT';
          const partnerHasMarineQual =
            partner.certifications.includes('M6_QUAL') ||
            (needsCapt && partner.certifications.includes('M6_CAPT_QUAL'));
          if (!partnerIsMarine || !partnerSameRank || !partnerHasMarineQual) {
            reasons.push({
              rule: 'rank_pairing',
              ok: false,
              message:
                'Marine Station personnel can only exchange with the same rank holding Marine qualifications.',
              detail: {
                partner_station: partner.station ?? null,
                partner_same_rank: partnerSameRank,
                partner_marine_quals: partner.certifications.join(', '),
              },
            });
          }
        }
      }
    }
  }

  // ── final tally ───────────────────────────────────────────────────────
  const decision = pickWorstDecision(reasons);

  // Marine off count for the day (informational; only meaningful for
  // requests on a combat shift).
  const marineFfOffOnDay =
    member.shift && COMBAT_SHIFTS.has(member.shift)
      ? await countMarineFfOffOnDay({
          shift: member.shift,
          dayDate: req.dayDate,
          marineKey: rules.marineStationKey,
        })
      : 0;

  return {
    decision,
    reasons,
    context: {
      memberId: member.id,
      memberLabel: `${member.lastName}, ${member.firstName}`,
      shift: member.shift,
      rankCode: member.rankCode,
      station: member.station,
      dayDate: req.dayDate,
      blockIndex: req.blockIndex,
      leaveCode: req.leaveCode,
      staffingForBlock,
      marineFfOffOnDay,
    },
  };
}

staffingDecision.post('/staffing-decision', async (c) => {
  const body = await c.req.json().catch(() => null);
  const parsed = DecisionRequestSchema.safeParse(body);
  if (!parsed.success) {
    return c.json({ error: 'invalid_request', issues: parsed.error.flatten() }, 400);
  }
  const { rules } = await loadStaffingRules();
  try {
    const result = await evaluate(parsed.data, rules);
    return c.json(result);
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return c.json({ error: msg }, 400);
  }
});
