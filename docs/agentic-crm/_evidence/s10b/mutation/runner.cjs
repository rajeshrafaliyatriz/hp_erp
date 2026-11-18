'use strict'
/*
 * Mutation runner for the S10b front end (docs/agentic-crm/slices/S10-outreach-gate.md, S10b log).
 *
 *   node lib/outreach/mutation/runner.cjs <manifest.cjs> <out.txt> [id-prefix]
 *
 * For each mutant: the anchor text must occur EXACTLY once in the file; the file is changed; the test files run in order and the first one that fails kills
 * the mutant; the file is restored from the bytes read before the run and its sha1 is compared (a mismatch stops everything). A green baseline comes first: a
 * command that cannot run would otherwise look like a kill.
 */
const { spawnSync } = require('node:child_process')
const crypto = require('node:crypto')
const fs = require('node:fs')
const path = require('node:path')

const root = process.cwd()
const manifest = require(path.resolve(process.argv[2]))
const out = path.resolve(process.argv[3])
const only = process.argv[4] || ''

const TESTS = [
  'lib/outreach/model.test.ts',
  'lib/outreach/no-send.test.ts',
  'lib/outreach/contrast.test.ts',
  'lib/outreach/mobile-width.test.ts',
  'lib/outreach/outreach-screen.test.tsx',
]

const sha = (buffer) => crypto.createHash('sha1').update(buffer).digest('hex')
const log = (line) => { fs.appendFileSync(out, line + '\n'); console.log(line) }

function run(file) {
  const r = spawnSync(process.execPath, ['--import', 'tsx', '--test', file], { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 280000 })
  const text = `${r.stdout || ''}\n${r.stderr || ''}`
  const failed = []
  for (const line of text.split(/\r?\n/)) {
    const m = /^(\s+)not ok \d+ - (.*)$/.exec(line)
    if (m && !failed.includes(m[2])) failed.push(m[2])
  }
  const tests = /^# tests (\d+)/m.exec(text)
  const fail = /^# fail (\d+)/m.exec(text)
  return { status: r.status, timedOut: r.error && r.error.code === 'ETIMEDOUT', failed, tests: tests ? Number(tests[1]) : 0, fail: fail ? Number(fail[1]) : -1 }
}

function writeRetry(file, buffer) {
  for (let i = 0; i < 5; i++) {
    try { fs.writeFileSync(file, buffer); return } catch (e) { Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500) }
  }
  throw new Error(`could not write ${file}`)
}

fs.writeFileSync(out, `S10b mutation proofs, started ${new Date().toISOString()}\n`)

// Baseline
let total = 0
for (const t of TESTS) {
  const r = run(t)
  total += r.tests
  if (r.status !== 0 || r.fail !== 0 || r.tests === 0) { log(`BASELINE NOT GREEN: ${t}: status ${r.status}, tests ${r.tests}, fail ${r.fail}, failing: ${r.failed.join(' | ')}`); process.exit(2) }
}
log(`BASELINE GREEN: ${TESTS.length} files, ${total} tests`)

let killed = 0
let survived = 0
const survivors = []
for (const m of manifest) {
  if (only && !m.id.startsWith(only)) continue
  const file = path.resolve(root, m.file)
  const original = fs.readFileSync(file)
  const text = original.toString('utf8')
  const count = text.split(m.find).length - 1
  if (count !== 1) { log(`${m.id}: ANCHOR OCCURS ${count} TIMES in ${m.file} (need exactly 1): ${JSON.stringify(m.find.slice(0, 80))}`); process.exit(3) }
  const mutated = text.replace(m.find, () => m.replace)
  if (mutated === text) { log(`${m.id}: the mutant changes nothing`); process.exit(3) }
  let verdict
  try {
    writeRetry(file, Buffer.from(mutated, 'utf8'))
    for (const t of m.tests || TESTS) {
      const r = run(t)
      if (r.status !== 0 || r.fail !== 0) { verdict = { by: t, failed: r.failed.slice(0, 3), timedOut: r.timedOut }; break }
    }
  } finally {
    writeRetry(file, original)
  }
  if (sha(fs.readFileSync(file)) !== sha(original)) { log(`${m.id}: RESTORE FAILED for ${m.file}, stopping`); process.exit(4) }
  if (verdict) {
    killed++
    log(`${m.id} KILLED by ${path.basename(verdict.by)}${verdict.timedOut ? ' (timeout)' : ''}: ${verdict.failed.join(' | ')}  [${m.what}]`)
  } else {
    survived++
    survivors.push(m.id)
    log(`${m.id} SURVIVED  [${m.what}]`)
  }
}
log(`DONE ${new Date().toISOString()}: ${killed} killed, ${survived} survived${survivors.length ? ` (${survivors.join(', ')})` : ''}`)
