<p align="center"><img src=".wordpress-org/icon-256x256.png" alt="" width="88"></p>

<h1 align="center">Block Doctor</h1>

<p align="center">
  <a href="https://try.axlewp.com/block-doctor"><img src="https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white" alt="Try in WordPress Playground"></a>
  <a href="https://github.com/aryanjasala/block-doctor/releases/latest"><img src="https://img.shields.io/github/v/release/aryanjasala/block-doctor?label=release&color=2271b1" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.6%2B-3858e9?logo=wordpress&logoColor=white" alt="WordPress 6.6+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white" alt="PHP 7.4+">
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later"></a>
  <br>
  <a href="https://github.com/aryanjasala/block-doctor/actions/workflows/phpcs.yml"><img src="https://github.com/aryanjasala/block-doctor/actions/workflows/phpcs.yml/badge.svg" alt="PHPCS"></a>
  <a href="https://github.com/aryanjasala/block-doctor/actions/workflows/phpstan.yml"><img src="https://github.com/aryanjasala/block-doctor/actions/workflows/phpstan.yml/badge.svg" alt="PHPStan"></a>
  <a href="https://github.com/aryanjasala/block-doctor/actions/workflows/unit-tests.yml"><img src="https://github.com/aryanjasala/block-doctor/actions/workflows/unit-tests.yml/badge.svg" alt="Unit tests"></a>
  <a href="https://github.com/aryanjasala/block-doctor/actions/workflows/e2e-tests.yml"><img src="https://github.com/aryanjasala/block-doctor/actions/workflows/e2e-tests.yml/badge.svg" alt="E2E tests"></a>
  <a href="https://github.com/aryanjasala/block-doctor/actions/workflows/plugin-check.yml"><img src="https://github.com/aryanjasala/block-doctor/actions/workflows/plugin-check.yml/badge.svg" alt="Plugin Check"></a>
</p>

<p align="center">Finds every invalid, unregistered and broken block on the site and fixes them in bulk. Shows which plugin provides each block and which posts use it, before you switch that plugin off.</p>

<p align="center">
  <img src=".github/media/demo.gif" alt="Scanning the site, previewing the fixes for three posts with invalid blocks, applying them, then undoing the batch" width="880">
</p>

---

After a migration, a block plugin update or a plugin removal, posts open with "This block contains unexpected or invalid content". The editor fixes them one block at a time, and only when someone opens the post. Other tools only list the problems. Block Doctor checks every block on the site with the editor's own validation, then fixes them in bulk: a diff first, a revision for every save, and Undo batch for the whole run. It also counts which posts use each block type and which plugin or theme provides it, so Deactivate and Delete on the Plugins screen can tell you how many posts will lose their blocks, before they do.

> [!NOTE]
> Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

## Features

- **Whole-site scan** in the browser with the block editor's own validation, 20 posts at a time, with pause, resume after a reload, and how long is left
- **Finds** invalid blocks, unregistered block types, classic content, outdated markup, emptied posts, and references to deleted synced patterns, menus and media
- **Every post type** that uses the block editor, plus synced patterns, templates and template parts saved in the database, and blocks other plugins register
- **Bulk fixes** with a diff per post first: recover invalid blocks like Attempt Block Recovery, or keep them as Custom HTML per block type, convert classic content, save outdated blocks again
- **Only broken blocks change**: every other byte of the post stays as it was
- **Undo batch** restores every post from the revision saved before its fix, and skips posts edited since
- **Block usage**: every block type on the site, who provides it (WordPress, a plugin, a theme or unknown), how many posts use it, how often and where in each post, with search, filters and a CSV download
- **A warning before you switch a plugin off**: Deactivate and Delete on the Plugins screen show "Its 3 block types are used in 212 posts" with the most affected posts and a link to the full list, then Continue or Cancel. A notice and a Site Health test follow when blocks are left behind
- **CSV download**, a **dashboard widget**, a **Site Health** check, **WP-CLI**, **abilities** for AI agents, and PHP and JavaScript hooks for every default and event

## Install

1. Download `block-doctor.zip` from the [latest release](https://github.com/aryanjasala/block-doctor/releases/latest).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and activate it.

Or with WP-CLI:

```sh
wp plugin install https://github.com/aryanjasala/block-doctor/releases/latest/download/block-doctor.zip --activate
```

Needs WordPress 6.6+ and PHP 7.4+. To try it first, [try.axlewp.com/block-doctor](https://try.axlewp.com/block-doctor) opens a private WordPress in your browser with the latest release and some demo content. Nothing to install.

## Quick look

The server-side checks, the usage counts and the stored results work from the command line too:

```sh
wp block-doctor scan --format=table
wp block-doctor report --kind=invalid --format=csv > invalid.csv
wp block-doctor usage --filter=unregistered
wp block-doctor usage --block=acme/gallery --format=csv
wp block-doctor batches
wp block-doctor undo-batch 3
```

Invalid markup needs the browser, so finding and fixing it happens on **Tools > Block Doctor**.

## Screenshots

<table>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-1.png" alt="The report after a scan: every kind of issue, per post, with the block and what is wrong with it"><br><sub>The report after a scan: every kind of issue, per post, with the block and what is wrong with it</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-2.png" alt="The preview: a diff for every post, and a choice per block type between recovering the block and keeping it as Custom HTML"><br><sub>The preview: a diff for every post, and a choice per block type between recovering the block and keeping it as Custom HTML</sub></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-3.png" alt="A scan in progress, with how many posts are left and about how long it takes"><br><sub>A scan in progress, with how many posts are left and about how long it takes</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-4.png" alt="Fix batches, each with Undo batch"><br><sub>Fix batches, each with Undo batch</sub></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-5.png" alt="The Block usage tab: every block type, who provides it, and the posts that use it, with the position of the block in each"><br><sub>The Block usage tab: every block type, who provides it, and the posts that use it, with the position of the block in each</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-6.png" alt="The step on the Plugins screen before a plugin whose blocks posts use is switched off"><br><sub>The step on the Plugins screen before a plugin whose blocks posts use is switched off</sub></td>
  </tr>
</table>

## Documentation

| Doc | What it covers |
| --- | --- |
| [Reference](docs/REFERENCE.md) | How the scan, the fixes and the block usage index work, PHP and JavaScript hooks, abilities, WP-CLI, REST routes and what is stored |
| [Development](docs/DEVELOPMENT.md) | Local setup, commands and tests |
| [Contributing](docs/CONTRIBUTING.md) | Pull requests, required checks and PR titles |
| [Releasing](docs/RELEASING.md) | How merged pull requests become releases |
| [Security](docs/SECURITY.md) | Reporting a vulnerability |
| [readme.txt](readme.txt) | What WordPress.org shows, with the FAQ and changelog |

## Project structure

```
block-doctor.php           plugin header and PSR-4 autoloader
src/
├── Plugin.php             creates the services
├── Config.php             defaults and their filters
├── Posts.php              which posts to scan, in ID order
├── Checks.php             the server-side checks
├── Usage.php              the block usage index
├── Providers.php          which plugin or theme provides a block type
├── Deactivation.php       warnings before a plugin or theme is switched off
├── Scanner.php            browser scan progress, server scan, WP-Cron
├── Issues.php             stored results and the index
├── Fixer.php              saving fixes and Undo batch
├── Batches.php            fix batches
├── Report.php             summary, CSV and WP-CLI rows
├── Rest/Controller.php    routes for the screen
├── Admin/                 Tools > Block Doctor and the dashboard widget
├── Health/SiteHealth.php  the Site Health check
├── Abilities.php          abilities for AI agents
└── Cli/Command.php        wp block-doctor
assets/
├── js/doctor.js           scan, repair and diff logic, no WordPress globals
├── js/admin.js            the screen
├── js/plugins.js          the step on the Plugins screen
├── admin.css
└── plugins.css
tests/
├── Unit/                  PHPUnit
├── js/                    Node tests for doctor.js
└── e2e/                   Playwright specs and the Docker site
.wordpress-org/            screenshots, icon and banner
docs/                      reference, development, contributing, releasing
```

## More free plugins

Small plugins that each fill one gap in WordPress, all free with no pro version. Try them all together at [demo.axlewp.com](https://demo.axlewp.com/).

| | Plugin | What it does |
| --- | --- | --- |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-page-template-usage/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Page Template Usage](https://github.com/aryanjasala/axle-page-template-usage) | See which template every page uses, and fix the pages on a missing template |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-staging-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Staging Guard](https://github.com/aryanjasala/axle-staging-guard) | Stops staging copies from emailing real people, calling live APIs or getting indexed |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-category-tag-trash/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Category & Tag Trash](https://github.com/aryanjasala/axle-category-tag-trash) | A trash for categories, tags and custom terms, with undo and full restore |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-read-only-mode/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Read-Only Mode](https://github.com/aryanjasala/axle-read-only-mode) | Read-only mode for WordPress during migrations and deploys |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-block-restore/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Block Restore](https://github.com/aryanjasala/axle-block-restore) | Restore a single block from an old revision, not the whole post |
| <img src="https://raw.githubusercontent.com/aryanjasala/activity-log-with-undo/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Activity Log with Undo](https://github.com/aryanjasala/activity-log-with-undo) | An activity log where every change you can reverse has an Undo button |
| <img src="https://raw.githubusercontent.com/aryanjasala/agent-governance/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Agent Governance](https://github.com/aryanjasala/agent-governance) | Hold risky AI agent calls for approval, preview what they change, and roll back a session |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-ai-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle AI Guard](https://github.com/aryanjasala/axle-ai-guard) | See and control every AI call your plugins make: cost, data sent, budgets and a kill switch |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-config-sync/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Config Sync](https://github.com/aryanjasala/axle-config-sync) | Keep settings in git, diff them against the live site, apply with a preview and undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-leftovers/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Leftovers](https://github.com/aryanjasala/axle-leftovers) | Find what deleted plugins left behind, see who owns it and why, and clean it up with undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-license-tracker/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle License Tracker](https://github.com/aryanjasala/axle-license-tracker) | See which premium plugins are licensed, which lapsed and stopped getting updates, and what renews when |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-review/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Review](https://github.com/aryanjasala/axle-review) | Share drafts through private links and collect outside feedback as block Notes |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-runtime-doctor/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Runtime Doctor](https://github.com/aryanjasala/axle-runtime-doctor) | Check OPcache coverage, the object cache, PHP support and limits, cron and more, with the fix for your host |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-smoke-test/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Smoke Test](https://github.com/aryanjasala/axle-smoke-test) | Check key pages after every update or deploy, see what broke and roll it back |
| <img src="https://raw.githubusercontent.com/aryanjasala/block-theme-ops/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Block Theme Ops](https://github.com/aryanjasala/block-theme-ops) | Find Site Editor changes that override your theme files, see the diff, reset them or send them to Git |
| <img src="https://raw.githubusercontent.com/aryanjasala/role-right-sizer/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Role Right-Sizer](https://github.com/aryanjasala/role-right-sizer) | Recommends the smallest role each user needs, from what they actually do |
| <img src="https://raw.githubusercontent.com/aryanjasala/update-rehearsal/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Update Rehearsal](https://github.com/aryanjasala/update-rehearsal) | Try plugin, theme, core and PHP updates on a disposable copy of your site in WordPress Playground first |
| <img src="https://raw.githubusercontent.com/aryanjasala/user-session-manager/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [User Session Manager](https://github.com/aryanjasala/user-session-manager) | See every device signed in, sign out one, and limit sessions per role |

## License

Copyright Aryan Jasala. Licensed under [GPL-2.0-or-later](LICENSE.md).
