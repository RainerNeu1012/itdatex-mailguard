// Notifications-Client: pollt den Server-Feed, feuert native Toasts (Desktop-
// Client) und pflegt den Header-Badge im Portal.
//
// Warum poll statt push?
//   Web-Portal: Web-Push würde einen Service Worker + VAPID-Setup brauchen —
//     zusätzliche Infra, die uns nichts gegenüber Poll bringt, solange das
//     Portal-Tab offen ist.
//   Desktop-Client: Windows-FCM in WebView2 ist fragil (Service-Worker-Setup
//     bricht bei Sleep). Poll ist verlässlich und funktioniert überall gleich.
//
// Poll-Intervall skaliert mit document.visibilityState — 60 s bei aktivem
// Fenster (weil User dann reagieren kann), 5 min wenn im Tray/Hintergrund
// (weil ihn dort eh nur der Toast + Tray-Badge erreicht).

import { useEffect, useState, useCallback, useRef } from 'react';
import { apiGet, apiPost } from './api.js';

const INTERVAL_ACTIVE_MS = 60_000;
const INTERVAL_IDLE_MS   = 5 * 60_000;
// lastSeenId wird pro Session im localStorage gehalten — verhindert, dass der
// User nach einem Reload plötzlich alte Toasts zwei Wochen alter Notifications
// bekommt.
const LAST_SEEN_KEY = 'mg_notifications_last_seen_id';

/**
 * Hook für den Header-Bell + Toast-Bridge. Startet den Poll-Loop beim Mount,
 * stoppt ihn beim Unmount. Rückgabe: `{ items, unreadCount, markSeen, refresh }`.
 *
 * `items` sind die letzten 50 Notifications (auch bereits gelesene, damit die
 * History-Anzeige nicht schlagartig leer ist, sobald der User "als gelesen"
 * markiert hat).
 */
export function useNotifications(enabled) {
  const [items, setItems] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const lastSeenRef = useRef(readLastSeenId());
  const timerRef = useRef(null);

  const nativeBridge = () => (window.itdatexMailguard || {}).native || null;

  const poll = useCallback(async () => {
    if (!enabled) return;
    try {
      // Wir holen absichtlich ALLE (bis 50) — für die History-Ansicht.
      // Neue-seit-lastSeen filtern wir client-seitig, statt zwei separate
      // Requests zu schicken.
      const { status, body } = await apiGet('me/notifications?limit=50');
      if (status !== 200 || !body || !body.ok) return;
      const list = Array.isArray(body.items) ? body.items : [];
      setItems(list);
      setUnreadCount(Number(body.unread_count) || 0);

      // Neue (id > lastSeen) → native Toasts feuern.
      const lastSeen = lastSeenRef.current;
      const fresh = list.filter((n) => n.id > lastSeen);
      const native = nativeBridge();
      if (native && fresh.length > 0) {
        // Neueste zuerst, aber max. 3 Toasts pro Poll — sonst spammt der
        // Windows Action Center. Ältere werden nur im Header-Feed angezeigt.
        fresh.slice(0, 3).forEach((n) => {
          native.notify({ title: n.title, body: n.body, route: n.route });
        });
      }
      if (native && typeof native.setBadge === 'function') {
        native.setBadge(Number(body.unread_count) || 0);
      }

      if (list.length > 0) {
        const maxId = Math.max(...list.map((n) => n.id));
        if (maxId > lastSeen) {
          lastSeenRef.current = maxId;
          writeLastSeenId(maxId);
        }
      }
    } catch (e) {
      // Netzwerk-Fehler beim Poll darf die App nicht kaputt machen; still fail.
      console.warn('notifications poll failed', e);
    }
  }, [enabled]);

  /** Markiert alle Notifications bis inkl. der jüngsten als gelesen (Server + Client). */
  const markSeen = useCallback(async () => {
    if (!items.length) return;
    const maxId = Math.max(...items.map((n) => n.id));
    try {
      await apiPost('me/notifications/mark-seen', { up_to_id: maxId });
      setItems((prev) => prev.map((n) => ({ ...n, read_at: n.read_at || 'now' })));
      setUnreadCount(0);
      const native = nativeBridge();
      if (native && typeof native.setBadge === 'function') native.setBadge(0);
    } catch (e) {
      console.warn('mark-seen failed', e);
    }
  }, [items]);

  // Poll-Loop starten/stoppen je nach `enabled` (User eingeloggt oder nicht).
  useEffect(() => {
    if (!enabled) {
      if (timerRef.current) clearInterval(timerRef.current);
      return;
    }
    poll(); // initial

    const schedule = () => {
      if (timerRef.current) clearInterval(timerRef.current);
      const interval = document.visibilityState === 'hidden'
        ? INTERVAL_IDLE_MS
        : INTERVAL_ACTIVE_MS;
      timerRef.current = setInterval(poll, interval);
    };
    schedule();

    const onVisibility = () => {
      // Beim Zurückkommen aus dem Hintergrund direkt einen Poll auslösen,
      // damit der User keine 5 Minuten alten Zahlen sieht, dann Intervall
      // anpassen.
      if (document.visibilityState === 'visible') poll();
      schedule();
    };
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [enabled, poll]);

  return { items, unreadCount, markSeen, refresh: poll };
}

function readLastSeenId() {
  try {
    const raw = globalThis.localStorage?.getItem(LAST_SEEN_KEY);
    return raw ? parseInt(raw, 10) || 0 : 0;
  } catch { return 0; }
}

function writeLastSeenId(id) {
  try { globalThis.localStorage?.setItem(LAST_SEEN_KEY, String(id)); }
  catch { /* ignore */ }
}
