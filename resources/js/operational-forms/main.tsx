/// <reference types="vite/client" />

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { OperationalFormsApp } from './OperationalFormsApp';
import type { BootstrapData } from './types';
import './operational-forms.css';

const element = document.getElementById('operational-forms-root');
if (!element) throw new Error('Operational Forms mount point is missing.');

const bootstrap = JSON.parse(element.dataset.bootstrap || '{}') as BootstrapData;
createRoot(element).render(<StrictMode><OperationalFormsApp bootstrap={bootstrap} /></StrictMode>);
