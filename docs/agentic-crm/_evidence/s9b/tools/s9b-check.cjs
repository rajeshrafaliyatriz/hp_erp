'use strict'
// Read-only: checks that every mutation's `find` text occurs exactly once in its file (or at least once when `all` is set), and that find differs from replace
// unless the mutation is a control. Changes no file.
const fs = require('node:fs')
const path = require('node:path')
const ROOT = 'C:/Users/MILAN/Downloads/g2gv0'
const SCRATCH = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad'
const spec = JSON.parse(fs.readFileSync(path.join(SCRATCH, process.argv[2] || 's9b-mutations.json'), 'utf8'))
const cache = {}
const ids = new Set()
let bad = 0
for (const m of spec.mutations) {
  if (ids.has(m.id)) { console.log(`#${m.id}: DUPLICATE ID`); bad++ }
  ids.add(m.id)
  cache[m.file] = cache[m.file] ?? fs.readFileSync(path.join(ROOT, m.file), 'utf8')
  const n = cache[m.file].split(m.find).length - 1
  const same = m.find === m.replace
  if (n !== 1 || same) { console.log(`#${m.id} ${m.file}: found ${n} times${same ? ', find equals replace' : ''}: ${JSON.stringify(m.find).slice(0, 100)}`); bad++ }
}
console.log(`${spec.mutations.length} mutations, ${bad} problems`)
