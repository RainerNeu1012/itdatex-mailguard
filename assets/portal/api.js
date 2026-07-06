// Portal-API-Client. Unterstuetzt sowohl die Web-SPA (Cookie-Session via
// same-origin) als auch native Apps (Bearer-Token in localStorage/Preferences).
// Wenn 'mg_api_token' im Local-Storage liegt, wird er als Authorization-Header
// mitgeschickt — sonst laeuft alles wie bisher via Cookie. Damit ist derselbe
// Bundle-Code in beiden Kontexten nutzbar.
const TOKEN_KEY = 'mg_api_token';

const cfg = () => window.itdatexMailguard || {};

function getToken() {
  try { return globalThis.localStorage?.getItem(TOKEN_KEY) || null; }
  catch { return null; }
}
export function setToken(t) {
  try {
    if (t) globalThis.localStorage?.setItem(TOKEN_KEY, t);
    else   globalThis.localStorage?.removeItem(TOKEN_KEY);
  } catch { /* ignore */ }
}
export function hasToken() { return !!getToken(); }

async function request(method, path, body) {
  const base = cfg().restUrl || '';
  const init = {
    method,
    credentials: 'same-origin',
    headers: {},
  };
  const t = getToken();
  if (t) init.headers['Authorization'] = 'Bearer ' + t;
  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res  = await fetch(base + path.replace(/^\//, ''), init);
  const text = await res.text();
  let parsed;
  try { parsed = JSON.parse(text); } catch { parsed = text; }
  return { status: res.status, body: parsed };
}

export const apiGet    = (path)       => request('GET',    path);
export const apiPost   = (path, body) => request('POST',   path, body);
export const apiPatch  = (path, body) => request('PATCH',  path, body);
export const apiDelete = (path)       => request('DELETE', path);
