import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './workgroup-data-dashboard/App';
import '../css/app.css';

const container = document.getElementById('workgroup-data-dashboard');
if (container) {
    createRoot(container).render(<App />);
}