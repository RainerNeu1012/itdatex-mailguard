// Portal-API-Client. Unterstuetzt drei Auth-Modi in Prioritätsreihenfolge:
//
//   1. cfg().authProvider (Tauri-Desktop). Access-Token liegt im OS-Keyring,
//      Refresh läuft über eine Callback-Funktion, die Bootstrap.js liefert.
//   2. localStorage-Token (native Mobile-Apps, die noch keinen Provider haben).
//   3. Cookie-Session (Web-SPA im Browser).
//
// Damit ist derselbe Bundle-Code in allen drei Kontexten nutzbar, ohne dass
// das Portal wissen muss, in welchem er läuft.
const TOKEN_KEY = 'mg_api_token';

const cfg = () => window.itdatexMailguard || {};

function getToken() {
  const ap = cfg().authProvider;
  if (ap && typeof ap.getAccessToken === 'function') {
    try { return ap.getAccessToken() || null; } catch { return null; }
  }
  try { return globalThis.localStorage?.getItem(TOKEN_KEY) || null; }
  catch { return null; }
}
export function setToken(t) {
  // authProvider verwaltet Tokens außerhalb von localStorage — hier nichts tun.
  // Der Desktop-Client rotiert Tokens über refresh() im Provider selbst.
  if (cfg().authProvider) return;
  try {
    if (t) globalThis.localStorage?.setItem(TOKEN_KEY, t);
    else   globalThis.localStorage?.removeItem(TOKEN_KEY);
  } catch { /* ignore */ }
}
export function hasToken() { return !!getToken(); }

/**
 * Führt einen Request aus. Bei 401 mit vorhandenem authProvider.refresh:
 * einmal Token erneuern und Request wiederholen. Nur einmal — sonst
 * geraten wir in eine Endlosschleife, wenn der Server anhaltend 401 gibt.
 */
async function request(method, path, body, _retried = false) {
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

  // Transparent Token-Refresh: Access-Tokens laufen nach 1h ab. Ohne diese
  // Logik würde jeder länger geöffnete Desktop-Client alle 60 Minuten
  // rausfliegen. Web-Session-Cookies verlängert der Server selbst, deshalb
  // ist der Refresh nur für authProvider relevant.
  const ap = cfg().authProvider;
  if (res.status === 401 && !_retried && ap && typeof ap.refresh === 'function') {
    try {
      await ap.refresh();
      return request(method, path, body, true);
    } catch {
      // refresh() hat den User bei permanenter Token-Invalidität schon
      // ausgeloggt (siehe bootstrap.js); wir reichen die 401 unverändert
      // an das UI durch, das sowieso gerade neu lädt.
    }
  }

  const text = await res.text();
  let parsed;
  try { parsed = JSON.parse(text); } catch { parsed = text; }
  return { status: res.status, body: parsed };
}

export const apiGet    = (path)       => request('GET',    path);
export const apiPost   = (path, body) => request('POST',   path, body);
export const apiPatch  = (path, body) => request('PATCH',  path, body);
export const apiDelete = (path)       => request('DELETE', path);
