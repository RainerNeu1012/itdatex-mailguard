import { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from './api.js';

let cachedMe = undefined;
const listeners = new Set();

function notify() {
  for (const fn of listeners) fn(cachedMe);
}

export async function refreshMe() {
  try {
    const { status, body } = await apiGet('me');
    cachedMe = (status === 200 && body && body.ok) ? body.customer : null;
  } catch {
    cachedMe = null;
  }
  notify();
  return cachedMe;
}

export async function logoutCurrent() {
  await apiPost('logout');
  cachedMe = null;
  notify();
}

export function useMe() {
  const [me, setMe] = useState(cachedMe);
  const [loading, setLoading] = useState(cachedMe === undefined);

  useEffect(() => {
    const fn = (m) => { setMe(m); setLoading(false); };
    listeners.add(fn);
    if (cachedMe === undefined) {
      refreshMe();
    } else {
      setLoading(false);
    }
    return () => { listeners.delete(fn); };
  }, []);

  return { me, loading, refresh: useCallback(refreshMe, []) };
}
