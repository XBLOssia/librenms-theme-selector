# Keeping up with LibreNMS's design

A skin is colours laid over LibreNMS's own markup and stylesheets. When upstream moves something, nothing
fails: a badge turns into an icon, a new component paints itself in stock Tailwind greys, a class we style is
deleted, and the skins quietly look a little wrong. The live suites (`dev/test.sh live`, run nightly against the
newest *release* by [nightly.yml](../.github/workflows/nightly.yml)) find what breaks. This is the other half:
what merely looks wrong, on LibreNMS **master**, which is what a host that updates itself nightly runs.

## What runs, and when

[`.github/workflows/upstream-design.yml`](../.github/workflows/upstream-design.yml) runs every Monday (and from
the Actions tab, **Upstream design check, Run workflow**). It reads a checkout of `librenms/librenms` master
(only `resources/`, `html/css/`, `html/js/` and `includes/html/`; it runs none of it) with
[`scripts/upstream-drift.py`](../scripts/upstream-drift.py) and compares it with
[`docs/data/upstream-baseline.json`](data/upstream-baseline.json): what we last looked at and answered.

If there is something new it opens one issue labelled `upstream-design` (and keeps it up to date, rather than
opening another every week). When the skins have caught up and the baseline is re-recorded, the next run
closes the issue. A failure of the check itself fails the run, so a quiet week is a real one.

## What it compares

| List in the issue | What it means | What to do |
|---|---|---|
| **Files that skins sit on have changed** | The layout, the navbar template, `styles.css`, `tw_dark.css` or `app.css` changed. Not necessarily a problem; the cheapest signal that something visual moved. | Open the linked diff. Most are routes and plumbing. Look for markup or classes the navbar, panels, tables, buttons, labels and forms use. |
| **Dark / light: Tailwind colours that no skin re-points** | Markup now uses a colour utility such as `tw:dark:bg-amber-950/40`. Tailwind reads it from `--tw-color-amber-950`, so any shade we have not mapped shows in stock Tailwind colours on every skin. | Map the shade to the role it plays there: the `html.dark { --tw-color-… }` block near the end of `base/base.css`, or `base/light.css`. Read how the markup uses it first: the same variable serves text, borders and backgrounds. |
| **New classes with colours of their own** | A class in upstream's stylesheets that sets a colour and that `base.css` does not mention. | Style it (or decide it is dead markup) in `base/base.css`. |
| **Classes upstream's stylesheets now define / no longer define** | Information: the list behind the rest. | Skim it. |
| **Gone upstream** | Classes `base.css` styles that nothing upstream uses any more. The rules are dead, not harmful. | Leave them. Do **not** delete a token a skin may set: that would break uploaded skins that set it (a major version). |

## Answering it

1. Update the skins. The harness (`harness/mockup.html`) is hand-written markup, so change it to what LibreNMS
   now renders first; then you can see the problem and the fix (`python -m http.server 8777`, see
   [PREVIEWING.md](PREVIEWING.md)). Screenshots: `scripts/capture-mockups.sh`.
2. Run the tests (`sh dev/test.sh`).
3. Re-record the baseline **in the same change**, against a LibreNMS checkout at the commit you looked at:

   ```bash
   git clone --depth 1 --filter=blob:none --sparse https://github.com/librenms/librenms.git /tmp/librenms
   git -C /tmp/librenms sparse-checkout set resources html/css html/js includes/html
   python scripts/upstream-drift.py /tmp/librenms --update
   ```

   The baseline records the gaps that remain as *acknowledged*, so run it after the skins have caught up, or
   after deciding a gap does not matter. To see what it would report first: leave out `--update`
   (exit 0 is nothing new, 1 is drift; `--report report.md` also writes the Markdown).

`python scripts/upstream-drift.py --selftest` builds a toy LibreNMS, changes it each way the check should notice
and checks that it does. CI and `dev/test.sh` run it.

## What it cannot see

It reads source, not pages. A component whose colours come from a stylesheet it does not scan, inline styles built
in PHP, or a layout that moves without a class changing will not show here; the live audit (`harness/audit.js`
against a running instance) and your own eyes on a real instance remain the check for those. And it can't tell you
whether a mapping *looks* right, only that one is missing.

## History

The first pass (2026-10-12) went through 232 upstream commits since the survey at `63e0394` (2026-09-17):
the Alerts count became an icon ([librenms#19891](https://github.com/librenms/librenms/pull/19891), then
[#20836](https://github.com/librenms/librenms/pull/20836)); the device page, filters, date picker and hover
cards were rebuilt on Tailwind greys, blues and tints that no skin pointed at; `.lnms-input` appeared with a
text colour no skin could beat; and the Vue multiselect styles were removed with the move to Alpine. The skins
were updated for each and the baseline recorded.
