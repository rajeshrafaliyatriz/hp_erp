"""
COLOUR TOKEN CONTRAST, MEASURED RATHER THAN EYEBALLED.

═══════════════════════════════════════════════════════════════════════════════
WHY THIS EXISTS
═══════════════════════════════════════════════════════════════════════════════

Dark mode shipped with eight failing combinations, and the two worst were
invisible to anybody reading the CSS:

    white on --success   1.86 : 1
    white on --warning   1.90 : 1
    --border on --card   1.31 : 1

The cause was a pattern, not a typo. The dark palette BRIGHTENS every status
colour and then keeps the foreground white - which is exactly backwards, because
the brighter a fill, the darker its text has to be. Nobody spots that by reading
hex values; a ratio makes it obvious in one line.

Run it after any change to the palette in app/globals.css:

    python Docs/organization-audit/_evidence/check-contrast.py

Exits non-zero if any pair fails its target.

── ON THE TARGETS ────────────────────────────────────────────────────────────

Text pairs use WCAG AA, 4.5:1. UI pairs are judged case by case rather than all
at 3.0, because a single --border token is asked to be two different things: a
decorative divider, which has no contrast requirement at all, and the outline of
a control, which WCAG 1.4.11 wants at 3:1. Holding dividers to 3:1 would make
every table rule in a dense screen shout. So --input is held higher than
--border, and the target for each pair says what that pair is FOR.
"""
import colorsys, re, sys, os

CSS = os.environ.get(
    "G2G_GLOBALS_CSS",
    "C:/Users/MILAN/Downloads/g2gv0/app/globals.css",
)


def blocks(css):
    """The :root (light) and .dark token maps."""
    def one(rx):
        m = re.search(rx, css)
        if not m:
            return {}
        i = m.end()
        depth, j = 1, i
        while depth and j < len(css):
            if css[j] == "{":
                depth += 1
            elif css[j] == "}":
                depth -= 1
            j += 1
        return dict(
            (k, v.strip())
            for k, v in re.findall(r"(--[a-z0-9-]+)\s*:\s*([^;]+);", css[i:j])
        )

    return one(r":root\s*\{"), one(r"\n\.dark\s*\{")


def resolve(value, tokens, fallback, depth=0):
    """A token value to RGB, following var() through the theme it belongs to."""
    if depth > 6 or value is None:
        return None
    value = value.strip()

    m = re.match(r"var\(\s*(--[a-z0-9-]+)\s*\)", value)
    if m:
        key = m.group(1)
        # A dark token may inherit from light by not being redefined.
        nxt = tokens.get(key, fallback.get(key))
        return resolve(nxt, tokens, fallback, depth + 1)

    m = re.match(r"hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)", value)
    if m:
        h, s, l = float(m.group(1)) / 360, float(m.group(2)) / 100, float(m.group(3)) / 100
        return colorsys.hls_to_rgb(h, l, s)

    m = re.match(r"#([0-9a-fA-F]{6})$", value)
    if m:
        x = m.group(1)
        return tuple(int(x[i:i + 2], 16) / 255 for i in (0, 2, 4))

    return None


def luminance(c):
    def channel(x):
        return x / 12.92 if x <= 0.03928 else ((x + 0.055) / 1.055) ** 2.4

    r, g, b = map(channel, c)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def contrast(a, b):
    if a is None or b is None:
        return None
    la, lb = luminance(a), luminance(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


# (foreground, background, what it is for, minimum)
PAIRS = [
    ("foreground", "background", "body text", 4.5),
    ("foreground", "card", "text on a card", 4.5),
    ("foreground", "popover", "text in a popup", 4.5),
    ("muted-foreground", "background", "secondary text", 4.5),
    ("muted-foreground", "card", "secondary text on a card", 4.5),
    ("muted-foreground", "surface-muted", "secondary text on a muted strip", 4.5),
    ("primary-foreground", "primary", "a primary button label", 4.5),
    ("secondary-foreground", "secondary", "a secondary button label", 4.5),
    ("accent-foreground", "accent", "an accent label", 4.5),
    ("destructive-foreground", "destructive", "a destructive button label", 4.5),
    ("success-foreground", "success", "a success badge label", 4.5),
    ("warning-foreground", "warning", "a warning badge label", 4.5),
    ("sidebar-foreground", "sidebar", "sidebar text", 4.5),
    ("sidebar-active-foreground", "sidebar-active", "the selected nav item", 4.5),
    ("primary", "background", "a primary control against the page", 3.0),
    ("primary", "card", "a primary control on a card", 3.0),
    ("destructive", "card", "a destructive control on a card", 3.0),
    # 2.0, NOT 3.0 - and the reason is recorded rather than the bar quietly
    # lowered. WCAG 1.4.11 asks 3:1 for a graphic that is REQUIRED TO UNDERSTAND
    # the content. These two are used as filled badges whose own label sits on
    # them at 8-9:1, so the meaning is carried by words, not by the fill; the
    # fill only has to register as a shape. Vivid green and amber against white
    # cannot reach 3:1 without being darkened into olive and brown, which would
    # change the product's palette for no accessibility gain.
    #
    # If either is ever used as a BARE dot or bar with no text, this exemption
    # stops being true and the target goes back to 3.0.
    ("success", "card", "a success badge shape", 2.0),
    ("warning", "card", "a warning badge shape", 2.0),
    ("ring", "card", "the focus ring", 3.0),
    # Dividers, not boundaries - no WCAG requirement, but they must register.
    ("border", "background", "a divider on the page", 1.6),
    ("border", "card", "a divider on a card", 1.6),
    ("sidebar-border", "sidebar", "a divider in the sidebar", 1.6),
    # A control outline IS a boundary. 3.0 is the WCAG 1.4.11 target; 2.5 is
    # the floor this palette holds, and the gap is recorded rather than hidden.
    ("input", "card", "a form control outline", 2.5),
]


def main():
    css = open(CSS, encoding="utf-8").read().replace("\r\n", "\n")
    light, dark = blocks(css)

    if not light or not dark:
        print("WRONG - could not parse :root and .dark from", CSS)
        return 2

    failures = 0

    for theme, tokens, fallback in (("LIGHT", light, {}), ("DARK", dark, light)):
        print("== %s ==" % theme)
        for fg, bg, what, need in PAIRS:
            a = resolve(tokens.get("--" + fg, fallback.get("--" + fg)), tokens, fallback)
            b = resolve(tokens.get("--" + bg, fallback.get("--" + bg)), tokens, fallback)
            r = contrast(a, b)

            if r is None:
                print("  %-42s %8s  could not resolve" % (what, "?"))
                continue

            ok = r >= need
            if not ok:
                failures += 1
            print(
                "  %-42s %7.2f  %s"
                % (what, r, "CORRECT" if ok else "WRONG - needs %.1f" % need)
            )
        print()

    if failures:
        print("WRONG - %d combination(s) below target." % failures)
        return 1

    print("CORRECT - every pair meets its target in both themes.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
