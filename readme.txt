=== Block Doctor ===
Contributors: aryanjasala
Tags: blocks, block editor, gutenberg, migration, repair
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fixes invalid and broken blocks site-wide, in bulk, with a diff first and Undo. Shows which posts use each block before you switch a plugin off.

== Description ==

After a migration, a block plugin update or a plugin removal, posts open with "This block contains unexpected or invalid content". The editor fixes them one block at a time, with Attempt Block Recovery, and only when someone opens the post. Other tools list the problems. Block Doctor finds them across the whole site and fixes them in bulk.

**Tools > Block Doctor** checks every block with the block editor's own validation, in the browser, a batch of posts at a time. It finds:

* **Invalid blocks**: the saved markup does not match what the block saves now. This is the "unexpected or invalid content" warning.
* **Unregistered blocks**: no plugin registers the block type any more.
* **Classic content**: HTML outside blocks, or a whole classic post.
* **Outdated blocks**: saved by an older version of the block. They still work, and change the next time the post is saved.
* **Broken references**: synced patterns, navigation menus and media that were deleted, and posts that are empty now but had blocks in an earlier revision.

It scans posts, pages and every other post type that uses the block editor, plus synced patterns, templates and template parts saved in the database.

= Fixing =

Pick posts, or a whole kind of issue, and preview the fixes. Every post shows a diff of its markup, and nothing is saved until you apply them.

* Invalid blocks are rebuilt from their settings, exactly like Attempt Block Recovery. Or turn one block type into Custom HTML blocks instead, with the markup kept as it is.
* Unregistered blocks stay as they are, or become Custom HTML blocks. Dynamic blocks have no saved HTML, so they always stay as they are.
* Classic content is converted to blocks, like Convert to blocks. Only when you ask.
* Outdated blocks are saved again with their current markup.

Only the broken blocks change. Every other byte of the post stays as it was.

Each fix saves the post, so there is a revision from before and after. A run of fixes is a batch, and **Undo batch** puts every post back to its revision from before the fix. Posts someone edited after the fix are skipped and listed. Fixes refuse to save a post that changed since the preview, or that someone has open in the editor.

The scan can pause, and picks up where it stopped, even after a reload. It shows how many posts are left and about how long it takes.

= Where is each block used? =

The **Block usage** tab on the same screen lists every block type on the site: who provides it (WordPress, a plugin, a theme, or unknown), how many posts use it, how often and when it was last used. Search it, filter it to blocks from plugins, blocks nothing registers any more, or blocks that are registered and never used. Choose a block to see its posts, with where the block sits in each. Download the list as CSV.

= Before you switch a plugin off =

Deactivate or Delete a plugin that provides blocks, and Block Doctor shows a step first on the Plugins screen: "Its 3 block types are used in 212 posts", with the most affected posts and a link to the full list. You continue or cancel. Nothing is ever blocked, and WP-CLI only prints a warning line. After a bulk deactivate or a theme switch, a notice and a Site Health check link to the posts that lost their blocks, so you can fix them with the tools above.

The counts come from an index that builds itself in the background the first time, and stays current as posts are saved.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= For developers and AI agents =

* Filters for every default, limit and list, and actions when a scan starts and finishes, issues are found, a post is fixed and a batch is undone. JavaScript filters and actions too, to skip posts or repair a block your own way. See the reference on GitHub.
* WP-CLI: `wp block-doctor scan`, `report`, `usage`, `batches`, `undo-batch` and `clear`.
* On WordPress 6.9 and later, abilities let AI agents read the results and the block usage, run the server-side scan and undo a batch.
* A CSV download of every issue, a dashboard widget with the counts, and a Site Health check that says how many posts have broken blocks.

== Frequently Asked Questions ==

= Why does the scan run in the browser? =

Only the block editor's JavaScript knows what each block saves, so only it can tell whether saved markup is valid. Block Doctor loads the editor's block scripts on its own screen, without opening any post, and checks each post the way the editor would. The server also runs the checks PHP can do alone, such as references to deleted patterns and media.

= Does it find blocks from other plugins? =

Yes. The screen loads the editor scripts of every block registered in PHP, including the usual `block.json` blocks, and fires the same `enqueue_block_editor_assets` hook as the post editor, so blocks that plugins register there are checked too. A block type the server knows is never reported as unregistered. A block that only registers in JavaScript inside the post editor itself shows up as unregistered.

= What can WP-CLI do without a browser? =

`wp block-doctor scan` runs the server-side checks: unregistered blocks, classic content, emptied posts and references to deleted patterns, menus and media. Invalid markup needs the browser scan. `wp block-doctor report`, `batches`, `undo-batch` and `clear` work on the stored results, and `wp block-doctor usage` lists block types and the posts that use them.

= How does it know which plugin provides a block? =

From facts. A block registered in PHP is traced through its scripts, its stylesheets and the file of its render callback. A block that only exists in JavaScript is traced through the script that registered it, which the browser scan notes: run Scan site once. When nothing can be traced, the block says Unknown. Block Doctor remembers what it found, so a block keeps its plugin after that plugin is off.

= Will it stop me from deactivating a plugin? =

No. The step on the Plugins screen has Continue and Cancel, and if the check fails you can still continue. WP-CLI deactivation prints one warning line and goes ahead. A bulk deactivate gives a notice afterwards.

= Can I undo a fix much later? =

For 30 days, with Undo batch. After that the batch is forgotten, and the revisions from before each fix are still on the post's revisions screen.

= Does it store anything about people? =

No. It stores the issues of each post and a count of the block types it uses in post meta, the progress of the scans, the list of fix batches, which plugin provides each block, and whether you hid the Get started card. Uninstalling removes all of it. Fixed posts stay fixed.

== Screenshots ==

1. The report after a scan: every kind of issue, per post, with the block and what is wrong with it.
2. The preview: a diff for every post, and a choice per block type between recovering the block and keeping it as Custom HTML.
3. A scan in progress, with how many posts are left and about how long it takes.
4. Fix batches, each with Undo batch.
5. The Block usage tab: every block type, who provides it, and the posts that use it, with the position of the block in each.
6. The step on the Plugins screen before a plugin whose blocks posts use is switched off.

== Changelog ==

= 1.0.0 =
* First release.
