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
| `block_doctor_scan_started` | action | `( string $source, array $state )`. A scan started. `$source` is `browser` or `server` |
| `block_doctor_scan_finished` | action | `( string $source, array $state )`. A scan reached the last post. `$state` has `done`, `total` and `found` (posts with issues) |
| `block_doctor_issues_found` | action | `( int $post_id, array $issues )`. A post has issues after a scan or a fix. `$issues` is every issue stored for it |
| `block_doctor_post_fixed` | action | `( int $post_id, int $batch_id, int $revision_id, string[] $kinds )`. A fixed post was saved. `$revision_id` holds the content before the fix, 0 when the post type keeps no revisions |
| `block_doctor_batch_undone` | action | `( int $batch_id, int[] $restored, array $skipped )`. Undo batch handled every post. `$skipped` is post ID => `edited`, `deleted`, `forbidden`, `nothing` or `revision` |

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

On WordPress 6.9 and later, Block Doctor registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All four need the Block Doctor capability.

Invalid markup can only be found and fixed in the browser, so the abilities cover the stored results, the server-side checks and Undo batch.

| Ability | Does |
| --- | --- |
| `block-doctor/get-scan-summary` | Read-only. Issue and post counts per kind, posts with broken blocks, and the state of the last browser and server scans |
| `block-doctor/list-issues` | Read-only. Posts with issues, with filters `kind`, `post_type`, `post_id`, `page`, `per_page` (up to 100). Each post has `status_label`, its issues (with `detail` and `excerpt`) and `stale` when it changed since the scan |
| `block-doctor/run-server-scan` | Runs the server checks. With `post_id`, on that post now. Otherwise the first batch now and the rest with WP-Cron. Not read-only, not destructive, so the REST API runs it with `POST` |
| `block-doctor/undo-batch` | Destructive. Undoes `batch_id`, up to 100 posts per call; call again while `remaining` is above 0. The REST API runs it with `DELETE` |

## WP-CLI

```sh
wp block-doctor scan [--post_type=<type>] [--format=table|csv|json|yaml|count]
wp block-doctor report [--kind=<kind>] [--post_type=<type>] [--format=...]
wp block-doctor batches [--format=...]
wp block-doctor undo-batch <id>
wp block-doctor clear [--yes]
```

`scan` runs the server-side checks on every post and lists what they found. It cannot see invalid markup: that needs the browser scan on Tools > Block Doctor. `report` lists everything stored, from both scans. `clear` deletes the stored results and scan progress; batches stay, so they can still be undone.

## REST API

The screen uses these routes under `/block-doctor/v1/`, all behind the Block Doctor capability:

| Route | Does |
| --- | --- |
| `GET /posts?after=&per_page=` or `?include[]=` | Posts to scan, with raw content and an md5 `hash` |
| `GET /scan`, `POST /scan` (`action`: start, pause, resume) | Summary and the browser scan's state |
| `POST /results`, `DELETE /results` | Store one batch of browser results, or clear everything |
| `POST /server-scan` | Run the next server batch, starting a scan when none is running |
| `GET /issues?kind=&post_type=&page=&per_page=` | Report rows, or `ids_only=1` for every matching post ID |
| `GET /batches`, `POST /batches` | List batches, or start one |
| `POST /fix` | Save one fixed post: `post_id`, `batch`, `content`, `hash`, `issues` |
| `POST /batches/<id>/undo` | Undo the next posts of a batch |

## PHP API

```php
$plugin = AryanX\Axle\BlockDoctor\Plugin::instance();

$plugin->scanner()->scan_post( get_post( 42 ) ); // run the server checks on one post and store them
$plugin->scanner()->start_server( 'page', true ); // a server scan of every page, in the background
$plugin->issues()->get( 42 );                     // [ 'hash' => ..., 'time' => ..., 'issues' => [ ... ] ]
$plugin->issues()->counts();                      // [ 'invalid' => [ 'issues' => 5, 'posts' => 4 ], ... ]
$plugin->report()->summary();                     // counts and the state of both scans
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

None of it is autoloaded. The `block_doctor_server_scan` cron event only runs during a background server scan. Uninstalling removes all of the above on every site of a network. Fixed posts stay fixed, and their revisions stay.

## Limits

- A block type registered in PHP is never reported as unregistered. When its editor script still does not load on the screen, its own markup is not validated; the blocks inside it are.
- A block that registers only in JavaScript inside the post editor screen itself, not on `enqueue_block_editor_assets`, shows up as unregistered. Most plugins use that hook or `block.json`.
- Recovery rebuilds a block from the attributes the editor can read from its markup, like Attempt Block Recovery. When the markup is too far off, content can be lost: check the diff, or pick Custom HTML for that block type.
- Templates and template parts that only exist as theme files are not scanned. Fixing one would create a customized copy in the database.
- Markup with unbalanced block delimiters is read the way WordPress's parser reads it.
