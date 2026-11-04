# Signal Review in the LIVE Signals navigation: run, not just read (3 Nov 2026, 20:50 IST)

Question: once the live front end (origin/main = b32f436, Harshit's design) is merged, is a Signal Review menu row under Market Signals still reachable from the sidebar?

Method: the real `lib/signals-nav.ts` was extracted from origin/main with `git show` (nothing in the working tree changed) and its `collapseSignalsNav` was run with `node --import tsx` over a menu tree shaped like the dev menu (Command Center, Market Signals with its four tabs and Signal Inbox, Organizations, Products), twice. Script: `D:\dev\agentic-crm-research\scripts\browser\run-live-nav.mts` (copy of the scratch file).

```

== A) Signal Review under Market Signals (today)
- Sales & Marketing Intelligence  (/module/crm)
  - Command Center  (/gtm/command-center)
  - Market Signals  (/signals)
  - Organizations  (/module/crm/marketing/organizations)
  - Products  (/module/crm/sales/products)
   -> "Signal Review" in the sidebar: NO (unreachable from the sidebar)

== B) Signal Review at the Sales & Marketing root (after the move)
- Sales & Marketing Intelligence  (/module/crm)
  - Command Center  (/gtm/command-center)
  - Market Signals  (/signals)
  - Organizations  (/module/crm/marketing/organizations)
  - Products  (/module/crm/sales/products)
  - Signal Review  (/signal-review)
   -> "Signal Review" in the sidebar: YES
```

Result (verified by running it): under Market Signals the row is dropped from the sidebar; at the Sales & Marketing root it is shown. That is why `2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root` exists. Not yet checked: the same thing in a real browser on the merged front end (after `origin/main` is merged into `milan1`).
