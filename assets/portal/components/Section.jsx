import React from 'react';

/**
 * Wiederverwendbare Section-Primitiven im Precision-Monochrome-Stil
 * (eingefuehrt mit Dashboard v0.60.0).
 *
 * Verwendung:
 *   <SectionHeader num="001" title="POSTFÄCHER" counter="3 AKTIV"
 *                  right={<button>…</button>} />
 *
 *   <HairlineList>
 *     <HairlineRow>…</HairlineRow>
 *   </HairlineList>
 */
export function SectionHeader({ num, title, right, counter }) {
  return (
    <div className="mg-dash__section-head">
      {num && <span className="mg-dash__section-num mg-mono">{num}</span>}
      <h2 className="mg-dash__section-title mg-mono">{title}</h2>
      {counter && <span className="mg-dash__section-count mg-mono">{counter}</span>}
      <div className="mg-dash__section-rule" aria-hidden />
      {right}
    </div>
  );
}

export function Section({ children, className = '' }) {
  return <section className={'mg-dash__section ' + className}>{children}</section>;
}

export function HairlineList({ children, variant }) {
  return <ul className={'mg-dash__list' + (variant ? ' mg-dash__list--' + variant : '')}>{children}</ul>;
}

export function HairlineRow({ children, variant, onClick }) {
  const Tag = onClick ? 'a' : 'li';
  return (
    <Tag
      className={'mg-dash__row' + (variant ? ' mg-dash__row--' + variant : '') + (onClick ? ' mg-dash__row--click' : '')}
      onClick={onClick ? (e) => { e.preventDefault(); onClick(e); } : undefined}
      href={onClick ? '#' : undefined}
    >
      {children}
    </Tag>
  );
}

export function PageShell({ children }) {
  return <div className="mg-dash">{children}</div>;
}

// PageTopbar ist seit v0.66 ein No-op — die globale App-Topbar
// zeigt Breadcrumb + Clock + LIVE. Views duerfen weiter `<PageTopbar/>`
// mounten, damit alte Imports nicht brechen; es wird nichts gerendert.
export function PageTopbar() { return null; }
