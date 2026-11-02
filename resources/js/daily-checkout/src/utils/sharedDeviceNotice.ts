import { HUB_TIMEZONE } from '../../../shared/timezone.ts';
import type { OtherMemberQueuedCheckout } from './dailyCheckoutSubmissionQueue';

const savedOnDeviceAt = (value: Date): string => new Intl.DateTimeFormat('en-US', {
  timeZone: HUB_TIMEZONE,
  month: 'short',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
}).format(value);

/**
 * The shared station device notice. Another member's saved checkout for the same
 * truck never blocks the signed-in member; it only tells them it is there and who
 * will send it.
 */
export const otherMembersCheckoutNotice = (other: OtherMemberQueuedCheckout, apparatusName: string): string => {
  const saved = `A checkout for ${apparatusName} saved on this device by ${other.operatorName ?? 'another member'} at ${savedOnDeviceAt(other.createdAt)}`;
  const owner = other.operatorName ?? 'that member';
  if (!other.hasOwner) return `${saved} needs review by an officer. You can still complete your own checkout.`;
  if (other.status === 'requires_attention') return `${saved} needs review. It stays on this device for ${owner} to review after signing in.`;

  return `${saved} has not been sent yet. It will send when ${owner} signs in on this device.`;
};
