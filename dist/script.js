import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
/**
 * The exact bytes of lua/sliding-window.lua (shipped in the package next to dist/) and their SHA-1, the
 * script's EVALSHA id (docs/design.md, "The Lua script").
 */
export const SCRIPT = readFileSync(new URL('../lua/sliding-window.lua', import.meta.url), 'utf8');
export const SCRIPT_SHA = createHash('sha1').update(SCRIPT, 'utf8').digest('hex');
