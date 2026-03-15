import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/index.css';

// Get the root element
const container = document.getElementById('apparatus-layout-root');

if (container) {
  const root = createRoot(container);
  root.render(<App />);
} else {
  console.error('Root element #apparatus-layout-root not found');
}