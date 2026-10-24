import React, { useCallback, useEffect, useState } from 'react';
import { apiGet } from '../api.js';

// Gemeinsam mit Inbox — damit Postfach-Wahl in Inbox / Newsletters / Actions
// synchron bleibt.
export const ACCOUNT_STORAGE_KEY = 'mg_inbox_account_id';

export function loadStoredAccountId() {
  try { return parseInt(localStorage.getItem(ACCOUNT_STORAGE_KEY) || '0', 10) || 0; }
  catch { return 0; }
}
export function saveStoredAccountId(id) {
  try { localStorage.setItem(ACCOUNT_STORAGE_KEY, String(id || 0)); } catch { /* ignore */ }
}

/**
 * Laedt die Postfaecher-Liste des aktuellen Kunden und verwaltet die Auswahl
 * mit localStorage-Persistenz. Rueckgabe: [accounts, activeId, switchTo].
 * activeId ist 0 solange nichts geladen oder kein Konto existiert.
 */
export function useCurrentAccount() {
  const [accounts,  setAccounts]  = useState([]);
  const [accountId, setAccountId] = useState(0);

  useEffect(() => {
    let cancel = false;
    (async () => {
      const { body } = await apiGet('accounts');
      if (!cancel && body && body.ok) setAccounts(body.items || []);
    })();
    return () => { cancel = true; };
  }, []);

  useEffect(() => {
    if (!accounts.length) return;
    if (accountId && accounts.some((a) => a.id === accountId)) return;
    const stored = loadStoredAccountId();
    const pick = stored && accounts.some((a) => a.id === stored)
      ? stored
      : (accounts.find((a) => a.status === 'active') || accounts[0]).id;
    setAccountId(pick);
  }, [accounts, accountId]);

  const switchTo = useCallback((id) => {
    saveStoredAccountId(id);
    setAccountId(id);
  }, []);

  return [accounts, accountId, switchTo];
}

/**
 * Renderts die Postfach-Tab-Leiste. Kein Rendering wenn nur 1 Postfach —
 * die Umschaltung waere in dem Fall visuelles Rauschen.
 */
export default function AccountTabs({ accounts, activeId, onSwitch }) {
  if (!accounts || accounts.length < 2) return null;
  return (
    <div className="mg-mono mg-tabs-mono" role="tablist" aria-label="Postfach auswählen">
      {accounts.map((a) => {
        const active = a.id === activeId;
        return (
          <button
            key={a.id}
            className={'mg-tabs-mono__btn' + (active ? ' mg-tabs-mono__btn--active' : '')}
            role="tab"
            aria-selected={active}
            title={a.username || a.label || a.host}
            onClick={() => onSwitch(a.id)}
          >
            <span className={'mg-dash__dot mg-dash__dot--' + (a.status === 'active' ? 'live' : 'muted')} aria-hidden />
            <span className="mg-tabs-mono__label">{a.label || a.host}</span>
            {a.status !== 'active' && <span className="mg-tabs-mono__state">{a.status.toUpperCase()}</span>}
          </button>
        );
      })}
    </div>
  );
}
