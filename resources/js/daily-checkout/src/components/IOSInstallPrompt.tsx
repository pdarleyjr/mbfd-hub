import { useState, useEffect } from 'react';

/**
 * Detects iOS Safari (non-standalone) and shows a dismissible banner
 * instructing the user to add the app to their Home Screen for push
 * notification support.
 *
 * Design: follows Impeccable mandate — no pure blacks, no bouncy animations.
 */
export function IOSInstallPrompt() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const isIOS =
      /iPad|iPhone|iPod/.test(navigator.userAgent) &&
      !(window as any).MSStream;
    const isStandalone =
      (window.navigator as any).standalone === true ||
      window.matchMedia('(display-mode: standalone)').matches;
    const dismissed = sessionStorage.getItem('ios-install-dismissed');

    if (isIOS && !isStandalone && !dismissed) {
      setVisible(true);
    }
  }, []);

  if (!visible) return null;

  function dismiss() {
    sessionStorage.setItem('ios-install-dismissed', '1');
    setVisible(false);
  }

  return (
    <div
      role="alert"
      style={{
        position: 'fixed',
        bottom: 0,
        left: 0,
        right: 0,
        zIndex: 9999,
        padding: '16px 20px',
        background: '#1e3a5f',
        color: '#f8f6f2',
        fontSize: '14px',
        lineHeight: 1.5,
        display: 'flex',
        alignItems: 'flex-start',
        gap: '12px',
        boxShadow: '0 -2px 12px rgba(0,0,0,0.15)',
        animation: 'slideUp 0.25s ease-out',
      }}
    >
      <div style={{ flex: 1 }}>
        <strong style={{ display: 'block', marginBottom: 4 }}>
          Enable Push Notifications
        </strong>
        <span>
          To receive alerts on this device, tap{' '}
          <span
            role="img"
            aria-label="share icon"
            style={{ fontSize: '16px' }}
          >
            ⬆
          </span>{' '}
          <strong>Share</strong> then <strong>"Add to Home Screen"</strong>.
        </span>
      </div>
      <button
        onClick={dismiss}
        aria-label="Dismiss"
        style={{
          background: 'transparent',
          border: 'none',
          color: '#f8f6f2',
          fontSize: '20px',
          cursor: 'pointer',
          padding: '0 4px',
          lineHeight: 1,
        }}
      >
        ✕
      </button>
      <style>{`
        @keyframes slideUp {
          from { transform: translateY(100%); opacity: 0; }
          to   { transform: translateY(0);    opacity: 1; }
        }
      `}</style>
    </div>
  );
}
