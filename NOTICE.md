# Notices

The project's own code, skins and documentation are under the [MIT licence](LICENSE). Two things in this
repository are not, and carry their own terms:

- **The bundled webfonts** under `skins/*/fonts/` are licensed under the SIL Open Font License 1.1. Each
  directory carries the upstream OFL notice beside the font files.
- **The patches** under `patches/` are diffs against LibreNMS, which is GPLv3, and they necessarily quote a
  small amount of that source as context. They are offered under the GPLv3, matching the code they modify, and
  are intended for upstream submission.

No LibreNMS source is otherwise redistributed here. The harness needs several of LibreNMS's stylesheets, and
`harness/sync-css.sh` fetches them at setup time into a gitignored directory rather than committing them.

[`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) is adapted from the
[Contributor Covenant](https://www.contributor-covenant.org), version 2.1, which is licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
