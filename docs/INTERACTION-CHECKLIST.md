# LibreNMS interaction checklist

Use this checklist when LibreNMS changes a page, control or interaction that a Theme Selector skin sits on.
The source comparison identifies candidates; the browser checks determine what actually works. A listed
area is not a claim that all of its pages or states have been tested.

The machine-readable upstream family IDs and source paths are in [interaction-checklist.json](data/interaction-checklist.json).
`theme-selector` is specific to this repository and is not in the upstream path map.
The comparison starts at upstream `c1e2715a6ef6444def15d3c9dd9c6c23fe2f0077`, inherited from the existing
visual-design checkpoint. That commit is a starting point, not an assertion that all interactions at it
were reviewed. The source comparison does not update or accept the checkpoint automatically.

## What to exercise

For each changed area, record the page, control, action and expected result, upstream revision or release,
Theme Selector revision, skin and mode, viewport, fixture and outcome. Keep **untested**, **passed in the
stated scope**, and **failed** distinct. A file mapping means a reviewer knows which checklist family to
inspect; it does not mean a new control within that file has been tested.

| ID | Page or area | Interactions and states to check |
|---|---|---|
| `shared` | Navbar and shared components | Menus, search, keyboard focus, tooltips, hover cards, popovers, overlays and click-outside behavior; check stacking and clipping. |
| `settings` | Settings and poller settings | Expand/collapse, help and copy-link icons, tooltips, switches, selects, dynamic arrays, validation and nested dialogs. |
| `devices` | Device lists, details, dependencies and edit pages | Filters, rows and hover cards, paging, menus, dependency dialog, Select2, maintenance dialog, tabs and cancel/close. |
| `ports` | Port lists and device port editing | Filters, help, sorting, selections, group menus, bulk controls, speed and reset dialogs; use synthetic devices and ports. |
| `polling` | Polling methods and secrets | Selects, add/remove methods, validation and unsaved-change dialogs, using invented values only. |
| `alerts` | Alerts, rules, faults and history | Rule builder, filters, row expansion, details, acknowledgement and notes dialogs, history and chart controls. |
| `logs` | Event log and filtered tables | Device/type filters, Select2, date controls, sorting, paging and empty/populated states. |
| `dashboard` | Dashboard and widgets | Widget menus, hover cards, add/remove and configuration dialogs, resize/drag and overflow near other widgets. |
| `maps` | Maps and editors | Hover cards, controls, editor help, selection, modal and overflow at viewport edges. |
| `account` | User and API access pages | Account controls, token dialogs, validation and close/cancel, using disposable test accounts and invented values. |
| `install` | Installation and setup | Setup forms, validation, progress and error states on a disposable instance. |
| `routes` | Page entry points | New or moved routes, links and permission-dependent variants; associate each new page with a family or add one. |
| `theme-selector` | Plugin picker and uploads | Each mode's selection and preview, apply, stock/default choices, upload, validation and removal on a disposable instance. These are plugin files, so inspect their changes in this repository too. |

## Test matrix

For a changed interaction, start with stock LibreNMS as a control, an ornamented dark skin, an ornamented
light skin and a non-ornamented skin. Exercise both desktop (1366×768) and narrow (390×844) viewports,
and hover, click, keyboard and close/cancel as applicable. The narrow viewport checks layout with a pointer;
it is not a physical touch-device test. For a change to shared mechanics, also test every bundled skin in
both modes and representative uploaded-skin examples. Check a real uploaded bundle separately when an
installer or upload path is affected. Never use production devices, credentials or routes as fixtures.

The browser scripts need Node.js, PHP, Playwright, Chrome and a localhost-only dev instance:

```bash
node dev/test-dialogs.cjs --url http://127.0.0.1:8000
node dev/test-tooltips.cjs --url http://127.0.0.1:8000
```

`test-dialogs.cjs` needs a LibreNMS build with the Alpine dependency dialog (tested on 26.10.2); it checks
the real dependency page plus synthetic nested-panel and Bootstrap fixtures. `test-tooltips.cjs` uses
synthetic markup and the instance's real Bootstrap and Font Awesome assets. Both use the working tree's
base and skin files, include the three example token files, and generate the light mirror through the
publisher's code. They are local, explicit browser checks, not currently part of `dev/test.sh live` or
the GitHub workflows. See [dev/README.md](../dev/README.md) for filtering the matrix and setup.

## Starting evidence and open work

On 2026-10-28, a disposable LibreNMS 26.10.2 instance and synthetic device were examined with Theme
Selector at `731ce22` before this dialog fix. Settings accordion/label tooltip, device-port help,
dependency Select2 selection, event-log dropdown opening, alert-rule dialog open/Escape and dark-slot
picker preview were exercised on stock, Gotham dark and Daylight light at 1500×1000. The dependency
dialog close button was covered at 1366×768 in all six native-mode ornamented bundled skins; stock and
the three non-ornamented skins passed that close-button check. At 390×844, the overlap was also observed
in Gotham dark and Daylight light. That initial pass did not cover save operations, physical touch or real
uploaded bundles.

With the dialog fix in the working tree based on `731ce22`, the 26.10.2 browser regression passed all 48
combinations of nine bundled skins and three custom-skin examples, two modes and two viewport sizes. It
exercised the real dependency dialog plus synthetic nested-panel/heading, tooltip and Bootstrap fixtures.
An actual `slate-teal` example bundle was also uploaded to the disposable instance and its dialog checked
in both modes at both sizes; the browser was supplied the working-tree base CSS. This is local evidence,
not a production deployment or a full upload workflow revalidation.

The source inventory also found newer alert faults/history/details and device polling/modules/routing
changes on upstream master `52b1a675741bab8953e9542259bd667c41b29ac4`. Those master pages were inspected
as source only; their rendered behavior and alert-row interactions remain **untested**. Seed synthetic
alerts and devices in a disposable build of the exact upstream revision before recording a result.
Dashboard, map, account, install and many other rows above also remain **untested** for this pass.

## Compare an update

The checker compares **committed Git trees**. It lists every changed watched view, script, stylesheet,
component, controller, route and frontend dependency file; new or removed paths are listed separately
(renames appear as a removal and an addition). It maps paths to the families above and marks unmapped
ones for classification. A changed file in a known family still needs review for new controls; the tool
does not infer a pass from a match or try to guess dynamic runtime behavior.

```bash
git clone --depth 1 --filter=blob:none --no-checkout https://github.com/librenms/librenms.git /tmp/librenms-interactions
git -C /tmp/librenms-interactions fetch --no-tags --filter=blob:none --depth=1 origin c1e2715a6ef6444def15d3c9dd9c6c23fe2f0077
python scripts/interaction-drift.py /tmp/librenms-interactions

# On a later update: compare the fetched master, not the old local HEAD.
git -C /tmp/librenms-interactions fetch --no-tags --filter=blob:none --depth=1 origin master
python scripts/interaction-drift.py /tmp/librenms-interactions --head FETCH_HEAD
```

Exit 0 means no watched source changed; 1 means candidates need review; 2 means the comparison could not
be completed. `--json` emits the same paths and family IDs as JSON. `--base <commit>` and `--head <commit>`
allow a bounded comparison; both commits must exist in the checkout. The tool does not inspect uncommitted
work, fetch, write the checkout or contact the application. It uses Git's complete trees, avoiding a
truncated web comparison. `python scripts/interaction-drift.py --selftest` checks detection against a
small temporary Git history.

Review additions, removals, mapped changes and unmapped paths; update this checklist and the family path
patterns as needed. Advance `comparison_base` in `docs/data/interaction-checklist.json` only after the
candidate changes have been triaged, with any remaining untested behavior explicitly recorded. Do not
advance it merely to make the checker quiet. Existing [visual drift checks](UPSTREAM-DRIFT.md) continue
independently; neither check can prove that a dynamically generated interaction works.
