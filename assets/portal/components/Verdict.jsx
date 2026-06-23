import React from 'react';

const META = {
  clean:      { label: 'sauber',      cls: 'mg-verdict--clean' },
  suspicious: { label: 'verdächtig',  cls: 'mg-verdict--susp'  },
  dangerous:  { label: 'gefährlich',  cls: 'mg-verdict--danger'},
};

export function VerdictBadge({ verdict, score, status }) {
  if (status === 'pending' || status === 'scanning') {
    return <span className="mg-verdict mg-verdict--pending">scan …</span>;
  }
  if (status === 'error') {
    return <span className="mg-verdict mg-verdict--err" title="Scan-Fehler">scan ✘</span>;
  }
  const meta = META[verdict];
  if (!meta) return null;
  return (
    <span className={'mg-verdict ' + meta.cls} title={typeof score === 'number' ? 'Score ' + score : ''}>
      {meta.label}{typeof score === 'number' ? ' · ' + score : ''}
    </span>
  );
}

export function ReasonsList({ reasons }) {
  if (!Array.isArray(reasons) || reasons.length === 0) return null;
  return (
    <ul className="mg-reasons">
      {reasons.map((r, i) => (
        <li key={i}>
          <code>{r.rule}</code>
          <span className="mg-reasons__score">+{r.score|0}</span>
          <span>{r.description}</span>
        </li>
      ))}
    </ul>
  );
}
