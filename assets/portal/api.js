const cfg = () => window.itdatexMailguard || {};

async function request(method, path, body) {
  const base = cfg().restUrl || '';
  const init = {
    method,
    credentials: 'same-origin',
    headers: {},
  };
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

export const apiGet  = (path)       => request('GET',  path);
export const apiPost = (path, body) => request('POST', path, body);
