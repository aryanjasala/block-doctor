# Reference

## How a scan works

Whether a block's markup is valid is only known in the block editor's JavaScript: the editor runs the block's `save()` and compares the result with what is stored. So the main scan runs in the browser, on **Tools > Block Doctor**.

The screen loads `wp-blocks`, `wp-block-library`, `wp-format-library` and `wp-block-editor`, adds the server-side block definitions and categories the way the post editor does, and fires `enqueue_block_editor_assets`, so blocks that plugins register there register here too. It also counts as a block editor screen (`should_load_block_editor_scripts_and_styles`) and loads the editor scripts of every block type registered in PHP, so blocks registered from `block.json` with an `editorScript`, such as WooCommerce's Cart and Checkout, register as they do in the post editor. No post is opened.

The scan then asks for posts in ID order, 20 at a time, with their raw content. For each post it finds every block delimiter with the same tokenizer as WordPress's default block parser, parses each block with `wp.blocks.parse()` and walks the inner blocks:

| Kind | Found by | Means |
| --- | --- | --- |
| `invalid` | browser | `isValid` is false. The message says the saved markup no longer matches what the block produces; the first specific validation issue, such as "Expected tag name `h3`, instead saw `h2`.", is kept as the technical `detail` |
| `missing` | browser and server | The block type is not registered (the editor shows it as `core/missing`). A block type the server registry knows is never reported, even when its editor script does not load on the screen |
| `classic` | browser and server | HTML between top-level blocks, or a whole post without blocks |
| `deprecated` | browser | The block is valid only through a deprecation: its saved markup is not what the block saves now, so the next save rewrites it |
| `empty` | server | The post is empty, and one of its five newest revisions had blocks |
| `pattern-ref` | server | A `core/block` points to a synced pattern that is missing or in the trash |
| `navigation-ref` | server | A `core/navigation` points to a menu that is missing or in the trash |
| `missing-media` | server | A `core/image`, `core/cover`, `core/video`, `core/audio`, `core/file` (`id`) or `core/media-text` (`mediaId`) points to a deleted attachment |
| `error` | browser and server | The scan hit an error in this post, such as a block type whose attributes make the parser throw, or a server check that threw. The post was not checked, so it is never stored as clean. Not counted as broken |

Each issue has `kind`, `path`, `name`, `message`, `detail` (technical text, or empty), `excerpt` (the block's first words, or empty) and `source`. The screen shows the block's title with its name second, its first words and its position, and groups a post's issues by kind.

Each batch of results goes back to the server, which runs its own checks on the same posts and stores both. The results index is written once per batch, not once per post. The server checks for unregistered blocks also know the block types the browser registered at the last browser scan, so blocks registered only in JavaScript are not reported as missing.

A path counts real blocks from 0, one number per level, like `parse_blocks()`: `[2]` is the third top-level block and `[2, 0]` the first block inside it. Classic content between blocks counts as a block, blank lines do not. The screen shows paths from 1.

The scan's progress is saved after every batch. Pause it, close the tab or reload, and **Resume scan** carries on from the last post ID. Posts the user cannot edit are skipped.

Scanned: every post type with `show_in_rest` and editor support, plus `wp_block`, `wp_template` and `wp_template_part`, in the statuses publish, future, draft, pending and private. Attachments, menu items, global styles and fonts never are. Templates and template parts are scanned when they are saved in the database; the theme's own files are not.

## How a fix works

1. **Preview.** The browser loads the selected posts and works out the repaired content, then shows a diff per post. Nothing is saved yet.
2. **Repair.** Only the chosen kinds are touched, and only the blocks with issues change; every other byte of the post stays as it was.
   - `invalid`: rebuilt with `createBlock( name, attributes, innerBlocks )`, exactly like Attempt Block Recovery, then saved again. Or, per block type, turned into a Custom HTML block with the markup kept as it is (`block_doctor_fix_strategy`, and a choice in the preview).
   - `missing`: kept (default), or turned into a Custom HTML block with the original markup. A block with no saved HTML, such as every dynamic block (`<!-- wp:acme/latest /-->`), is never converted: the Custom HTML block would be empty and the block gone. It stays as it is, and the preview says why. The same goes for an invalid block repaired as Custom HTML.
   - `classic`: converted with `wp.blocks.rawHandler()`, like Convert to blocks. Only when you tick it.
   - `deprecated`: saved again with the block's current markup.
3. **Save.** Each post is saved with `wp_update_post()` through `POST /block-doctor/v1/fix`. Before that, a revision with the exact current content is made if there is none, so the content before the fix is always in a revision. The save is refused when the post changed since the preview (the content hash differs), someone has it open in the editor, or the new content has more empty Custom HTML blocks than the old one. When every save of a run is refused, for example during a site freeze, the preview stays open with the error, and nothing is listed as a batch.
4. **Batch.** A run of fixes is one batch with a number. **Undo batch** restores each post's revision from before the fix, newest first, and skips posts edited since, listing them. For post types without revisions, the content before the fix is kept with the batch instead.

## Block usage

The **Block usage** tab on Tools > Block Doctor lists every block type the site uses or registers: its provider, how many posts use it, how many times in all, and when a post using it last changed.

### The index

The counts come from an index kept on the server. For each post and block type there is one post meta row, `_block_doctor_use_<block name>`, holding how many times the post uses the block (blocks inside other blocks count too). The meta key is indexed, so counting a block type or listing its posts is one indexed query.

- It covers what the scans cover: the post types and statuses from `block_doctor_post_types` and `block_doctor_post_statuses`, so synced patterns, templates and template parts saved in the database too.
- It builds itself in the background with WP-Cron the first time someone who may use Block Doctor opens the Dashboard, Plugins, Themes or Block Doctor screen (`block_doctor_usage_auto_index`), in batches in ID order. **Count now** on the tab, `wp block-doctor usage --reindex` and the `run-usage-index` ability build it too. A build can stop and carry on from its last post ID.
- After that, saving a post updates its rows, and trashing or deleting one removes them. Revisions and autosaves are skipped.
- A block used only inside a synced pattern counts for the pattern, not for each post that shows the pattern.

### Who provides a block

Block Doctor traces a block type through facts, never a guess:

- A block registered in PHP: through its editor script, view script and stylesheets (their URLs sit inside a plugin or theme folder), then through the file of its render callback.
- A block registered only in JavaScript: through the script that registered it. The browser scan notes each script's URL as blocks register, so run **Scan site** once to learn it. A block registered in a callback that runs later has no script to name, and stays unknown.
- `core/` blocks are WordPress. Anything else stays **Unknown**.

What is found is remembered (`block_doctor_providers`), so a block keeps its plugin after that plugin is switched off. That is how a block shows as "Unregistered, provided by Acme Gallery" once Acme Gallery is off.

### Before you switch a plugin off

On the Plugins screen, Deactivate and Delete for a plugin whose blocks posts use show a step in place first: how many block types and posts, the most affected posts, a link to the full list, and **Deactivate anyway** or **Cancel**. Nothing is blocked: if the check fails, Continue still works. The index must be built first.

- **Bulk deactivate and theme switches** cannot show a step. They leave a notice on the Plugins, Themes and Block Doctor screens instead: "Acme Gallery is off, and its 3 block types are still used in 212 posts", with links to the posts and to the fixes (keep as Custom HTML, convert). **Dismiss** removes it, and so does turning the plugin back on or fixing the posts.
- **WP-CLI** deactivation is never held back. `wp plugin deactivate` prints one warning line, which `block_doctor_cli_deactivation_warning` turns off.
- **Site Health** has a test, "Blocks of plugins and themes that are off", that lists the posts still using blocks nothing registers any more.

### Who sees what

The tab and its CSV only count and list post types the person may edit other people's posts in (the post type's own `edit_others_posts` capability), and each listed post also needs `edit_post`. An editor never sees titles, counts or edit links for a post type with capabilities of its own. The scan report, its CSV and the `list-issues` ability only list posts the person may edit, as the fixes always did.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `block_doctor_capability` | filter | `( string $capability )`. Who may scan and fix, on the screen, the REST routes, the abilities and the dashboard widget. Default `edit_others_posts`. Every fix and undo also checks `edit_post` for the post |
| `block_doctor_post_types` | filter | `( string[] $types )`. Post types to scan. Types that do not exist, and attachments, menu items, global styles and fonts, are dropped |
| `block_doctor_post_statuses` | filter | `( string[] $statuses )`. Statuses to scan. Default publish, future, draft, pending, private. Unregistered statuses are dropped |
| `block_doctor_batch_size` | filter | `( int $size )`. Posts per scan request, server batch and undo request. Default 20, from 1 to 100 |
| `block_doctor_server_checks` | filter | `( array $checks )`. Name => callable that gets `( WP_Post $post, array $blocks )` and returns issues (`kind`, `path`, `name`, `message`). Add your own or remove one |
| `block_doctor_issue_kinds` | filter | `( array $kinds )`. Kinds the report knows: `label`, `description`, `fix`, `broken`. Remove one to stop reporting it; a custom check needs its kind here. Only the four built-in browser kinds can be fixed |
| `block_doctor_fix_strategy` | filter | `( string $strategy, string $block_name )`. `recover` or `html`, the default repair for invalid blocks of one type. `$block_name` is '' for the default of blocks the server does not know |
| `block_doctor_undo_days` | filter | `( int $days )`. How long a batch can be undone. Default 30, from 1 to 365 |
| `block_doctor_cron_time_limit` | filter | `( int $seconds )`. How long one WP-Cron run of the background server scan keeps going. Default 20, from 5 to 120 |
| `block_doctor_date_format` | filter | `( string $format )`. PHP date format for dates on the screen and in messages. Default the site's date format and time format from Settings > General, in the site's time zone. Anything but a non-empty string falls back to the default |
| `block_doctor_usage_auto_index` | filter | `( bool $auto )`. Whether the block usage index builds itself with WP-Cron the first time it is needed. Default true |
| `block_doctor_usage_per_page` | filter | `( int $per_page )`. Block types or posts per page on the Block usage tab. Default 20, from 1 to 100 |
| `block_doctor_usage_positions` | filter | `( int $limit )`. Positions of one block type listed for each post. Default 10, from 1 to 100 |
| `block_doctor_block_provider` | filter | `( array $provider, string $name )`. The plugin or theme a block type is attributed to: `type` (core, plugin, mu-plugin, theme or unknown), `slug`, `name` and `basename` (plugin file, empty for themes) |
| `block_doctor_deactivation_top_posts` | filter | `( int $limit )`. Most affected posts named in the warning before Deactivate or Delete. Default 5, from 1 to 20 |
| `block_doctor_deactivation_message` | filter | `( string $message, array $warning )`. The sentence in the warning, default "Its 3 block types are used in 212 posts." |
| `block_doctor_deactivation_warning` | filter | `( array $warning, string $key )`. The whole warning for one plugin or theme (`key` such as `plugin:acme/acme.php`): `name`, `blocks`, `block_count`, `posts`, `top`, `url`, `message`. Return an empty array to show none |
| `block_doctor_orphans_notice` | filter | `( string $text, array $group, string $key )`. The sentence in the notice after a plugin or theme with blocks in use was switched off |
| `block_doctor_cli_deactivation_warning` | filter | `( bool $warn )`. Whether `wp plugin deactivate` prints a warning line when the plugin's blocks are in use. Default true. It never blocks |
| `block_doctor_scan_started` | action | `( string $source, array $state )`. A scan started. `$source` is `browser` or `server` |
| `block_doctor_scan_finished` | action | `( string $source, array $state )`. A scan reached the last post. `$state` has `done`, `total` and `found` (posts with issues) |
| `block_doctor_issues_found` | action | `( int $post_id, array $issues )`. A post has issues after a scan or a fix. `$issues` is every issue stored for it |
| `block_doctor_post_fixed` | action | `( int $post_id, int $batch_id, int $revision_id, string[] $kinds )`. A fixed post was saved. `$revision_id` holds the content before the fix, 0 when the post type keeps no revisions |
| `block_doctor_batch_undone` | action | `( int $batch_id, int[] $restored, array $skipped )`. Undo batch handled every post. `$skipped` is post ID => `edited`, `deleted`, `forbidden`, `nothing` or `revision` |
| `block_doctor_usage_index_started` | action | `( array $state )`. The block usage index started building |
| `block_doctor_usage_index_finished` | action | `( array $state )`. The index covered every post |
| `block_doctor_post_indexed` | action | `( int $post_id, array $counts )`. A post's block usage was written. `$counts` is block name => uses, empty when the post is not scanned or has no blocks |
| `block_doctor_blocks_orphaned` | action | `( string $source, string $key, array $warning )`. A plugin or theme was switched off while posts use its blocks. `$source` is `plugin` or `theme` |

```php
// Only administrators may scan and fix.
add_filter( 'block_doctor_capability', fn() => 'manage_options' );

// A slider plugin whose old markup cannot be recovered: keep it as Custom HTML.
add_filter( 'block_doctor_fix_strategy', function ( $strategy, $block ) {
	return 'acme/slider' === $block ? 'html' : $strategy;
}, 10, 2 );

// Report shortcodes left in block content, as a kind of its own.
add_filter( 'block_doctor_issue_kinds', function ( $kinds ) {
	$kinds['shortcode'] = array( 'label' => 'Shortcode', 'description' => 'A shortcode in a paragraph.', 'broken' => false );
	return $kinds;
} );
add_filter( 'block_doctor_server_checks', function ( $checks ) {
	$checks['shortcode'] = function ( WP_Post $post, array $blocks ) {
		$issues = array();
		AryanX\Axle\BlockDoctor\Checks::walk( $blocks, function ( $block, $path ) use ( &$issues ) {
			if ( 'core/paragraph' === $block['blockName'] && preg_match( '/\[[a-z_]+/', $block['innerHTML'] ) ) {
				$issues[] = array( 'kind' => 'shortcode', 'path' => $path, 'name' => 'core/paragraph', 'message' => 'Shortcode in a paragraph.' );
			}
			return true;
		} );
		return $issues;
	};
	return $checks;
} );

// Tell the team when a scan finds broken blocks.
add_action( 'block_doctor_scan_finished', function ( $source, $state ) {
	if ( $state['found'] ) {
		my_slack_alert( sprintf( 'Block Doctor: %d posts with issues.', $state['found'] ) );
	}
}, 10, 2 );
```

### JavaScript hooks

The screen reads these through `wp.hooks` when it uses them, so any script on the screen can add them, for example one enqueued on `enqueue_block_editor_assets`.

| Hook | Type | Use |
| --- | --- | --- |
| `blockDoctor.batchSize` | filter | `( size )`. Posts per scan request. Default from `block_doctor_batch_size`, clamped to 1 to 100 |
| `blockDoctor.shouldScanPost` | filter | `( true, post )`. Return false to skip a post; its stored results stay. `post` has `id`, `type`, `status`, `title`, `hash`, `content` |
| `blockDoctor.fixBlock` | filter | `( result, block, context )`. Change one repair. Return a block, an array of blocks, block markup, or null to leave the block alone. `context` has `kind`, `strategy`, `source` (original markup), `path`, `post` and `reason`: `empty` when the block has no saved HTML to convert, so `result` is null |
| `blockDoctor.issueFound` | action | `( issue, post )`. Every issue the browser scan finds |
| `blockDoctor.postFixed` | action | `( post, result, batchId )`. After each fixed post is saved. `result` has `post_id`, `changed`, `revision`, `issues` |
| `blockDoctor.usageRows` | filter | `( rows )`. The rows of the Block usage tab after they load: `name`, `title`, `provider`, `registered`, `posts`, `uses`, `last` |
| `blockDoctor.usageOpened` | action | `( block, provider )`. Someone opened the posts that use a block type (`block`), or the blocks of one plugin or theme (`provider`, a key such as `plugin:acme/acme.php`) |
| `blockDoctor.deactivationContinue` | action | `( plugin, action )`. On the Plugins screen: someone chose Deactivate anyway or Delete anyway. `action` is `deactivate` or `delete` |

```js
// A custom repair: rebuild acme/notice blocks from their text only.
wp.hooks.addFilter( 'blockDoctor.fixBlock', 'my-plugin', ( result, block, context ) => {
	if ( block && block.name === 'acme/notice' && context.kind === 'invalid' ) {
		return wp.blocks.createBlock( 'acme/notice', { text: block.attributes.text } );
	}
	return result;
} );

// Leave drafts out of the browser scan.
wp.hooks.addFilter( 'blockDoctor.shouldScanPost', 'my-plugin', ( scan, post ) => scan && post.status !== 'draft' );
```

## Abilities

On WordPress 6.9 and later, Block Doctor registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All of them need the Block Doctor capability.

Invalid markup can only be found and fixed in the browser, so the abilities cover the stored results, the server-side checks, block usage and Undo batch.

| Ability | Does |
| --- | --- |
| `block-doctor/get-scan-summary` | Read-only. Issue and post counts per kind, posts with broken blocks, and the state of the last browser and server scans |
| `block-doctor/list-issues` | Read-only. Posts with issues that the user may edit, with filters `kind`, `post_type`, `post_id`, `page`, `per_page` (up to 100). Each post has `status_label`, its issues (with `detail` and `excerpt`) and `stale` when it changed since the scan |
| `block-doctor/run-server-scan` | Runs the server checks. With `post_id`, on that post now. Otherwise the first batch now and the rest with WP-Cron. Not read-only, not destructive, so the REST API runs it with `POST` |
| `block-doctor/get-block-usage` | Read-only. Block types with their provider, posts, uses and last use, filtered by `filter` (core, plugin, theme, unregistered, unused), `search` or `provider`. With `block`, or `provider`, the posts that use it, with the position of the block in each. Only post types and posts the user may edit. `ready` is false while the index is still building |
| `block-doctor/run-usage-index` | Builds the block usage index: the first batch now, the rest with WP-Cron. Not read-only, not destructive, so the REST API runs it with `POST`. Changes no post |
| `block-doctor/undo-batch` | Destructive. Undoes `batch_id`, up to 100 posts per call; call again while `remaining` is above 0. The REST API runs it with `DELETE` |

## WP-CLI

```sh
wp block-doctor scan [--post_type=<type>] [--format=table|csv|json|yaml|count]
wp block-doctor report [--kind=<kind>] [--post_type=<type>] [--format=...]
wp block-doctor usage [--block=<name>] [--provider=<key>] [--filter=<filter>] [--search=<text>] [--reindex] [--format=...]
wp block-doctor batches [--format=...]
wp block-doctor undo-batch <id>
wp block-doctor clear [--yes]
```

`scan` runs the server-side checks on every post and lists what they found. It cannot see invalid markup: that needs the browser scan on Tools > Block Doctor. `report` lists everything stored, from both scans. `usage` lists every block type with its provider, posts and uses, or with `--block` or `--provider` the posts that use it, with the position of the block in each. It builds the block usage index first when it was never built, and `--reindex` counts every post again. `--filter` takes core, plugin, theme, unregistered (used, not registered) or unused (registered, not used). `clear` deletes the stored results and scan progress; batches stay, so they can still be undone.

## REST API

The screen uses these routes under `/block-doctor/v1/`, all behind the Block Doctor capability:

| Route | Does |
| --- | --- |
| `GET /posts?after=&per_page=` or `?include[]=` | Posts to scan, with raw content and an md5 `hash` |
| `GET /scan`, `POST /scan` (`action`: start, pause, resume, `sources` with the scripts that registered each block) | Summary and the browser scan's state |
| `POST /results`, `DELETE /results` | Store one batch of browser results, or clear everything |
| `POST /server-scan` | Run the next server batch, starting a scan when none is running |
| `GET /issues?kind=&post_type=&page=&per_page=` | Report rows, or `ids_only=1` for every matching post ID. Only posts the user may edit |
| `GET /batches`, `POST /batches` | List batches, or start one |
| `POST /fix` | Save one fixed post: `post_id`, `batch`, `content`, `hash`, `issues` |
| `POST /batches/<id>/undo` | Undo the next posts of a batch |
| `GET /usage?search=&filter=&provider=&page=&per_page=` | Block types for the Block usage tab, with `ready`, `state`, `providers` and `stats` |
| `GET /usage/posts?block=` or `?provider=&page=&per_page=` | Posts that use one block type, or any block of one plugin or theme, with `positions` for one block type |
| `POST /usage/index` (`restart`) | Index the next batch of posts, starting a build when none is running |
| `GET /usage/warning?provider=` | What switching off one plugin or theme does to the blocks in use |
| `POST /steps` (`dismissed`) | Hide or show the Get started card for the current user |

## PHP API

```php
$plugin = AryanX\Axle\BlockDoctor\Plugin::instance();

$plugin->scanner()->scan_post( get_post( 42 ) ); // run the server checks on one post and store them
$plugin->scanner()->start_server( 'page', true ); // a server scan of every page, in the background
$plugin->issues()->get( 42 );                     // [ 'hash' => ..., 'time' => ..., 'issues' => [ ... ] ]
$plugin->issues()->counts();                      // [ 'invalid' => [ 'issues' => 5, 'posts' => 4 ], ... ]
$plugin->report()->summary();                     // counts and the state of both scans
$plugin->usage()->blocks( array( 'filter' => 'unregistered' ) ); // block types with posts, uses and provider
$plugin->usage()->posts_using( array( 'acme/gallery' ) );        // posts that use a block type
$plugin->providers()->for_block( 'acme/gallery' );               // the plugin or theme behind it
$plugin->fixer()->undo( 3 );                      // undo batch 3
```

## Data

| Where | Contents |
| --- | --- |
| `_block_doctor_issues` post meta | The post's issues and the md5 of the content they were found in |
| `_block_doctor_undo` post meta | Per batch: the revision before the fix, the content hash after it, the issues before it, and the old content when the post type keeps no revisions |
| `block_doctor_index` option | Which posts have issues of which kind, for counts and the report. Written once per batch of posts, and once per request when posts are deleted |
| `block_doctor_scan`, `block_doctor_server_scan` options | Progress of the last browser and server scan |
| `block_doctor_batches` option | Fix batches inside the undo window |
| `block_doctor_js_blocks` option | Block types the browser registered at the last browser scan |
| `_block_doctor_use_<block name>` post meta | How many times the post uses that block type. One row per post and block type, in the block usage index |
| `block_doctor_usage_scan` option | Progress of the last block usage index build |
| `block_doctor_providers` option | Plugin or theme found for each registered block type, remembered so it is still known after the plugin is off |
| `block_doctor_js_sources` option | Plugin or theme of blocks registered only in JavaScript, from the browser scan |
| `block_doctor_affected` option | Plugins and themes switched off while their blocks were in use, until the notice is dismissed |
| `block_doctor_steps_dismissed` user meta | The person hid the Get started card |

None of it is autoloaded. The `block_doctor_server_scan` and `block_doctor_usage_index` cron events only run during a background build. Uninstalling removes all of the above on every site of a network. On multisite each site has its own index. Fixed posts stay fixed, and their revisions stay.

## Limits

- A block type registered in PHP is never reported as unregistered. When its editor script still does not load on the screen, its own markup is not validated; the blocks inside it are.
- A block that registers only in JavaScript inside the post editor screen itself, not on `enqueue_block_editor_assets`, shows up as unregistered. Most plugins use that hook or `block.json`.
- Recovery rebuilds a block from the attributes the editor can read from its markup, like Attempt Block Recovery. When the markup is too far off, content can be lost: check the diff, or pick Custom HTML for that block type.
- Templates and template parts that only exist as theme files are not scanned. Fixing one would create a customized copy in the database.
- Markup with unbalanced block delimiters is read the way WordPress's parser reads it.
- Block usage counts blocks in post content. Blocks that only come from a template file in a theme, or from PHP, are not in any post and are not counted.
- A block registered in JavaScript inside a callback that runs after the script loads cannot be traced to its plugin. It shows as Unknown until a filter (`block_doctor_block_provider`) says otherwise.
- The warning before Deactivate works from the Plugins screen. Plugins removed through the REST API, an updater or the file system, and network-wide deactivation on multisite, get the notice and the Site Health test, not the step.
