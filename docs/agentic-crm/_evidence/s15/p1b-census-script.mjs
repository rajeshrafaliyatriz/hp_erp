// Counts, per file, the places that name the old Signal Review ADDRESS (not the services/signal-review import paths and not file names).
// Usage: node census.mjs <repoRoot> <label>
// Scans git-tracked AND untracked-not-ignored text files (git ls-files -co --exclude-standard), skipping vendor/node_modules/.next/storage and, for hp_erp, the docs folder.
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import path from 'node:path'

const [root, label] = process.argv.slice(2)
const files = execFileSync('git', ['ls-files', '-co', '--exclude-standard', '-z'], { cwd: root, maxBuffer: 1 << 28 }).toString('utf8').split('\0').filter(Boolean)

const SKIP = /^(vendor|node_modules|\.next|storage|public\/build)\//
const DOCS = /^(docs|Docs)\//
const TEXT = /\.(php|ts|tsx|js|jsx|mjs|cjs|json|md|txt|log|blade\.php|yml|yaml|env\.example)$/i
const ADDRESS = /(?:(?<![\w.\-])|(?<=\/module\/crm))\/signal-review(?![\w\-])/g
const LOOSE = /signal-review/g

let addressTotal = 0
let looseTotal = 0
const rows = []
for (const f of files) {
  if (SKIP.test(f)) continue
  const docs = DOCS.test(f)
  if (!TEXT.test(f)) continue
  let text
  try { text = readFileSync(path.join(root, f), 'utf8') } catch { continue }
  const a = (text.match(ADDRESS) ?? []).length
  const l = (text.match(LOOSE) ?? []).length
  if (a === 0 && l === 0) continue
  rows.push({ f, a, l, docs })
  if (!docs) { addressTotal += a; looseTotal += l }
}
rows.sort((x, y) => x.f.localeCompare(y.f))
console.log(`# census ${label} (${root})`)
console.log('# columns: address-occurrences  loose-substring-occurrences  file   [docs = excluded from totals]')
for (const r of rows) console.log(`${String(r.a).padStart(3)}  ${String(r.l).padStart(3)}  ${r.f}${r.docs ? '   [docs]' : ''}`)
console.log(`TOTAL (non-docs) address occurrences: ${addressTotal}`)
console.log(`TOTAL (non-docs) loose 'signal-review' substring occurrences: ${looseTotal}`)
console.log(`files with an address occurrence (non-docs): ${rows.filter((r) => !r.docs && r.a > 0).length}`)
