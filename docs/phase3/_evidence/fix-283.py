import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

CLARIFY = ("**One line, because someone will ask:** the headline counts **rows with a populated\n"
           "string key \u2014 283,126**. The four tables hold **283,127 rows**; one row of\n"
           "`s_user_jobrole_task` has an empty `jobrole`. *Rows* and *rows with a key* are\n"
           "different claims; the headline is the second.")

# 08-connection-plan
p = os.path.join(D, "08-connection-plan.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("> **283,127 rows** carry the product's core relationships",
              "> **283,126 rows** carry the product's core relationships")
t = t.replace("- **Not 283,127 defects.** It is 283,127 rows across four tables, each resolving its relationship by string. *(Of these, 283,126 carry a populated key; one row's is empty. Quote the row count.)*",
              "- **Not 283,126 defects.** It is 283,126 rows across four tables, each resolving its relationship by string.")
t = t.replace("| **283,127** | rows whose relationships resolve by string, across four tables verified individually",
              "| **283,126** | rows whose relationships resolve by string, across four tables verified individually")
t = t.replace("**That is the whole diagnosis in one screen.**",
              "**That is the whole diagnosis in one screen.**\n\n" + CLARIFY)
t = t.replace("\u26a0\ufe0f **Headline numbers corrected by `12-gate-c-verification.md` V6** \u2014 283,127 (not 283,126), 2.7% (not 3.0%), 29 controllers (not 30).",
              "\u26a0\ufe0f **Headline numbers corrected by `12-gate-c-verification.md` V6** \u2014 **283,126** (rows with a populated key; 283,127 rows exist), 2.7% (not 3.0%), 29 controllers (not 30).")
io.open(p, "w", encoding="utf-8").write(t)

# 12-gate-c-verification
p = os.path.join(D, "12-gate-c-verification.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("**Use 283,127** and describe it as *rows*. The difference is one row and changes\nnothing material \u2014 **but a headline number quoted to a board must be derived one\nway.**",
              "**Settled 2026-08-07: the headline is 283,126 \u2014 ROWS WITH A POPULATED STRING KEY.**\n"
              "The four tables hold 283,127 rows; one `s_user_jobrole_task` row has an empty\n"
              "`jobrole`. Either figure is defensible; **the headline is the second, and the\n"
              "distinction is stated wherever it appears.**")
t = t.replace("| **283,127 rows** across four tables | ~~283,126~~ |",
              "| **283,126** rows **with a populated key** (283,127 rows exist) | ~~an unqualified 283,127~~ |")
io.open(p, "w", encoding="utf-8").write(t)

# 13-current-state
p = os.path.join(D, "13-current-state.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("My files are right: the table has **85,663 rows**, of which 85,662 have a populated key. **Use 85,663 \u2192 283,127 rows**",
              "**Settled 2026-08-07: the headline is 283,126 \u2014 rows with a populated key.** The tables hold 283,127 rows; one `s_user_jobrole_task` row has an empty `jobrole`. The recap's total was mine and paired a corrected total with an uncorrected component \u2014 **R19's failure mode inside the document written to prevent drift**")
io.open(p, "w", encoding="utf-8").write(t)

# R18 extension
p = os.path.join(D, "07-gap-register.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| **R19** |",
    "| **R18b** | **Anything merged verbatim from a recovery carries a DATE STAMP.** A stale line looks identical to a current one | *\"2 of 32 delivered\"* came back in a recovered Gate checklist and **survived three write-ups unnoticed**, contradicting a line 80 rows above it |\n| **R19** |")
io.open(p, "w", encoding="utf-8").write(t)
print("283,126 settled; R18b added")
