// Plain-language wording for the audit log's security entries ("security.*"), written for
// office staff rather than IT. The server stores a technical summary ("Blocked request to
// /api/export/summary: A text value is too long…"); this turns it into what happened, what it
// means for the user, and who to talk to. The technical text stays available for IT staff.

// What the user was doing, by the address the request went to (after /api/)
const FEATURES = [
  [/^export\/summary/, 'exporting the Summary Report'],
  [/^export\/stockcard/, 'exporting a Stock Card'],
  [/^export\//, 'exporting a report'],
  [/^import\//, 'importing from Excel'],
  [/^stock\/add/, 'recording a stock movement'],
  [/^stock\/count/, 'saving a physical count'],
  [/^stock\/ledger/, 'saving changes to the stock card'],
  [/^stock\//, 'working with stock'],
  [/^stockout/, 'a stock-out request'],
  [/^products?\//, 'working with products'],
  [/^settings\//, 'changing settings'],
  [/^backup/, 'a backup or restore'],
  [/^auth\/login/, 'signing in'],
  [/^auth\/(register|forgot|reset)/, 'signing up or resetting a password'],
  [/^auth\//, 'an account action'],
]

const IT_HELP = 'If this keeps happening, please coordinate with your designated IT personnel so they can look into it.'

// Per kind of block: the tag on the row, and what happened in plain words
const KINDS = {
  too_complex: {
    tag: 'Request too large',
    text: (f) => `The system stopped ${f} because it carried more information than the system normally accepts in one go. Nothing was saved or changed.`,
    help: IT_HELP,
  },
  payload_too_large: {
    tag: 'Upload too large',
    text: (f) => `The system stopped ${f} because the file or data sent was larger than allowed. Nothing was saved or changed.`,
    help: 'Try a smaller file. ' + IT_HELP,
  },
  malformed: {
    tag: 'Unreadable request',
    text: (f) => `The system could not read the information sent while ${f}, so it was not processed. Nothing was saved or changed.`,
    help: 'Refreshing the page and trying again usually helps. ' + IT_HELP,
  },
  rate_limited: {
    tag: 'Too many attempts',
    text: (f) => `The system paused ${f} for a moment because too many requests were made in a short time. It works again after a short wait.`,
    help: IT_HELP,
  },
  csrf_blocked: {
    tag: 'Outside request blocked',
    text: (f) => `The system refused a request for ${f} that did not come from the BSU Inventory app itself. Nothing was saved or changed.`,
    help: 'If you did not expect this, please report it to your designated IT personnel.',
  },
}

const kindOf = (action) => String(action || '').replace(/^security\./, '')

function routeOf(log) {
  const details = log.details && typeof log.details === 'object' ? log.details : {}
  if (details.route) return String(details.route)
  const m = String(log.summary || '').match(/\/api\/([^\s:]+)/)
  return m ? m[1] : ''
}

function featureOf(route) {
  const found = FEATURES.find(([pattern]) => pattern.test(route))
  return found ? found[1] : 'a task in the system'
}

export const isSecurityEntry = (log) => String(log?.action || '').startsWith('security.')

/** The tag shown in the Action column for a security entry, or '' for any other entry. */
export function securityTag(action) {
  return KINDS[kindOf(action)]?.tag || ''
}

/**
 * { text, help, technical } for a security entry; null for any other entry.
 * technical: the summary as the server wrote it (for IT staff).
 */
export function plainSecuritySummary(log) {
  if (!isSecurityEntry(log)) return null
  const kind = KINDS[kindOf(log.action)]
  const feature = featureOf(routeOf(log))
  const details = log.details && typeof log.details === 'object' ? log.details : {}
  return {
    text: kind ? kind.text(feature) : `The system blocked a request while ${feature}. Nothing was saved or changed.`,
    help: kind ? kind.help : IT_HELP,
    technical: details.technical || log.summary || '',
  }
}
