// Builds the compact location files this application reads (countries, states/provinces, cities) from the
// "Countries States Cities Database" as published in the npm package @countrystatecity/countries (ODbL-1.0).
//
//   node build.mjs <path to node_modules/@countrystatecity/countries/dist/data>
//
// Output (next to this file):
//   countries.json          [{code, iso3, name, emoji, has_states}]            code = ISO 3166-1 alpha-2
//   states/<CC>.json        [{code, name, type}]                               code = the database's state code (ISO 3166-2 suffix)
//   cities/<CC>.json        {"<state code>": [{id, name}], ...}                "" = cities listed directly under the country
//   meta.json               {source, version, counts, built_at}
//
// Only names, codes and the database's own city ids are kept. Coordinates, translations, population, time zones and
// the postal-code data in the package are NOT copied. See NOTICE.md for the licence and credit that go with these files.
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const src = process.argv[2]
if (!src || !fs.existsSync(path.join(src, 'countries.json'))) {
  console.error('usage: node build.mjs <path to @countrystatecity/countries/dist/data>')
  process.exit(1)
}
const read = (f) => JSON.parse(fs.readFileSync(f, 'utf8'))
const byName = (a, b) => a.name.localeCompare(b.name, 'en', { sensitivity: 'base' })

fs.rmSync(path.join(here, 'states'), { recursive: true, force: true })
fs.rmSync(path.join(here, 'cities'), { recursive: true, force: true })
fs.mkdirSync(path.join(here, 'states'), { recursive: true })
fs.mkdirSync(path.join(here, 'cities'), { recursive: true })

const countries = []
let stateCount = 0
let cityCount = 0
const problems = []

for (const c of read(path.join(src, 'countries.json'))) {
  const dir = fs.readdirSync(src, { withFileTypes: true }).find((d) => d.isDirectory() && d.name.endsWith(`-${c.iso2}`))
  const states = []
  const cities = {}
  if (dir) {
    const sf = path.join(src, dir.name, 'states.json')
    const parsed = fs.existsSync(sf) ? read(sf) : []
    const seen = new Set()
    for (const s of parsed) {
      const code = String(s.iso2 ?? '')
      if (code === '' || seen.has(code)) {
        problems.push(`${c.iso2}: state ${s.name} has a missing or duplicate code "${code}"`)
        continue
      }
      seen.add(code)
      states.push({ code, name: String(s.name), type: s.type ? String(s.type) : null })
    }
    const addCities = (file, stateCode) => {
      if (!fs.existsSync(file)) return
      const ids = new Set()
      for (const x of read(file)) {
        if (ids.has(x.id)) continue
        ids.add(x.id)
        ;(cities[stateCode] ??= []).push({ id: Number(x.id), name: String(x.name) })
        cityCount++
      }
    }
    addCities(path.join(src, dir.name, 'cities.json'), '')
    for (const s of states) {
      const sd = fs.readdirSync(path.join(src, dir.name), { withFileTypes: true }).find((d) => d.isDirectory() && d.name.endsWith(`-${s.code}`))
      if (sd) addCities(path.join(src, dir.name, sd.name, 'cities.json'), s.code)
    }
    for (const k of Object.keys(cities)) cities[k].sort(byName)
  }
  states.sort(byName)
  stateCount += states.length
  countries.push({ code: String(c.iso2), iso3: String(c.iso3 ?? ''), name: String(c.name), emoji: c.emoji ? String(c.emoji) : null, has_states: states.length > 0 })
  fs.writeFileSync(path.join(here, 'states', `${c.iso2}.json`), JSON.stringify(states))
  fs.writeFileSync(path.join(here, 'cities', `${c.iso2}.json`), JSON.stringify(cities))
}

countries.sort(byName)
fs.writeFileSync(path.join(here, 'countries.json'), JSON.stringify(countries))
const version = fs.existsSync(path.join(src, 'version.json')) ? read(path.join(src, 'version.json')) : {}
fs.writeFileSync(path.join(here, 'meta.json'), JSON.stringify({
  source: '@countrystatecity/countries (Countries States Cities Database, dr5hn)',
  version: version.dataVersion ?? null,
  licence: 'ODbL-1.0',
  counts: { countries: countries.length, states: stateCount, cities: cityCount },
}, null, 2))
console.log({ countries: countries.length, states: stateCount, cities: cityCount, problems: problems.length })
for (const p of problems.slice(0, 20)) console.log('  problem:', p)
