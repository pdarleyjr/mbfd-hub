import CommunicationsPanel from '../command-communications/CommunicationsPanel';
import { useCommandCommunications } from '../command-communications/useCommandCommunications';

interface Props {
  /** The server refused the feed: the page switches to its access-denied state. */
  onDenied: () => void;
  /** Opens the Department Updates tab. */
  onOpenUpdates: () => void;
}

/**
 * The Captain 5 channel of the shared 300 + Captain 5 conversation. Mounted only while the Messages
 * tab is open, so the feed polls only while someone is looking at it.
 */
export default function Captain5Messages({ onDenied, onOpenUpdates }: Props) {
  const comms = useCommandCommunications({ active: true, enabled: true, onDenied, channel: 'captain_5' });
  return <CommunicationsPanel comms={comms} channel="captain_5" onOpenUpdates={onOpenUpdates} />;
}
