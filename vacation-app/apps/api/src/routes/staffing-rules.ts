import { staffingRules, staffingRulesAudit } from '@mbfd-vacation/db';
import {
  DEFAULT_STAFFING_RULES,
  StaffingRulesSchema,
  type StaffingRules,
} from '@mbfd-vacation/shared';
import { desc, eq } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db';

export const staffingRulesRoute = new Hono();

/**
 * Load the singleton staffing-rules row, falling back to the hard-coded
 * defaults if the table is somehow empty (e.g. fresh DB before
 * migration completes).
 */
export async function loadStaffingRules(): Promise<{
  rules: StaffingRules;
  updatedAt: string | null;
}> {
  const [row] = await db
    .select({ rulesJson: staffingRules.rulesJson, updatedAt: staffingRules.updatedAt })
    .from(staffingRules)
    .where(eq(staffingRules.singleton, true))
    .limit(1);
  if (!row) {
    return { rules: DEFAULT_STAFFING_RULES, updatedAt: null };
  }
  const parsed = StaffingRulesSchema.safeParse(row.rulesJson);
  if (!parsed.success) {
    // The blob is broken — fall back to defaults so the engine stays
    // online, but log loudly so an admin notices.
    console.warn('staffing_rules row failed validation; using defaults', parsed.error.flatten());
    return { rules: DEFAULT_STAFFING_RULES, updatedAt: row.updatedAt.toISOString() };
  }
  return { rules: parsed.data, updatedAt: row.updatedAt.toISOString() };
}

staffingRulesRoute.get('/staffing-rules', async (c) => {
  const { rules, updatedAt } = await loadStaffingRules();
  return c.json({ rules, updatedAt });
});

staffingRulesRoute.put('/staffing-rules', async (c) => {
  const body = await c.req.json().catch(() => null);
  const parsed = StaffingRulesSchema.safeParse(body);
  if (!parsed.success) {
    return c.json({ error: 'invalid_rules', issues: parsed.error.flatten() }, 400);
  }
  const newRules = parsed.data;

  const result = await db.transaction(async (tx) => {
    const [prev] = await tx
      .select({
        id: staffingRules.id,
        rulesJson: staffingRules.rulesJson,
      })
      .from(staffingRules)
      .where(eq(staffingRules.singleton, true))
      .limit(1);

    let updatedAt: Date;
    if (prev) {
      const [row] = await tx
        .update(staffingRules)
        .set({ rulesJson: newRules, updatedAt: new Date() })
        .where(eq(staffingRules.id, prev.id))
        .returning({ updatedAt: staffingRules.updatedAt });
      updatedAt = row?.updatedAt ?? new Date();
    } else {
      const [row] = await tx
        .insert(staffingRules)
        .values({ rulesJson: newRules })
        .returning({ updatedAt: staffingRules.updatedAt });
      updatedAt = row?.updatedAt ?? new Date();
    }

    await tx.insert(staffingRulesAudit).values({
      previousRulesJson: prev?.rulesJson ?? null,
      newRulesJson: newRules,
    });

    return { updatedAt };
  });

  return c.json({ rules: newRules, updatedAt: result.updatedAt.toISOString() });
});

/** Last 20 audit entries — drives the "history" pane on the rules page. */
staffingRulesRoute.get('/staffing-rules/audit', async (c) => {
  const rows = await db
    .select({
      id: staffingRulesAudit.id,
      changedAt: staffingRulesAudit.changedAt,
      previousRulesJson: staffingRulesAudit.previousRulesJson,
      newRulesJson: staffingRulesAudit.newRulesJson,
    })
    .from(staffingRulesAudit)
    .orderBy(desc(staffingRulesAudit.changedAt))
    .limit(20);
  return c.json({
    entries: rows.map((r) => ({
      id: r.id,
      changedAt: r.changedAt.toISOString(),
      previousRules: r.previousRulesJson,
      newRules: r.newRulesJson,
    })),
  });
});
