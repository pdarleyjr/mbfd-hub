import { Link } from 'react-router-dom';
import { useState } from 'react';

export default function FormsHub() {
  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-blue-600 text-white py-6 px-4">
        <div className="max-w-4xl mx-auto">
          <h1 className="text-2xl font-bold mb-2">Forms Hub</h1>
          <p className="text-blue-100">Select a form to complete</p>
        </div>
      </div>

      <div className="max-w-4xl mx-auto py-8 px-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          
          {/* Card A: Big Ticket Item Request */}
          <Link
            to="/forms-hub/big-ticket-request"
            className="group bg-white rounded-xl shadow-md p-6 border border-gray-200 hover:shadow-lg hover:border-blue-300 transition-all duration-200"
          >
            <div className="flex items-start space-x-4">
              <div className="flex-shrink-0">
                <div className="w-14 h-14 bg-orange-100 rounded-xl flex items-center justify-center group-hover:bg-orange-200 transition-colors">
                  <svg className="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                  </svg>
                </div>
              </div>
              <div className="flex-1">
                <h2 className="text-xl font-semibold text-gray-900 mb-2">Big Ticket Item Request</h2>
                <p className="text-gray-600 text-sm mb-4">
                  Request large items like appliances, mattresses, or furniture for a specific room.
                </p>
                <ul className="text-sm text-gray-500 space-y-1 mb-4">
                  <li>• Select station and room</li>
                  <li>• Choose items from curated list</li>
                  <li>• Add notes if needed</li>
                </ul>
                <span className="inline-flex items-center text-blue-600 font-medium group-hover:text-blue-700">
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
            className="group bg-white rounded-xl shadow-md p-6 border border-gray-200 hover:shadow-lg hover:border-blue-300 transition-all duration-200"
          >
            <div className="flex items-start space-x-4">
              <div className="flex-shrink-0">
                <div className="w-14 h-14 bg-green-100 rounded-xl flex items-center justify-center group-hover:bg-green-200 transition-colors">
                  <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                  </svg>
                </div>
              </div>
              <div className="flex-1">
                <h2 className="text-xl font-semibold text-gray-900 mb-2">Station Inventory Form</h2>
                <p className="text-gray-600 text-sm mb-4">
                  Complete station supply inventory and generate a PDF request.
                </p>
                <ul className="text-sm text-gray-500 space-y-1 mb-4">
                  <li>• Categories: Garbage, Floors, Laundry, Kitchen</li>
                  <li>• Select quantities up to max</li>
                  <li>• Download PDF on completion</li>
                </ul>
                <span className="inline-flex items-center text-blue-600 font-medium group-hover:text-blue-700">
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
          <Link
            to="/"
            className="inline-flex items-center px-4 py-2 text-gray-600 hover:text-gray-900"
          >
            <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to MBFD Forms
          </Link>
        </div>
      </div>
    </div>
  );
}