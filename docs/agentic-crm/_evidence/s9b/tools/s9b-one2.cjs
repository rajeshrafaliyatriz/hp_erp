const fs = require('node:fs'), path = require('node:path'), { spawnSync } = require('node:child_process')
const ROOT = 'C:/Users/MILAN/Downloads/g2gv0'
const SCRATCH = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad'
const spec = JSON.parse(fs.readFileSync(path.join(SCRATCH, process.argv[2]), 'utf8'))
const m = spec.mutations.find((x) => String(x.id) === process.argv[3])
const file = path.join(ROOT, m.file)
const before = fs.readFileSync(file)
const text = before.toString('utf8')
try {
  fs.writeFileSync(file, Buffer.from(text.split(m.find).join(m.replace), 'utf8'))
  const r = spawnSync(process.execPath, ['--import', 'tsx', '--test', ...process.argv.slice(4)], { cwd: ROOT, encoding: 'utf8', maxBuffer: 200 * 1024 * 1024, timeout: 240000 })
  fs.writeFileSync(path.join(SCRATCH, 'one-out.txt'), `${r.stdout}\n----stderr----\n${r.stderr}`)
} finally { fs.writeFileSync(file, before) }
console.log('restored', Buffer.compare(fs.readFileSync(file), before) === 0)
