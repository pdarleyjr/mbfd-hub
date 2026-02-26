import React from 'react';

const pulse = 'animate-pulse bg-slate-700 rounded';

export function ApparatusListSkeleton() {
  return (
    <div className="space-y-3 p-4">
      <div className={`h-8 w-2/3 ${pulse}`} />
      {[1,2,3,4].map(i => (
        <div key={i} className={`h-16 w-full ${pulse}`} />
      ))}
    </div>
  );
}

export function CompartmentSkeleton() {
  return (
    <div className="space-y-4 p-4">
      <div className={`h-6 w-1/2 ${pulse}`} />
      <div className={`h-2 w-full ${pulse}`} />
      {[1,2,3].map(i => (
        <div key={i} className="flex items-center gap-3">
          <div className={`h-11 w-11 ${pulse}`} />
          <div className={`h-6 flex-1 ${pulse}`} />
          <div className={`h-11 w-28 ${pulse}`} />
        </div>
      ))}
    </div>
  );
}
