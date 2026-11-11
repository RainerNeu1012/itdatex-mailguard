// Sanitizer fuer Mail-Body-HTML: entfernt Tracking-Pixel + bekannte
// Marketing-Tracker-URLs bevor der Body im sandbox-iframe gerendert wird.
// Wird von Portal (Inbox.jsx) + App (MessageDetail.jsx) importiert.
//
// Was gefiltert wird:
//  1. <img>-Tags mit width=1/height=1 oder style 1x1 (klassische Open-Tracker)
//  2. <img>-Tags zu bekannten Marketing-Tracker-Domains
//  3. UTM-Tracking-Params in Links werden NICHT entfernt — das wuerde
//     legitime Newsletter-Clicks brechen. Nur echte Bezeichner raus.

const TRACKER_HOST_PATTERNS = [
  // Mail-Marketing-Tracker (open-tracking pixels)
  /\.mkto-?mail\./i,                          // Marketo
  /\.mkt\d+\./i,
  /sendgrid\.net/i,                           // SendGrid open-track
  /\.sendgrid\./i,
  /mandrillapp\.com/i,                        // Mailchimp Transactional
  /mcusercontent\.com/i,
  /\.mailchimp\.com/i,
  /\.list-manage\.com/i,                      // Mailchimp
  /\.rsgsv\.net/i,                            // Mailchimp CDN-tracker
  /\.constantcontact\.com/i,
  /\.cmail\d+\.com/i,                         // Campaign Monitor
  /\.createsend\.com/i,                       // Campaign Monitor
  /\.hubspotlinks\.com/i, /\.hubspotemail/i,  // HubSpot
  /\.hs-sites\.com/i,
  /\.pardot\.com/i,                           // Salesforce Pardot
  /\.exacttarget\.com/i,                      // Salesforce Marketing Cloud
  /\.marketingcloud\.com/i,
  /mailtrack\.io/i,
  /streak-pixel/i, /streak\.com/i,            // Streak
  /\.getvero\.com/i,                          // Vero
  /intercom-mail/i,
  /\.customer\.io/i,
  /\.braze\.com/i,
  /\.iterable\.com/i,
  // Analytics-Pixel in Mails (seltener)
  /google-analytics\.com\/collect/i,
];

function hostOfUrl(url) {
  try { return new URL(url).host.toLowerCase(); } catch { return ''; }
}

function isTrackerHost(host) {
  if (!host) return false;
  return TRACKER_HOST_PATTERNS.some(re => re.test(host));
}

/**
 * Pruefen ob ein img-Tag ein Tracking-Pixel ist.
 * Signale: 1x1 dimensions, display:none, tracker-Host.
 */
function isTrackingImg(tag) {
  // extract width/height attributes
  const w = parseInt((tag.match(/\bwidth\s*=\s*["']?(\d+)/i) || [])[1] || '0', 10);
  const h = parseInt((tag.match(/\bheight\s*=\s*["']?(\d+)/i) || [])[1] || '0', 10);
  if (w === 1 && h === 1) return true;
  if (w > 0 && w <= 2 && h > 0 && h <= 2) return true;

  // style:display:none oder style:width:1px
  const style = ((tag.match(/\bstyle\s*=\s*["']([^"']*)["']/i) || [])[1] || '').toLowerCase();
  if (style.includes('display:none') || style.includes('display: none')) return true;
  if (/width\s*:\s*1(?:px|em)?/.test(style) && /height\s*:\s*1(?:px|em)?/.test(style)) return true;

  // Tracker-Host?
  const src = (tag.match(/\bsrc\s*=\s*["']([^"']+)["']/i) || [])[1] || '';
  if (src && isTrackerHost(hostOfUrl(src))) return true;

  return false;
}

/**
 * Hauptfunktion: entfernt Tracking-Pixel aus HTML-string.
 * Gibt dekontaminiertes HTML + Count der entfernten Pixel zurueck.
 * @returns {{ html: string, removed: number }}
 */
export function stripTrackingPixels(html) {
  if (!html || typeof html !== 'string') return { html: html || '', removed: 0 };
  let removed = 0;
  // Alle <img ... /> + <img ...></img> Tags durchlaufen
  const cleaned = html.replace(/<img\b[^>]*\/?>/gi, (tag) => {
    if (isTrackingImg(tag)) { removed++; return ''; }
    return tag;
  });
  return { html: cleaned, removed };
}
