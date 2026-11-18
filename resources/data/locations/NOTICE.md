# Location data: source, licence and credit

`countries.json`, `states/*.json` and `cities/*.json` are an extract of the **Countries States Cities Database** (dr5hn,
https://github.com/dr5hn/countries-states-cities-database), taken from the npm package `@countrystatecity/countries`
(data version recorded in `meta.json`).

- **Licence:** Open Database Licence (ODbL) v1.0, https://opendatacommons.org/licenses/odbl/1.0/ . Individual contents are under the
  Database Contents Licence, https://opendatacommons.org/licenses/dbcl/1.0/ .
- **Credit:** Countries States Cities Database, dr5hn, https://github.com/dr5hn/countries-states-cities-database (ODbL 1.0).
- **Changes made here (`build.mjs`):** only country, state/province and city names, the database's own country and state codes, and its city ids
  were kept, regrouped into one file per country. Coordinates, translations, population, time zones and all postal-code data were not copied.
  The extract is rebuilt, not edited by hand: `node build.mjs <path to dist/data>`.
- **Not copied:** the package also carries notices for postal-code sources (Australian Bureau of Statistics, Luxembourg ACT, and others).
  None of that data is in these files; if postal codes are ever added, bring those notices along.
- **Share-alike:** ODbL requires that a publicly used *adapted database* stays under ODbL. Whether serving this extract through the
  application's API counts as public use of an adapted database is a legal question this repository cannot answer. Have the owner of the
  product confirm it before the data is exposed outside the organisation, and keep this notice with every copy of the files.
- **Accuracy:** community-maintained. A missing state or city is a gap in the source, not a statement about the place. The application
  never invents administrative divisions: a country with no states in the source shows none.
