import React from 'react';

/**
 * Theme-aware Brand-Mark: zeigt mark-white.svg im Dark-Mode,
 * mark.svg im Light-Mode. Via CSS-display-Toggle, damit der Switch
 * ohne Flash passiert.
 */
export default function BrandMark({ className = '', size = 28, alt = '!tdatex' }) {
  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  // display wird AUSSCHLIESSLICH aus CSS kontrolliert (Theme-Toggle via
  // :root[data-theme] + prefers-color-scheme). Inline-style würde das
  // display:none der versteckten Variante überschreiben → beide Marks sichtbar.
  const style = { width: size, height: 'auto', flexShrink: 0 };
  return (
    <span className={'mg-brand-mark ' + className} style={{ lineHeight: 0 }}>
      <img
        src={`${pluginUrl}assets/img/mark-white.svg`}
        alt={alt}
        className="mg-brand-mark__dark"
        style={style}
      />
      <img
        src={`${pluginUrl}assets/img/mark.svg`}
        alt={alt}
        className="mg-brand-mark__light"
        style={style}
      />
    </span>
  );
}
