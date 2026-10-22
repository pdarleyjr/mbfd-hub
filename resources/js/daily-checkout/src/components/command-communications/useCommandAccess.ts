import { useEffect, useState } from 'react';
import { identityForQueueCapture } from '../../lib/offlineIdentity';

/**
 * 'checking' until the server context answers. 'unknown' means the capability could not be read
 * (offline, or an identity cached before the field existed): the pages still load and the
 * server's 403 stays authoritative. Only an explicit false is 'denied'.
 */
export type CommandAccess = 'checking' | 'allowed' | 'denied' | 'unknown';

export function useCommandAccess(): CommandAccess {
  const [access, setAccess] = useState<CommandAccess>('checking');

  useEffect(() => {
    let active = true;
    identityForQueueCapture({ redirectOnExpiry: false })
      .then((identity) => {
        if (!active) return;
        const allowed = identity.capabilities?.commandAccess;
        setAccess(allowed === true ? 'allowed' : allowed === false ? 'denied' : 'unknown');
      })
      .catch(() => { if (active) setAccess('unknown'); });
    return () => { active = false; };
  }, []);

  return access;
}
