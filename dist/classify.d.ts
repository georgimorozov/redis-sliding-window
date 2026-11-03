/** The reasons a degraded result reports (docs/design.md, "Failure policy"). */
export type UnavailableReason = 'connection' | 'timeout' | 'LOADING' | 'BUSY' | 'MASTERDOWN' | 'READONLY';
export type Classification = {
    readonly action: 'noscript';
} | {
    readonly action: 'degrade';
    readonly reason: UnavailableReason;
} | {
    readonly action: 'throw';
};
/**
 * Classifies an error from the script call. A server error reply (ioredis `ReplyError`) by its code,
 * the first word of its message; then ioredis transport failures; everything else throws.
 */
export declare function classify(error: unknown): Classification;
