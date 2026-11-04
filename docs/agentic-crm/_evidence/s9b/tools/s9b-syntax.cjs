'use strict'
// Read-only. For every mutation of the spec, apply it IN MEMORY and ask the TypeScript parser whether the result is still valid syntax. A mutation that is not
// valid syntax would "kill" tests only because the file no longer loads, which proves nothing about the rule it was meant to test. Changes no file.
const fs = require('node:fs')
const path = require('node:path')
const ROOT = 'C:/Users/MILAN/Downloads/g2gv0'
const SCRATCH = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad'
const ts = require(path.join(ROOT, 'node_modules/typescript'))
const spec = JSON.parse(fs.readFileSync(path.join(SCRATCH, process.argv[2] || 's9b-mutations.json'), 'utf8'))
const diagnosticsOf = (file, text) => {
  const kind = file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS
  const sf = ts.createSourceFile(file, text, ts.ScriptTarget.ES2022, true, kind)
  return (sf.parseDiagnostics || []).map((d) => ts.flattenDiagnosticMessageText(d.messageText, ' '))
}
let bad = 0
const baseDiag = {}
for (const m of spec.mutations) {
  const original = fs.readFileSync(path.join(ROOT, m.file), 'utf8')
  baseDiag[m.file] = baseDiag[m.file] ?? diagnosticsOf(m.file, original).length
  const mutated = original.split(m.find).join(m.replace)
  const d = diagnosticsOf(m.file, mutated)
  if (d.length > baseDiag[m.file]) { bad += 1; console.log(`#${m.id} ${m.file}: NOT VALID SYNTAX AFTER THE MUTATION: ${d.slice(0, 2).join(' | ')}`) }
}
console.log(`${spec.mutations.length} mutations checked, ${bad} with invalid syntax; originals have ${JSON.stringify(baseDiag)} parse errors`)
