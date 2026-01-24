import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar, { MenuIcon } from './Sidebar';
import OfflineIndicator from '../OfflineIndicator';

export default function Layout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="flex h-screen bg-gray-50 overflow-hidden">
      {/* Sidebar */}
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Main content area */}
      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Top header bar */}
        <header className="bg-white border-b border-gray-200 shadow-sm">
          <div className="flex items-center justify-between h-16 px-4 lg:px-6">
            {/* Mobile menu button */}
            <button
              onClick={() => setSidebarOpen(true)}
              className="lg:hidden p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors"
              aria-label="Open sidebar"
            >
              <MenuIcon />
            </button>

            {/* Breadcrumb/Title area */}
            <div className="flex-1 min-w-0 lg:ml-0 ml-4">
              <h2 className="text-lg font-semibold text-gray-900 truncate">
                Daily Checkout System
              </h2>
            </div>

            {/* Right side actions */}
            <div className="flex items-center gap-3">
              <OfflineIndicator />
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto">
          <div className="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}
