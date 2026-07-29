import { copyFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Refresh public/flags from country-flag-icons.
 *
 * Run with `npm run flags:sync`. The files are committed, so this is only
 * needed when the package is updated — nothing at build or request time reads
 * the package, and it stays a devDependency for that reason.
 *
 * The 3x2 set, not flag-icons' 4x3. Both draw the same flags, but 4x3 renders
 * every coat of arms in full detail: Serbia is 179 KB, Spain 79 KB, and SVGO
 * takes 1% off because the weight *is* the artwork. At the 20px these are shown
 * at, none of that detail survives the first pixel. The same flags here are
 * under a kilobyte each — 178 KB for all 267.
 *
 * Names are lower-cased on the way over. The API reports ISO codes in upper
 * case and the component lower-cases them to build the path; on a case-sensitive
 * filesystem — which is to say in production, not on this Mac — ES.svg would be
 * a 404 for every Spanish server.
 */

const here = dirname(fileURLToPath(import.meta.url))
const from = join(here, '..', 'node_modules', 'country-flag-icons', '3x2')
const to = join(here, '..', 'public', 'flags')

rmSync(to, { recursive: true, force: true })
mkdirSync(to, { recursive: true })

const flags = readdirSync(from).filter((name) => name.endsWith('.svg'))

for (const name of flags) {
  copyFileSync(join(from, name), join(to, name.toLowerCase()))
}

console.log(`${flags.length} flags → public/flags`)
