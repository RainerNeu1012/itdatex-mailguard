import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';

const mount = document.getElementById('mg-portal');
if (mount) {
  createRoot(mount).render(<App />);
}
