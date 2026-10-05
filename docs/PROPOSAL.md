# Upstream proposal

**POSTED 2026-09-21:** [community.librenms.org → Projects](https://community.librenms.org/t/a-theme-system-for-librenms-a-phased-proposal/29463)

What follows is the source the post was built from. It is kept as the record
of what was said and why. **Do not silently edit it to match later thinking** —
the thread is public, so a correction belongs in a reply, and this file should
show what was actually claimed. Verified after posting: 14 headings, 5 code
blocks and 3 tables all survived Discourse's markdown intact.

Everything above `### A theme system for LibreNMS` was internal and not posted.

> **Corrections, 2026-10-02 (the posted text below is unchanged).** Re-measured against
> LibreNMS 26.9.1; see FINDINGS.md "Re-measured on 2026-10-02" for the full list. What differs
> from what was posted: "1,233 graph definitions" (twice) is **1,313**; "595 uses" of `tw:`
> colour utilities is 596; "264 distinct first-party colours" is 265 (269 on master);
> `device_bits` renders through `generic_multi_seperated.inc.php`, not
> `generic_multi_bits_separated.inc.php` (both read `graph_colours`);
> the `tw:bg-white!` "on the date-range field … `show.blade.php:53`" is on the popup
> (`popup.blade.php:14`), while `show.blade.php:53` carries `tw:dark:bg-white!`; and Phase 0d's
> contextual-row fix, #20594, **merged 2026-09-29**. Everything else measured here reproduces.

**Venue:** [community.librenms.org → Projects](https://community.librenms.org/c/projects)
— GitHub Discussions is disabled on the repo (404), and Feature Requests has
1,200 topics (1,198 when this was written) with little maintainer traffic. Projects has 74
(73 then; the extra one is the proposal thread) and is described as
"a space for discussing ongoing development work and initiatives", which is
exactly what this is.

**~~Before posting~~ — done.** Said hello on Discord first, then posted.
murrant gave direct guidance on
[#19029](https://github.com/librenms/librenms/pull/19029) about sequencing, and
this is built on it. The PR template also warns that PRs may be closed without
explanation over LLM-generated submissions, so a human conversation first was
worth a lot.

---

## Suggested Discord opener

> Hi — I've been building third-party themes for LibreNMS and ended up mapping
> how colour is handled across the codebase. I'd like to propose working toward
> a proper theme system, in phases, starting with the legacy-colour cleanup
> murrant asked for on #19029. The early phases are pixel-identical refactors
> that are worth doing on their own merits even if the theme system never
> lands. Mind if I write it up in Projects?
>
> Being upfront: I'm using AI tooling for this — the measurement, the writing,
> and the code if it goes ahead. Every number comes with a command so you can
> check it rather than trust me, and I'll own and stand behind whatever PRs
> come out of it.

---

## Forum post

### A theme system for LibreNMS — a phased proposal

I want to be clear about the destination before asking for anything: **I'd like
LibreNMS to have a real theme system** — one where an admin can install a theme
from a file, users can pick it, and custom themes can be removed while the
built-ins stay protected.

> **Superseded, 2026-09-22.** Two maintainers said plainly that installable
> themes are not wanted — *"LibreNMS isn't Wordpress"*, and *"I hope installable
> themes is not the goal"*. What was offered instead: **selectable built-in
> colour schemes, hosted in the LibreNMS codebase.** That is a narrower and
> better target, and Phases 0–2 are unchanged by it — they are the prerequisite
> either way. Phases 3–4 below are kept as written because the thread is public
> and this file is the record of what was actually proposed; see the revised
> Phase 3 note for what replaces them.

I'm not asking anyone to approve that today. I'm asking whether the direction is
welcome, because the first phases are things murrant has already asked for, and
they're worth doing whether or not the rest ever happens.

The reason I think this is smaller than it sounds: **most of the work is the
cleanup, not the feature.** Once colour lives in tokens, a theme is just a set
of token values, and storing, validating and selecting them is ordinary CRUD
that LibreNMS already does elsewhere.

---

### Up front: how this was produced

I used AI tooling (Claude) to explore the codebase, take the measurements, and
draft this post. I'm saying so before anything else, because the PR template
warns about low-quality LLM-generated submissions and that warning is fair — a
large mechanical refactor looks exactly like the thing it defends against.

What I can actually commit to:

- **Every number is reproducible.** The commands are below. Please don't take
  my word for any of them — a figure you can check yourself is worth more than
  an assurance from me.
- **Small, single-concern PRs.** Review effort stays bounded, and anything
  claimed as pixel-identical comes with before/after evidence.
- **I'm accountable for what I submit.** I read the diff before it goes up, I
  answer review comments, and I don't open a PR and disappear.
- **The assistance is ongoing, not just the drafting.** I'll be using AI tooling
  to write these changes and to work through review feedback too. I'd rather
  say that now than have it inferred later. If that's a dealbreaker for this
  project, tell me and I'll stop here — no hard feelings, and the measurements
  are yours to use either way.

For what it's worth, writing the reproduction commands caught an error in my own
figures: I'd been quoting "468 distinct colours", which turned out to include
`html/js/` — essentially all vendored (`leaflet`, `esri-leaflet`, `overlib`).
Excluding code LibreNMS doesn't own, the real first-party figure is 264. That's
the method working, and it's why I'd rather hand you commands than ask you to
trust a number.

---

### Reproduce every number

From a checkout at `63e0394`. All of these run in under a second.

```bash
# 264 distinct first-party colours (4 non-vendor stylesheets + all PHP/Blade)
{ grep -ohE '#[0-9a-fA-F]{6}\b' html/css/{styles,tw_dark,mono,blue}.css
  grep -rohE '#[0-9a-fA-F]{6}\b' --include='*.php' includes app resources LibreNMS
} | tr 'A-F' 'a-f' | awk '!seen[$0]++' | wc -l

# 0 font-family rules in the dark theme, against 272 hex literals
grep -c 'font-family' html/css/tw_dark.css
grep -ohE '#[0-9a-fA-F]{3,8}' html/css/tw_dark.css | wc -l

# 595 inline tw: colour utilities in Blade templates
grep -rohE 'tw:(dark:)?(bg|text|border|ring|divide)-[a-z]+-[0-9]{2,3}' \
     --include='*.blade.php' . | wc -l

# 58 colour literals in the shared graph helpers
grep -cE '#[0-9A-Fa-f]{6}' includes/html/graphs/generic_*.inc.php

# ...and how many graph definitions reference each helper
grep -rhoE 'generic_[a-z_]+[.]inc[.]php' includes/html/graphs/ \
  | awk '{c[$0]++} END {for (k in c) print c[k], k}'

# 89 graph files already using the graph_colours.* palettes
grep -rl 'graph_colours' includes/html/graphs/ | wc -l
```

---

### Where colour lives today

Five places, none of which know about each other. This is the actual problem —
not any individual value.

1. **`styles.css`** — 331 hex literals.
2. **`tw_dark.css`** — 272 hex literals. *Correction, from a maintainer: this
   is not "the dark theme". It is legacy Bootstrap overrides kept so older
   markup still works with the Tailwind theme toggle, and it carries a lot of
   dead CSS from an old convention of copying all of Bootstrap to recolour it.
   Some share of these literals wants deleting, not tokenising.*
3. **Inline `tw:` utilities in Blade templates** — 595 uses.
4. **JavaScript template strings.** The dashboard widget title bar is built by
   string concatenation with utilities inline and no class at all
   (`resources/views/overview/default.blade.php:516`), so its colour isn't in a
   stylesheet.
5. **Graph rendering.** `rrdgraph_def_text_dark` for chrome, `graph_colours.*`
   for series, and 58 hardcoded literals in the shared `generic_*` helpers.
   Ten of those fifteen helpers do read `graph_colours`; **five read no config
   at all** — 40 literals, 169 graph definitions, including every port traffic
   graph in the application.

   > **Correction, 2026-10-01 (the posted text above is unchanged).** "Five read no
   > config" is true but reads as one problem, and it is two. Only `generic_data`
   > hard-codes its series colours (6 of its 18 literals) and can be fixed on its own.
   > The other four take series colours from variables their callers set (about 150
   > files), and their own literals are percentile, previous-period and rule lines.
   > See FINDINGS.md §5.

**264 distinct first-party colours.** That isn't a palette; it's accretion.
Consolidated, it's plausibly 40–60 real tokens.

For contrast: `tw_dark.css` contains **zero** `font-family` declarations. I gave
my themes a full typographic treatment and not one rule needed a workaround —
plain selectors, first try. Same stylesheet, same load order, same afternoon.
The only variable is whether core hard-codes the property.

---

### The phases

Each is independently shippable and useful on its own. Phases 0–2 are the
cleanup murrant asked for. Phases 3–4 are the theme system, and they're
comparatively small *because* of 0–2.

#### Phase 0 — four small fixes, no theme system required

*(Three, as of 2026-09-22 — 0b is withdrawn, see below. Heading left as posted.)*

**0a. Drop the `!` from inline colour utilities.** 9 files, no visual change.

A `tw:…!` utility written inline in a template compiles to `!important` inside
Tailwind's `utilities` cascade layer. `webui.custom_css[]` is injected last and
is unlayered — and for `!important` declarations the cascade reverses, so
earlier layers win and unlayered `!important` is the weakest of all. A
stylesheet cannot retroactively place itself in an earlier layer, because layer
order follows first declaration and `app.css` already declared them.

So a theme **cannot override these with `!important` of its own, at any
specificity.** Measured on `tw:dark:text-red-500!` — the red device links on
`/eventlog`, at 3.3:1, below WCAG AA.

It *can* override them two other ways, and I want to be straight about this
because an earlier draft of my notes claimed it couldn't:

- **Redefine the theme variable** the declaration reads —
  `--tw-color-red-500`. The `var()` resolves at use time against the inherited
  custom property, and that lookup doesn't care about layers or importance.
- **Re-open `@layer utilities`** from `custom_css` and use `!important` there.
  Same layer, same importance, later source — it wins, even at specificity
  (0,1,0).

I originally tested `--color-red-500`, without the `tw` prefix LibreNMS
configures. Nothing is defined under that name, so nothing happened, and I read
the null result as a property of the cascade rather than as my own typo. Worth
saying plainly rather than having someone find it later.

That makes this a weaker argument than I first thought, but not an empty one:

- Both workarounds are couplings to **Tailwind internals**, not to anything
  LibreNMS promises. Change the prefix or the layer name and every theme
  silently reverts to stock, with no error anywhere.
- The variable route is **all or nothing**. Retinting `red-500` changes it
  everywhere; there is no way to fix one usage. `!important` on an inline
  utility is precisely a declaration that no one downstream may disagree with.

So it isn't *impossible → possible*. It's *requires two undocumented Tailwind
facts → requires nothing*. Deleting a character where nothing depends on it
still looks like the cheaper side of that trade.

```bash
grep -rhoE 'tw:(dark:)?(text|bg|border|ring|divide)-[a-z0-9-]+!' \
     --include='*.blade.php' . | awk '!s[$0]++'
```

**71 uses, 22 distinct, across 9 files.** They include `tw:bg-white!` and
`tw:dark:bg-white!` on the date-range field at
`resources/views/graphs/show.blade.php:53` — a white input in dark mode, on
every graph page in the application.

Where the `!` is load-bearing it should stay; where it isn't, dropping it costs
nothing and is invisible. Where it genuinely is needed, moving the declaration
into a component class in `app.css` also fixes it, because `@apply` output is
unlayered and therefore reachable.

**0b. ~~Give the dashboard widget header a class.~~ WITHDRAWN.** The premise
was that `.dashboard-widget-title` is only the inner `<span>` so the bar itself
is unreachable. It is not unreachable — the header carries
`tw:dark:bg-dark-gray-200`, and this repo's skins theme it both by element
selector and by redefining that theme variable. A maintainer flagged it and was
right. What is left is a preference for a semantic class over depending on
element position, which is not worth a maintainer's attention. See FINDINGS
§2b.

**0c. Tokenise the 58 colour literals in the shared graph helpers.** 15 files.
Pixel-identical if defaults keep current values. These helpers are referenced by
**1,233 graph definitions** (1,322 references — the per-helper counts sum
higher because a file can use more than one helper), so this is the best
effort-to-impact ratio in the whole proposal.

| Helper | Literals | References |
|---|---|---|
| `generic_stats.inc.php` | **1** | **527** |
| `generic_multi_line.inc.php` | **1** | **422** |
| `generic_simplex.inc.php` | 5 | 120 |
| `generic_duplex.inc.php` | 7 | 28 |
| **`generic_data.inc.php`** | **18** | **20** (incl. `port_bits`) |
| …10 more | 26 | 205 |
| **Total** | **58 across 15 files** | **1,322 refs / 1,233 distinct files** |

Ten of the fifteen already read `graph_colours`. **Five read no config at
all** — `generic_data`, `generic_duplex`, `generic_simplex`,
`generic_multi_data`, `generic_multi_bits` — and those five hold 40 of the 58
literals.

What that looks like to a user is worth spelling out, because it reads as a bug
rather than a gap. Every graph in the application renders through one endpoint,
`/graph/id=<id>?type=<type>`, so the *page* is never the variable — the `type`
is. Sampling the rendered PNGs on an instance with a themed `graph_colours`:

| `type` | Helper | Dominant colours in the PNG |
|---|---|---|
| `device_bits` ("Overall Traffic") | `generic_multi_bits_separated` | `#3fb8f5` `#3ad6a8` `#2cb08a` `#218c6e` — **all from config** |
| `port_bits` | `generic_data` | `#90b040` `#8080c0` — **stock literals** |

Both pick up the themed *chrome* (`#0f1a2e` background, via
`rrdgraph_def_text_dark`), so the config plainly reaches the renderer — only
the series are stuck. The practical result is that a dashboard built mostly
from port widgets looks completely untouched while the device pages beside it
look correct. That is the report I got from someone running one of these
themes, and it took pixel-sampling the PNGs to establish it wasn't a
dashboard-specific bug.

**`generic_data.inc.php` is worth doing first within 0c.** It renders
`port_bits` — the traffic graph on effectively every dashboard — and the series
users actually see are six lines:

```php
$rrd_options[] = 'AREA:in'   . $format . '#90B040' . $stacked['transparency'] . ':';
$rrd_options[] = 'LINE:in'   . $format . '#608720:In ';
$rrd_options[] = 'AREA:dout' . $format . '#8080C0' . $stacked['transparency'] . ':';
$rrd_options[] = 'LINE:dout' . $format . '#606090:Out';
```

The file's other twelve literals are percentile rules, port-speed lines and
prediction overlays, which arguably *should* stay fixed — leaving them out
keeps the diff small and the argument narrow. There's no structural obstacle:
the file builds an `$rrd_options[]` array of strings exactly like its siblings,
and `generic_multi_bits_separated.inc.php` in the same directory already does

```php
$colour_in = LibrenmsConfig::get("graph_colours.$colours_in.$iter");
```

This helper simply predates the config mechanism and never got converted, and
it happens to sit behind the most-viewed graph in the product.

Corroboration that no theme can reach this today: two of my themes with
completely different palettes — one teal/gold, one acid-green/magenta — render
**byte-identical** port graphs.

So this needs no new machinery. `graph_colours.*` already exists, is already
config-driven, and is already used by 89 graph files. It's applying an in-tree
pattern to the helpers that never got it.

**0d. Fix contextual table row contrast.** Four values, and not a theming ask at
all. `tw_dark.css` fills contextual rows with saturated mid-tones and leaves the
text dark:

| Row | Fill |
|---|---|
| `tr.success` | `#62c462` |
| `tr.info` | `#5bc0de` |
| `tr.warning` | `#ba6f05` |
| `tr.danger` | `#ee5f5b` |

On the alert rules page, where most rows carry one of these, that's dark body
text on bright fills — in the stock dark theme, with no custom CSS involved.

The same file has a second instance, and it's a cleaner illustration of why
Phase 1 matters:

```css
.dark .select2-container--bootstrap .select2-selection--single
  .select2-selection__placeholder { color: #272b30; }
```

`#272b30` is `--tw-color-dark-gray-500` — the **darkest surface** in the dark
ramp, used as a text colour. On `/eventlog` the "All Devices" and "All Types"
filter labels measure **1.2:1**. Both bugs are the same mistake: a surface
value used as ink. A token contract that separates the two makes it hard to
write, which is the argument for Phase 1 made by core's own stylesheet rather
than by me.

*(Retracted 2026-09-25, after posting. The 1.2:1 was measured with a skin
active. Stock dark leaves the select2 field white, where `#272b30` reads at
14.2:1; the skin's own field darkening caused the failure. The "same mistake"
argument above therefore has no second instance. Section left as posted.)*

Worth fixing regardless of everything else here.

#### Phase 1 — define the token contract

Consolidate the 603 literals in `styles.css` and `tw_dark.css` onto named tokens
in the existing `@theme` block. Pixel-identical, area by area, one PR per area.

This is murrant's "clean up all the legacy colors first", done in a way that
produces something durable: **the resulting token list is the theming API.** A
theme can only control what core reads from a token, so this phase decides what
is themeable forever after. Worth designing deliberately rather than falling out
of a refactor.

While in there, `tw_dark.css` mixes selector depths — `.dark .x` (0,2,0),
`.dark .y .x` (0,3,0), `.dark .a.b .c > d` (0,4,2). There's no single prefix a
theme can use, so overriding anything means reading the stylesheet first to see
what you're fighting. Normalising that is cheap while the file is already open.

#### Phase 2 — one palette source for CSS and graphs

Make `rrdgraph_def_text*` and `graph_colours.*` derive from the same tokens as
the CSS layer, instead of being a parallel universe. After Phase 0c the graph
side is already config-driven throughout; this just points both at one source.

End state: one palette definition drives the page and the graphs.

#### Phase 3 — themes as data

> **Superseded — see the note at the top.** What follows was the proposal as
> posted. The replacement is much smaller: **colour schemes live in the
> LibreNMS codebase as token sets and are selectable through the existing
> `site_style` mechanism.** No upload, no manifest format, no third-party
> installs, no policy gate, and nothing here for a maintainer to support when a
> stranger's theme breaks. Phases 0–2 are what make adding one cheap; after
> them a scheme is a file of token values and a line in a list.
>
> Kept below unedited as the record of what was argued.

A theme becomes a **validated manifest of token values** — not a CSS file.

- A `themes` table, same shape as `custom_map`.
- Built-ins (`light`, `dark`, `mono`, `blue`) seeded from
  `resources/definitions/themes/*.json`, flagged non-deletable — the same
  pattern as `resources/definitions/alert_rules.json` backing the alert rule
  collection.
- `lnms theme:import <file.json>` / `theme:export` / `theme:delete`.
- `site_style` options become dynamic instead of a fixed enum in
  `config_definitions.json`.
- Rendering is a `<style>` block of custom properties in the layout head. No
  filesystem writes, so nothing to break on `daily.sh` and nothing for the
  webserver user to own.

#### Phase 4 — the UI

> **Withdrawn.** This phase existed only to serve installable themes. With
> built-in schemes there is nothing to upload or delete, and selection already
> works through `site_style`. Kept below as the record.

Upload and delete in the web UI, gated by a policy. Per-user selection already
works from Phase 3. This is the smallest phase.

---

### Security: a manifest, not a stylesheet

This is the part I'd most like scrutiny on, because "users can upload something
that becomes CSS" deserves suspicion.

**A theme is never arbitrary CSS.** It's a JSON manifest of known keys with
validated values, rendered only as custom properties:

- Whitelisted key set — unknown keys rejected, not ignored.
- Values validated by type: hex colours against a regex, numerics bounded, font
  families from an allow-list.
- Output is only `--token: value` pairs. No selectors, no `url()`, no `content`,
  no arbitrary declarations.

That matters because arbitrary CSS is genuinely dangerous: attribute selectors
plus `background-image: url(...)` can exfiltrate page data, absolute positioning
enables clickjacking, and remote fonts or images beacon on every page load. A
strict token whitelist removes all of it by construction.

Install gated by policy exactly like `CustomMapPolicy::create()`.

**Open question for v1:** web fonts. Allowing arbitrary font URLs reintroduces
the beacon problem, so my instinct is to restrict to families already bundled,
with self-hosted fonts as a later, separately-considered step. Interested in
other views.

---

### Why this fits how LibreNMS already works

I'm not proposing a new pattern — I'm proposing an existing one applied to
colour. **Custom maps** (`app/Http/Controllers/Maps/CustomMapController.php`,
2023) are already a user-created, DB-stored, JSON-configured, policy-gated,
deletable entity, with a `CustomMapSettingsRequest` doing strict validation
including regex closures. A theme is the same shape with a different payload.

And **alert rule templates** already ship built-in definitions as JSON in
`resources/definitions/` that users instantiate from — the same seeding pattern
Phase 3 needs for protected built-ins.

---

### What I'm asking for today

Not approval of the whole thing. Specifically:

1. **Is the direction welcome?** If a theme system is something LibreNMS
   doesn't want, I'd rather know now — Phase 0 is still worth doing and I'd
   happily stop there.
2. **Does the token contract in Phase 1 need a design discussion first?** It's
   the part that's hard to change later, and I'd rather agree the shape than
   present it finished.
3. **May I open Phase 0b?** One line, obviously correct, easy to review — a
   reasonable place to start building trust. I would then like to follow with
   0a, which is the one that most directly unblocks third-party theming.

   *(Asked before 0b was withdrawn. The equivalent ask now is 0d — four
   contrast values, the least arguable item in the set.)*

If 0c is welcome, I'd suggest starting it with `generic_data.inc.php` on its
own rather than all fifteen helpers at once — six lines, the pattern copied
from a sibling file in the same directory, and it fixes `port_bits`, which is
the graph most people look at most often. Easy to review, easy to revert, and
it makes the rest of 0c concrete rather than hypothetical.

> **Correction, 2026-10-01 (the posted text above is unchanged).** "The rest of 0c" is
> smaller than this implies: there is no second helper like `generic_data`. The other
> config-blind helpers would need their callers edited, not themselves.

I'm aware [#4863](https://github.com/librenms/librenms/issues/4863) asked for
custom templates in 2016 and was closed, and that #19029 was closed this year. I
think #19029 was closed for the right reason — it changed appearance before the
cleanup existed. This proposal deliberately does the cleanup first and keeps
every early phase visually identical.

---

### What I'm not proposing

- **Arbitrary CSS upload.** Validated token manifests only.
- **A visual redesign.** Phases 0–2 are pixel-identical by construction.
- **Changing the existing themes' appearance.** Built-ins keep their current
  values; they just get expressed as tokens.
- **Retinting the multi-hue graph palettes** (`manycolours`, `rainbow`,
  `psychedelic`, `mixed`, `varied`). Those are multi-hue on purpose — hue is
  what distinguishes series on a busy graph.
- **Replacing `webui.custom_css[]`.** It keeps working for people who want raw
  CSS.

---

### Offer

Happy to do any or all of this, in whatever order suits, or to hand over the
measurements if someone else would rather own it. I can provide before/after
screenshots for anything claimed as pixel-identical.

I've been running one of these themes on a production instance for a while, so
this comes from using it rather than theorising about it.

---

## Notes for us, not for the post

**~~Fix before posting~~ — all handled:**

- The repo was private, so the post deliberately does not link it. It is public
  now. Adding the link is a follow-up reply, not an edit.
- The offer says "I can provide before/after screenshots" rather than claiming
  they exist. Still true, and still the right shape: they are cheap to produce
  once a phase is actually welcomed, and premature otherwise.
- The old draft disclosed the instance size. Removed from the post, and later
  from `DEPLOYMENT.md` and `ROADMAP.md` too (2026-10-05): pairing a size and an exact
  build with the rest of this repository narrows down who runs it, for no benefit.

**Sequencing** — *(updated 2026-10-02)* steps 1 and 2 are done. The thread was answered on
2026-09-22/23 (installable themes are not wanted; built-in colour schemes might be; "please stop
pasting AI text"), 0d went up alone as #20594 and merged on 2026-09-29, and the upstream port
series change (0c) was written, proven and **deliberately not submitted** (ROADMAP.md, "Upstream:
the port series change", has the reasons and what would reopen it). The list below is the original
plan, kept as it was:

1. Discord first, using the opener above.
2. Post to Projects once someone's said "sure, write it up".
3. Open **Phase 0d only** — contextual row contrast plus the select2
   placeholder. *(The select2 half is void — not a stock bug; see FINDINGS
   §2c. Contextual rows went up alone as #20594.)* Four values, pure accessibility, independent of everything
   else, and the easiest thing in the set to say yes to. It inherited the
   opener slot when 0b was withdrawn.
4. Then **0a** (drop the `!`, 9 files). Note this is now weaker than first
   drafted twice over: the utilities turned out reachable via theme variables,
   *and* third-party theming is no longer the goal. Pitch it as removing an
   `!important` nothing depends on, not as unblocking anything.
5. **0b is withdrawn.** The premise was wrong — the widget header is themeable.
   Do not open it.
6. **0c** (graph helpers, 15 files) *(updated: only `generic_data.inc.php` is fixable in the
   helper; the other four config-blind helpers take their colours from about 150 callers, and the
   one-helper change is written but not submitted — see
   [ROADMAP.md](ROADMAP.md))*. Six lines, and it fixes the most-viewed graph in the product.
7. Don't write a line of Phase 1 until question 2 gets an answer. The token
   contract is the part that's expensive to get wrong.

**This list has been wrong twice.** First the letters were off by one — it
said "0a, one line" when 0a is 9 files. Then 0b, which it named as the opener,
turned out to rest on a false premise and was withdrawn. If you edit the phase
definitions above, re-check this list; it does not update itself.

If **0d** — four contrast values in the stock dark theme, nothing to do with
theming — is rejected, stop and ask why before writing anything else. A no on
the most trivially correct change in the set is an answer about the direction,
not about the patch. *(It was accepted and merged, as #20594, after cutting it back to the
eight values and calling it a quick fix; a first version that moved rules between files was
rejected as "moving the garbage around".)*
