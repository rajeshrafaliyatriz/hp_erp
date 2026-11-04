'use strict'
// S9b mutation runner. For each mutation: back the file up, break it on purpose, run the campaign tests, record which named tests failed,
// restore the file byte for byte and compare it with the backup (sha256). The baseline must be green first (a command error would otherwise look like a kill).
// A mutation counts as KILLED only when the test runner exited non-zero AND named at least one failing test (a command error or a crash that names no test
// is reported as ERROR and never counted as a kill). A mutation may carry `quick` (a narrow list of test globs): if the quick run kills it, that is a kill by
// those named tests (a subset of the suite); if the quick run survives, the whole suite is run before the mutation is called a survivor.
//
// usage: node s9b-mutate.cjs <evidence out path> [ids comma list | all] [spec file name in the scratchpad]
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const { spawnSync } = require('node:child_process')

const ROOT = 'C:/Users/MILAN/Downloads/g2gv0'
const SCRATCH = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad'
const BACKUP = path.join(SCRATCH, 's9b-backup')
const OUT = process.argv[2]
const ONLY = process.argv[3] && process.argv[3] !== 'all' ? process.argv[3].split(',') : null
const SPEC = JSON.parse(fs.readFileSync(path.join(SCRATCH, process.argv[4] || 's9b-mutations.json'), 'utf8'))

const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex')
const abs = (rel) => path.join(ROOT, rel)

function runTests(globs) {
  const started = Date.now()
  const result = spawnSync(process.execPath, ['--import', 'tsx', '--test', ...globs], { cwd: ROOT, encoding: 'utf8', maxBuffer: 200 * 1024 * 1024, timeout: 240000 })
  const out = `${result.stdout}\n${result.stderr}`
  const leaf = []
  for (const line of out.split(/\r?\n/)) {
    const m = /^(\s+)not ok \d+ - (.*)$/.exec(line)
    if (m && m[1].length >= 4) leaf.push(m[2])
  }
  const num = (name) => { const m = new RegExp(`^# ${name} (\\d+)`, 'm').exec(out); return m ? Number(m[1]) : null }
  return { status: result.status, leaf, tests: num('tests'), pass: num('pass'), fail: num('fail'), seconds: Math.round((Date.now() - started) / 1000), timedOut: !!(result.error && result.error.code === 'ETIMEDOUT') }
}

const verdictOf = (r) => {
  if (r.status !== 0 && r.leaf.length > 0) return 'KILLED'
  if (r.status === 0 && r.fail === 0 && r.tests) return 'SURVIVED'
  return 'ERROR'
}

const lines = []
const log = (s) => { lines.push(s); fs.writeFileSync(OUT, lines.join('\n') + '\n') }

// Back up every file that any mutation touches, before anything is changed.
const files = [...new Set(SPEC.mutations.map((m) => m.file))]
fs.mkdirSync(BACKUP, { recursive: true })
const original = {}
for (const rel of files) {
  const buf = fs.readFileSync(abs(rel))
  original[rel] = { buf, hash: sha(buf) }
  fs.writeFileSync(path.join(BACKUP, rel.replace(/[\\/]/g, '__')), buf)
}

log(`S9b mutation run: ${SPEC.title}`)
log(`started ${new Date().toISOString()}`)
const base = runTests(SPEC.tests)
log(`BASELINE (whole campaigns suite): status ${base.status}, tests ${base.tests}, pass ${base.pass}, fail ${base.fail}, ${base.seconds}s`)
if (base.status !== 0 || base.fail !== 0 || !base.tests) {
  log('BASELINE IS NOT GREEN: stopping, nothing was mutated. Failed: ' + base.leaf.join(' | '))
  process.exit(2)
}
const quickBases = {}
for (const m of SPEC.mutations) {
  if (!m.quick) continue
  const key = JSON.stringify(m.quick)
  if (quickBases[key]) continue
  const qb = runTests(m.quick)
  quickBases[key] = qb
  log(`BASELINE (quick set ${key}): status ${qb.status}, tests ${qb.tests}, pass ${qb.pass}, fail ${qb.fail}, ${qb.seconds}s`)
  if (qb.status !== 0 || qb.fail !== 0 || !qb.tests) { log('A QUICK SET IS NOT GREEN: stopping, nothing was mutated.'); process.exit(2) }
}

let killed = 0
let survived = 0
let errored = 0
let restoredOk = 0
const survivors = []
const errors = []
let ran = 0
let broken = 0

for (const m of SPEC.mutations) {
  if (ONLY && !ONLY.includes(String(m.id))) continue
  const before = original[m.file]
  let text = before.buf.toString('utf8')
  const occurrences = text.split(m.find).length - 1
  if (occurrences === 0 || (!m.all && occurrences !== 1)) {
    log(`#${m.id} ${m.file}: SKIPPED, the text to break was found ${occurrences} times (expected exactly 1)`)
    errors.push(m.id)
    errored += 1
    continue
  }
  ran += 1
  text = text.split(m.find).join(m.replace)
  let result
  let where = 'whole suite'
  try {
    fs.writeFileSync(abs(m.file), Buffer.from(text, 'utf8'))
    if (m.quick) {
      result = runTests(m.quick)
      where = 'quick set'
      if (verdictOf(result) !== 'KILLED') { result = runTests(SPEC.tests); where = 'whole suite (the quick set did not kill it)' }
    } else {
      result = runTests(SPEC.tests)
    }
  } finally {
    fs.writeFileSync(abs(m.file), before.buf)
  }
  const after = sha(fs.readFileSync(abs(m.file)))
  const same = after === before.hash
  if (same) restoredOk += 1
  const verdict = verdictOf(result)
  if (verdict === 'KILLED') killed += 1
  else if (verdict === 'SURVIVED') { survived += 1; survivors.push(m.id) }
  else { errored += 1; errors.push(m.id) }
  log(`#${m.id} [${verdict}] ${m.file} :: ${m.rule}\n    broke ${m.all ? occurrences : 1}x: ${JSON.stringify(m.find).slice(0, 120)}\n    failing tests (${result.leaf.length}, by the ${where}): ${result.leaf.slice(0, 2).join(' | ')}\n    runner: status ${result.status}, tests ${result.tests}, fail ${result.fail}${result.timedOut ? ', TIMED OUT' : ''}; restored byte for byte: ${same ? 'yes' : 'NO'}  (${result.seconds}s)`)
  if (!same) { broken += 1; log('!!! RESTORE MISMATCH: stopping'); process.exit(3) }
}

const final = runTests(SPEC.tests)
let allSame = true
for (const rel of files) if (sha(fs.readFileSync(abs(rel))) !== original[rel].hash) allSame = false
log(`\nSUMMARY: ${ran} mutations run, ${killed} killed, ${survived} survived${survivors.length ? ' (' + survivors.join(', ') + ')' : ''}, ${errored} errored or skipped${errors.length ? ' (' + errors.join(', ') + ')' : ''}; ${restoredOk} of ${ran} restores byte-identical (sha256)`)
log(`AFTER: every touched file equals its backup: ${allSame ? 'yes' : 'NO'}; whole suite after the run: status ${final.status}, tests ${final.tests}, pass ${final.pass}, fail ${final.fail}`)
log(`finished ${new Date().toISOString()}`)
