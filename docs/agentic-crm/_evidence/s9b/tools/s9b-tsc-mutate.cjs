'use strict'
// tsc-based mutation proof for the TYPE rules (a field must stay optional). For each listed mutation of the spec: back the file up, break it, run
// `node node_modules/typescript/bin/tsc --noEmit -p .`, record every error that is NOT one of the 7 known ones, restore the file byte for byte and compare sha256.
// A mutation counts as KILLED by tsc only when tsc reports at least one error beyond the baseline's, in a file of this track.
// usage: node s9b-tsc-mutate.cjs <out file> <spec> <id,id,...>
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const { spawnSync } = require('node:child_process')
const ROOT = 'C:/Users/MILAN/Downloads/g2gv0'
const SCRATCH = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad'
const OUT = process.argv[2]
const spec = JSON.parse(fs.readFileSync(path.join(SCRATCH, process.argv[3]), 'utf8'))
const ids = process.argv[4].split(',')
const sha = (b) => crypto.createHash('sha256').update(b).digest('hex')
const lines = []
const log = (s) => { lines.push(s); fs.writeFileSync(OUT, lines.join('\n') + '\n') }

function tsc() {
  const started = Date.now()
  const r = spawnSync(process.execPath, ['node_modules/typescript/bin/tsc', '--noEmit', '-p', '.'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 100 * 1024 * 1024, timeout: 400000 })
  const errors = `${r.stdout}\n${r.stderr}`.split(/\r?\n/).filter((l) => /error TS\d+/.test(l))
  return { status: r.status, errors, seconds: Math.round((Date.now() - started) / 1000) }
}
const fileOf = (line) => line.replace(/\(.*$/, '')

log(`S9b type-rule mutation proofs by tsc; started ${new Date().toISOString()}`)
const base = tsc()
const baseKey = new Set(base.errors)
log(`BASELINE: tsc exit ${base.status}, ${base.errors.length} errors (the known ones elsewhere): ${[...new Set(base.errors.map(fileOf))].join(', ')} (${base.seconds}s)`)
if (base.errors.length !== 7) { log('BASELINE IS NOT THE EXPECTED 7 KNOWN ERRORS: stopping, nothing was mutated.'); process.exit(2) }

let killed = 0
let survived = 0
for (const id of ids) {
  const m = spec.mutations.find((x) => String(x.id) === id)
  const file = path.join(ROOT, m.file)
  const before = fs.readFileSync(file)
  const text = before.toString('utf8')
  if (text.split(m.find).length - 1 !== 1) { log(`#${id}: SKIPPED, find not unique`); continue }
  let res
  try {
    fs.writeFileSync(file, Buffer.from(text.split(m.find).join(m.replace), 'utf8'))
    res = tsc()
  } finally {
    fs.writeFileSync(file, before)
  }
  const same = sha(fs.readFileSync(file)) === sha(before)
  const extra = res.errors.filter((e) => !baseKey.has(e))
  const verdict = extra.length > 0 ? 'KILLED' : 'SURVIVED'
  if (verdict === 'KILLED') killed += 1
  else survived += 1
  log(`#${id} [${verdict} by tsc] ${m.file} :: ${m.rule}\n    new errors beyond the 7 known (${extra.length}): ${extra.slice(0, 3).map((e) => e.slice(0, 150)).join(' || ')}\n    restored byte for byte: ${same ? 'yes' : 'NO'} (${res.seconds}s)`)
  if (!same) { log('!!! RESTORE MISMATCH: stopping'); process.exit(3) }
}
const after = tsc()
log(`\nSUMMARY: ${ids.length} run, ${killed} killed by tsc, ${survived} survived; tsc after the run: ${after.errors.length} errors (expected 7)`)
log(`finished ${new Date().toISOString()}`)
