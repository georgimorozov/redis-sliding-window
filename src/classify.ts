/** The reasons a degraded result reports (docs/design.md, "Failure policy"). */
export type UnavailableReason =
  'connection' | 'timeout' | 'LOADING' | 'BUSY' | 'MASTERDOWN' | 'READONLY';

export type Classification =
  | { readonly action: 'noscript' }
  | { readonly action: 'degrade'; readonly reason: UnavailableReason }
  | { readonly action: 'throw' };

const DEGRADING_CODES: ReadonlySet<string> = new Set(['LOADING', 'BUSY', 'MASTERDOWN', 'READONLY']);
// ioredis 6's own transport failures, matched exactly (node_modules/ioredis/built: Command.js,
// Redis.js, utils/index.js CONNECTION_CLOSED_ERROR_MSG, errors/MaxRetriesPerRequestError.js).
const TIMEOUT_MESSAGE = 'Command timed out';
const CONNECTION_MESSAGES: ReadonlySet<string> = new Set([
  'Connection is closed.',
  "Stream isn't writeable and enableOfflineQueue options is false",
]);
const THROW: Classification = { action: 'throw' };

/**
 * Classifies an error from the script call. A server error reply (ioredis `ReplyError`) by its code,
 * the first word of its message; then ioredis transport failures; everything else throws.
 */
export function classify(error: unknown): Classification {
  if (!(error instanceof Error)) return THROW;
  if (error.name === 'ReplyError') {
    const code = error.message.split(' ', 1)[0] ?? '';
    if (code === 'NOSCRIPT') return { action: 'noscript' };
    if (DEGRADING_CODES.has(code)) {
      return { action: 'degrade', reason: code as UnavailableReason };
    }
    return THROW;
  }
  if (error.name === 'MaxRetriesPerRequestError')
    return { action: 'degrade', reason: 'connection' };
  if (error.message === TIMEOUT_MESSAGE) return { action: 'degrade', reason: 'timeout' };
  if (CONNECTION_MESSAGES.has(error.message)) return { action: 'degrade', reason: 'connection' };
  return THROW;
}
