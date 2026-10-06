import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("### Module write-ups: **26 of 32 sub-modules**",
              "### Module write-ups: **30 of 32 sub-modules \u2014 GATE C EFFECTIVELY CLOSED**\n\n"
              "The remaining 2 are CRM's deferred rows (Q-A4). **Next artefact: `08-connection-plan.md`.**")
t = t.replace("| Other (HRIT, Agentic, Reports, CRM) | 4 | pending |",
              "| **Other** (HRIT, Agentic, Reports, CRM) | 4 | \u2705 `other.md` |")
io.open(p, "w", encoding="utf-8").write(t)

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

# matched pair framing at the top of G-DATA-06
t = t.replace(
    "**This is promoted above the security findings deliberately.** The security gaps\n"
    "are breaches to be closed; **this one explains why the product does not function as\n"
    "a connected system.** Until now L-11 was an argument. It is now a measurement.",
    "**This is promoted above the security findings deliberately.** The security gaps\n"
    "are breaches to be closed; **this one explains why the product does not function as\n"
    "a connected system.** Until now L-11 was an argument. It is now a measurement.\n\n"
    "### \u2b50 READ WITH `G-FLOW-26` \u2014 THEY ARE A MATCHED PAIR AND TOGETHER THEY ARE THE DIAGNOSIS\n\n"
    "| | |\n"
    "|---|---|\n"
    "| **G-DATA-06 \u2014 the SUPPLY side** | the relationships that **do** exist are joined by **strings**, not keys |\n"
    "| **G-FLOW-26 \u2014 the DEMAND side** | three relationships the product is **sold on** do not exist at all \u2014 they are **named and not built** |\n\n"
    "**The single concrete illustration: the 9-box grid has performance on one axis\n"
    "and nothing to put on the other.** Performance has never been able to read a\n"
    "capability measurement, because the join was never built \u2014 only the word\n"
    "*competency* appears, as a dropdown label and a validator enum value.\n\n"
    "Neither finding alone explains the product's state. Together they do.")

# data-class fix order
t = t.replace("## G-SEC-12 \u2014 caller-supplied audit provenance",
    "## FIX ORDER FOR THE REMAINING TENANT LEAKS \u2014 by DATA CLASS, not route count\n\n"
    "**Decided 2026-08-06.** Route count is the wrong ordering: it optimises for\n"
    "closing many at once rather than for closing the worst first.\n\n"
    "| Tier | Data class | First items |\n"
    "|---|---|---|\n"
    "| **1** | **Candidate / personal data** | **`talent_interviewpanelController`** \u2014 interview panel records cover **candidates: people outside the company who never agreed to be in the system.** Once Q-D4's portal exists this is external PII and a leak is a **regulatory** matter, not only a commercial one. Then the other three C27 Talent controllers |\n"
    "| **2** | **Payroll-adjacent** | `PayrollController` \u2705 **done (D-004)**; `HrmsLeaveController`, `ApplyLeaveController`, `LeaveTypeController`, `LeaveSummaryReportController` |\n"
    "| **3** | **Credentials / integrations** | `ExcelAutomationAgentController@credentialStatus` \u2014 reports on **another tenant's integration credentials** |\n"
    "| **4** | **Competency and learning content** | `skillLibraryController` \u2705 **done (D-003)**; `skillcontroller`, `assignmentController`, `courseController`, the rest |\n\n"
    "**`talent_interviewpanelController` goes first among everything remaining**, ahead\n"
    "of `assignmentController` (6 routes) and `HrmsController` (3).\n\n"
    "---\n\n"
    "## G-SEC-12 \u2014 caller-supplied audit provenance")

# G-MAP-01 resolution
t = t.replace("**The only genuine one-line change is DELETING the button**, which is a\nuser-facing removal and needs explicit approval plus an R8 checklist. **Not done.**",
    "**RESOLVED 2026-08-06: the button was REMOVED**, with approval and an R8\nchecklist (`g2gv0` commit `cb2f6a5`). Not disabled, not annotated \u2014 **a control\nthat quietly does the wrong thing is worse than an absent one**: the user asked for\na role mapping, got a framework, and was told it succeeded. **M-03 is its\nreinstatement** and it stays gone until the create path exists.")
io.open(p, "w", encoding="utf-8").write(t)
print("recorded: matched pair, data-class order, G-MAP-01 resolution, 30/32")
