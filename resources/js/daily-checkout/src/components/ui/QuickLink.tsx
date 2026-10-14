import type { ReactNode } from 'react';
import { Link } from 'react-router';

export interface QuickLinkProps {
  label: string;
  icon: ReactNode;
  /** In-app destination (react-router path without the /daily basename). */
  to?: string;
  /** Destination outside Daily (Employee portal, video conferencing). */
  href?: string;
  /** One primary action per page; every other quick link is an ordinary action-tint link. */
  emphasis?: boolean;
}

const BASE = 'flex min-h-12 items-center gap-2.5 rounded-hub-control px-3 py-2.5 text-sm font-semibold transition-colors';
const ORDINARY = `${BASE} border border-hub-blue/30 bg-hub-well text-hub-blue hover:bg-hub-blue-tint`;
const PRIMARY = `${BASE} border border-hub-blue bg-hub-blue text-white hover:bg-hub-blue-hover`;

/** Station quick link: 48px tall, shared focus outline from the Hub scope, icon hidden from assistive tech. */
export default function QuickLink({ label, icon, to, href, emphasis = false }: QuickLinkProps) {
  const className = emphasis ? PRIMARY : ORDINARY;
  const content = <><span className="flex h-5 w-5 flex-none items-center justify-center" aria-hidden="true">{icon}</span><span className="min-w-0">{label}</span></>;

  return to
    ? <Link to={to} className={className}>{content}</Link>
    : <a href={href} className={className}>{content}</a>;
}
