import React from 'react';

const shimmer = 'skeleton';

export function ApparatusListSkeleton() {
  return (
    <div className="space-y-3 p-4">
      <div className={`h-8 w-2/3 ${shimmer}`} />
      {[1,2,3,4].map(i => (
        <div key={i} className={`h-16 w-full ${shimmer}`} />
      ))}
    </div>
  );
}

export function CompartmentSkeleton() {
  return (
    <div className="space-y-4 p-4">
      <div className={`h-6 w-1/2 ${shimmer}`} />
      <div className={`h-2 w-full ${shimmer}`} />
      {[1,2,3].map(i => (
        <div key={i} className="flex items-center gap-3">
          <div className={`h-11 w-11 ${shimmer}`} />
          <div className={`h-6 flex-1 ${shimmer}`} />
          <div className={`h-11 w-28 ${shimmer}`} />
        </div>
      ))}
    </div>
  );
}

export function GenericSkeleton({ lines = 3 }: { lines?: number }) {
  return (
    <div className="space-y-3 p-4">
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className={`h-5 ${shimmer}`} style={{ width: `${60 + Math.random() * 40}%` }} />
      ))}
    </div>
  );
}
