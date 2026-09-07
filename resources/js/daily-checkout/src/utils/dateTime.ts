const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

const calendarDate = (value: string): Date => {
  const match = DATE_ONLY_PATTERN.exec(value);
  if (!match) {
    throw new RangeError('Expected a YYYY-MM-DD date-only value.');
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));

  if (
    date.getUTCFullYear() !== year
    || date.getUTCMonth() !== month - 1
    || date.getUTCDate() !== day
  ) {
    throw new RangeError('Invalid date-only value.');
  }

  return date;
};

const timestamp = (value: string): Date => {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) {
    throw new RangeError('Invalid timestamp value.');
  }

  return date;
};

export const formatDateOnly = (value: string): string => (
  new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(calendarDate(value))
);

export const formatTimestampDate = (value: string): string => (
  new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(timestamp(value))
);

export const formatTimestampTime = (value: string): string => (
  new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  }).format(timestamp(value))
);

export const todayDateOnly = (
  instant: Date = new Date(),
  timeZone = 'America/New_York',
): string => {
  if (!Number.isFinite(instant.getTime())) {
    throw new RangeError('Invalid timestamp value.');
  }

  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(instant);
  const part = (type: Intl.DateTimeFormatPartTypes): string => (
    parts.find((entry) => entry.type === type)?.value ?? ''
  );

  return `${part('year')}-${part('month')}-${part('day')}`;
};
