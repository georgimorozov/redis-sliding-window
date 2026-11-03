// The gate's live check for the npm package (scripts/docker-test.sh package). Reads `npm pack --dry-run
// --json` on stdin, checks the file list, then imports the package by its own name, which resolves
// through package.json "exports" as it does for a consumer.
import process from 'node:process';
import { text } from 'node:stream/consumers';

// Read stdin as a stream: readFileSync(0) fails with EAGAIN when the pipe is non-blocking.
const parsed = JSON.parse(await text(process.stdin));
// npm prints an array of tarball summaries, or an object keyed by package name; accept both.
const tarballs = Array.isArray(parsed) ? parsed : Object.values(parsed);
const paths = tarballs.flatMap((tarball) => tarball.files.map((file) => file.path));
const missing = ['package.json', 'dist/index.js', 'dist/index.d.ts'].filter(
  (path) => !paths.includes(path),
);
const forbidden = paths.filter((path) =>
  /^(src|tests|php|scripts|docker|node_modules)\//.test(path),
);
if (missing.length > 0 || forbidden.length > 0) {
  process.stderr.write(
    `check-pack: missing ${JSON.stringify(missing)}; must not ship ${JSON.stringify(forbidden)}\n`,
  );
  process.exit(1);
}
await import('redis-sliding-window');
process.stdout.write(`check-pack: ${paths.length} files; the package imports by its name\n`);
