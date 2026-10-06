import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("### Module write-ups: **19 of 32 sub-modules**",
              "### Module write-ups: **26 of 32 sub-modules**")
t = t.replace("| Talent | 7 | pending |", "| **Talent** | 7 | \u2705 `talent.md` |")
io.open(p, "w", encoding="utf-8").write(t)

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()
anchor = "## G-SEC-12 \u2014 caller-supplied audit provenance"
new = """## G-FLOW-26 \u2014 a vocabulary of connection without the connection · **S2**

**Three modules now show the same shape: the word "competency" is present, the join
is not.**

| Thread | Where | What exists | What does not |
|---|---|---|---|
| **3** \u00b7 Competency \u2194 LMS | `library-config.ts:172` | a *Learning Resources* **text field** | any course reference (`L-08`) |
| **5** \u00b7 Competency \u2194 Performance | `PerformanceGoalController.php:93,167`; `PerformanceOverviewController.php:314` | `'competency'` as a **validator enum value** and a **filter label** | **any join to `s_skill_matrix`, `s_users_skills` or a competency table \u2014 none exists in `Api/Performance/`** |
| **7** \u00b7 Competency \u2192 Recruitment | Q-D1 recorded the read as intended | nothing | **zero references to `s_user_skill_jobrole` / `s_jobrole_skills` in `Api/Talent/` or `talent_*`** |

### Why this is its own gap and not three

**A reader of the code would conclude these modules are connected.** The
vocabulary is there \u2014 a goal category called *competency*, a filter labelled
*Competency*, a field called *Learning Resources*. **Each is a label with no
referent.**

**The 9-box grid is the clearest casualty:** it has performance on one axis and
**nothing to put on the other**, because Performance has never been able to read a
capability measurement.

**This is the demand-side counterpart to `G-DATA-06`.** G-DATA-06 says the
relationships that *do* exist are joined by string; **G-FLOW-26 says three of the
relationships the product is sold on do not exist at all** \u2014 they are named and
not built.

**Connections:** `TL-02` (Performance), `TL-03` (Recruitment), `L-08` (LMS).

---

"""
t = t.replace(anchor, new + anchor, 1)
io.open(p, "w", encoding="utf-8").write(t)
print("Talent recorded; G-FLOW-26 raised")
