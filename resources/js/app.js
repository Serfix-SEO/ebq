import './bootstrap';

// Lazy bridge for the "Report a bug" snipping overlay: the capture module
// (and modern-screenshot inside it) only downloads when a user actually
// clicks "Capture area" — Vite code-splits the dynamic import.
window.ebqSnip = () => import('./bug-report-capture.js').then((m) => m.snip());
