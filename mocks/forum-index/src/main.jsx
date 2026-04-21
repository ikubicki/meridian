import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import './styles/base.css';

function normalizeBasePath(path) {
  if (!path || path === '/') return '/';
  const withLeadingSlash = path.startsWith('/') ? path : `/${path}`;
  return withLeadingSlash.endsWith('/') ? withLeadingSlash : `${withLeadingSlash}/`;
}

function getBasePathFromBaseTag() {
  const href = document.querySelector('base')?.getAttribute('href');
  if (!href) return '/';

  try {
    return new URL(href, window.location.origin).pathname;
  } catch {
    return '/';
  }
}

const normalizedBasePath = normalizeBasePath(getBasePathFromBaseTag());

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter basename={normalizedBasePath}>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);
