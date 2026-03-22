import { Link } from 'react-router-dom';

export default function FormsHub() {
  return (
    <div>
      <div className="text-center mb-8">
        <h1 className="text-3xl font-bold text-neutral-800 mb-2 font-heading">Forms Hub</h1>
        <p className="text-neutral-500">Select a form to complete</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 stagger-list">
        
        {/* Card A: Big Ticket Item Request */}
        <Link
          to="/forms-hub/big-ticket-request"
          className="group bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 border-l-4 border-l-orange-500 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="w-14 h-14 bg-orange-50 rounded-xl flex items-center justify-center group-hover:bg-orange-100 transition-colors">
                <svg className="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="text-xl font-semibold text-neutral-800 mb-2 font-heading">Big Ticket Item Request</h2>
              <p className="text-neutral-500 text-sm mb-4">
                Request large items like appliances, mattresses, or furniture for a specific station room.
              </p>
              <span className="inline-flex items-center text-orange-600 font-medium group-hover:text-orange-700">
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
          className="group bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 border-l-4 border-l-teal-500 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="w-14 h-14 bg-teal-50 rounded-xl flex items-center justify-center group-hover:bg-teal-100 transition-colors">
                <svg className="w-8 h-8 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="text-xl font-semibold text-neutral-800 mb-2 font-heading">Station Inventory Form</h2>
              <p className="text-neutral-500 text-sm mb-4">
                Complete station supply inventory across all categories and generate a PDF request.
              </p>
              <span className="inline-flex items-center text-teal-600 font-medium group-hover:text-teal-700">
                Start Inventory
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>
        
        {/* Card C: Fire Equipment Request */}
        <Link
          to="/forms-hub/equipment-request"
          className="group bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 border-l-4 border-l-red-500 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="w-14 h-14 bg-red-50 rounded-xl flex items-center justify-center group-hover:bg-red-100 transition-colors">
                <svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="text-xl font-semibold text-neutral-800 mb-2 font-heading">Fire Equipment Request</h2>
              <p className="text-neutral-500 text-sm mb-4">
                Request SCBA, hose, PPE, tools, or other fire equipment for your station.
              </p>
              <span className="inline-flex items-center text-red-600 font-medium group-hover:text-red-700">
                Start Request
                <svg className="w-4 h-4 ml-1 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </span>
            </div>
          </div>
        </Link>

        {/* Card D: Station Inspection */}
        <Link
          to="/forms-hub/station-inspection"
          className="group bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 border-l-4 border-l-sky-500 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5"
        >
          <div className="flex items-start space-x-4">
            <div className="flex-shrink-0">
              <div className="w-14 h-14 bg-sky-50 rounded-xl flex items-center justify-center group-hover:bg-sky-100 transition-colors">
                <svg className="w-8 h-8 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <div className="flex-1">
              <h2 className="text-xl font-semibold text-neutral-800 mb-2 font-heading">Station Inspection</h2>
              <p className="text-neutral-500 text-sm mb-4">
                Complete safety, compliance, and facility inspection checklists for any station.
              </p>
              <span className="inline-flex items-center text-sky-600 font-medium group-hover:text-sky-700">
                Start Inspection
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
        <Link
          to="/"
          className="inline-flex items-center px-4 py-2 text-neutral-500 hover:text-neutral-800"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to MBFD Forms
        </Link>
      </div>
    </div>
  );
}