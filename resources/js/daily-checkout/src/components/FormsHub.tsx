import { Link } from 'react-router';
import PreviousPageButton from './PreviousPageButton';

export default function FormsHub() {
  return (
    <div className="mx-auto max-w-6xl">
      <div className="mb-8 border-l-4 border-red-600 pl-4">
        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-blue-800">Operational forms</p>
        <h1 className="mb-2 text-3xl font-bold text-slate-950 font-heading">Forms Hub</h1>
        <p className="text-slate-600">Select the workflow that matches the work you need to complete.</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 stagger-list">
        
        {/* One authoritative station repair / service / equipment request */}
        <Link
          to="/forms-hub/station-request"
          className="group rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="flex h-14 w-14 items-center justify-center rounded-lg bg-blue-50 text-blue-800 transition-colors group-hover:bg-blue-100">
                <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="mb-2 text-xl font-semibold text-slate-950 font-heading">Station Request</h2>
              <p className="mb-4 text-sm text-slate-600">
                Request a station repair, service visit, room asset, or operational equipment from one tracked workflow.
              </p>
              <span className="inline-flex min-h-11 items-center font-semibold text-red-700 group-hover:text-red-800">
                Start Request
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>

        {/* Card B: Station Inventory Form */}
        <Link
          to="/forms-hub/station-inventory"
          className="group rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="flex h-14 w-14 items-center justify-center rounded-lg bg-blue-50 text-blue-800 transition-colors group-hover:bg-blue-100">
                <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="mb-2 text-xl font-semibold text-slate-950 font-heading">Station Inventory Form</h2>
              <p className="mb-4 text-sm text-slate-600">
                Complete station supply inventory across all categories and generate a PDF request.
              </p>
              <span className="inline-flex min-h-11 items-center font-semibold text-red-700 group-hover:text-red-800">
                Start Inventory
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>
        
        {/* Station Inspection */}
        <Link
          to="/forms-hub/station-inspection"
          className="group rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="flex h-14 w-14 items-center justify-center rounded-lg bg-blue-50 text-blue-800 transition-colors group-hover:bg-blue-100">
                <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="mb-2 text-xl font-semibold text-slate-950 font-heading">Station Inspection</h2>
              <p className="mb-4 text-sm text-slate-600">
                Complete safety, compliance, and facility inspection checklists for any station.
              </p>
              <span className="inline-flex min-h-11 items-center font-semibold text-red-700 group-hover:text-red-800">
                Start Inspection
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>

        {/* Card E: TRT Trailer Inventory */}
        <Link
          to="/forms-hub/trt-inventory"
          className="group rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="flex h-14 w-14 items-center justify-center rounded-lg bg-blue-50 text-blue-800 transition-colors group-hover:bg-blue-100">
                <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="mb-2 text-xl font-semibold text-slate-950 font-heading">TRT Trailer Inventory</h2>
              <p className="mb-4 text-sm text-slate-600">
                Complete the TRT trailer equipment inventory checklist collaboratively.
              </p>
              <span className="inline-flex min-h-11 items-center font-semibold text-red-700 group-hover:text-red-800">
                Start Inventory
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>
      </div>

      {/* Back to Home */}
      <div className="mt-8 text-center">
        <PreviousPageButton
          className="inline-flex min-h-11 items-center px-4 py-2 font-semibold text-slate-600 hover:text-slate-950"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to previous page
        </PreviousPageButton>
      </div>
    </div>
  );
}
