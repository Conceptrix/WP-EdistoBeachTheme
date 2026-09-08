<?php
/**
 * Directorist listing permalinks.
 *
 * EdistoBeach.com serves its Directorist listings under two flat, sibling URL
 * bases:
 *
 *   Restaurant listings -> /restaurants/{listing-slug}/
 *   Event listings      -> /events/{listing-slug}/
 *
 * Unlike FollyBeach.com, Directorist here does NOT own /restaurants/: the
 * `atbdp_listing_slug` option is empty, so Directorist's own single-listing base
 * is /at_biz_dir/{slug}/. Both pretty bases are currently produced solely by the
 * WPCode "Listing Slug" snippet (post 71902). This module replaces that snippet
 * and therefore has to own BOTH bases itself.
 *
 * Hardening vs. the snippet:
 *   - directory types are resolved by taxonomy term slug at runtime (memoised),
 *     never by the hard-coded term IDs 77 / 78;
 *   - a filterable type-slug -> URL-base map drives both the permalink filter
 *     and the rewrite rules, so the two cannot drift apart;
 *   - each base gets the companion routes WordPress would build for a real
 *     permastruct (pagination, comment pages, embed, trackback), so
 *     /restaurants/{slug}/page/2/ and friends resolve instead of 404ing;
 *   - every callback no-ops cleanly when Directorist is not active.
 *
 * This module NEVER flushes rewrite rules and performs NO database writes of any
 * kind. The plugin-wide, version-guarded flush in edistobeach-site.php
 * (EBS_REWRITE_RULES_VERSION) is the single place that flushes, with
 * "Settings -> Permalinks -> Save" as the manual fallback.
 *
 * @package EdistoBeach_Site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Directorist's listing post type.
 */
define( 'EBS_DIRECTORIST_POST_TYPE', 'at_biz_dir' );

/**
 * Directorist's multi-directory "listing type" taxonomy.
 */
define( 'EBS_DIRECTORIST_TYPE_TAXONOMY', 'atbdp_listing_types' );

/**
 * Whether Directorist is loaded far enough for this module to act.
 *
 * Directorist registers the listing post type on init:5 and the directory-type
 * taxonomy on init:10, so this is reliable from init:11 onward and inside the
 * post_type_link filter.
 *
 * @return bool
 */
function ebs_directorist_is_ready() {
	return post_type_exists( EBS_DIRECTORIST_POST_TYPE )
		&& taxonomy_exists( EBS_DIRECTORIST_TYPE_TAXONOMY );
}

/**
 * Map of Directorist directory-type term slug => public URL base segment.
 *
 * Keys and values are bare slugs (no surrounding slashes). Filter
 * `ebs_directorist_type_base_map` to add types or change bases; the same map
 * drives both the permalink filter and the rewrite-rule registration.
 *
 * @return array<string,string>
 */
function ebs_directorist_type_base_map() {
	$map = array(
		'restaurants' => 'restaurants',
		'events'      => 'events',
	);

	/**
	 * Filter the Directorist directory-type-slug => URL-base map.
	 *
	 * @param array<string,string> $map Slug => base pairs.
	 */
	$map = apply_filters( 'ebs_directorist_type_base_map', $map );

	$clean = array();
	foreach ( (array) $map as $type_slug => $base ) {
		$type_slug = trim( (string) $type_slug, '/' );
		$base      = trim( (string) $base, '/' );

		if ( '' !== $type_slug && '' !== $base ) {
			$clean[ $type_slug ] = $base;
		}
	}

	return $clean;
}

/**
 * Resolve the configured type slugs against the atbdp_listing_types taxonomy.
 *
 * Memoised per request. Returns:
 *   'bases'   => array<int term_id, string base>  resolved directory types
 *   'missing' => string[]                          configured slugs with no term
 *
 * Term IDs are never hard-coded; each slug is looked up at runtime. When the
 * taxonomy is not registered yet the (empty) result is returned but not
 * memoised, so a later call can still resolve.
 *
 * @return array{bases: array<int,string>, missing: string[]}
 */
function ebs_directorist_type_resolution() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$result = array(
		'bases'   => array(),
		'missing' => array(),
	);

	if ( ! taxonomy_exists( EBS_DIRECTORIST_TYPE_TAXONOMY ) ) {
		return $result;
	}

	foreach ( ebs_directorist_type_base_map() as $type_slug => $base ) {
		$term = get_term_by( 'slug', $type_slug, EBS_DIRECTORIST_TYPE_TAXONOMY );

		if ( $term instanceof WP_Term ) {
			$result['bases'][ (int) $term->term_id ] = $base;
		} else {
			$result['missing'][] = $type_slug;
		}
	}

	$cache = $result;

	return $cache;
}

/**
 * Resolved term ID => URL base for the mapped Directorist listing types.
 *
 * @return array<int,string>
 */
function ebs_directorist_resolved_type_bases() {
	$resolution = ebs_directorist_type_resolution();

	return $resolution['bases'];
}

/**
 * Point Restaurant and Event listing permalinks at their flat URL bases.
 *
 * Only at_biz_dir listings whose `_directory_type` resolves to one of the mapped
 * directory types are rewritten. Every other post type -- and any listing of an
 * unmapped type -- is returned untouched. Runs at priority 20 so it can coexist
 * with the legacy WPCode "Listing Slug" snippet (priority 10) during rollout.
 *
 * @param string  $post_link Default permalink.
 * @param WP_Post $post      Post object.
 * @return string
 */
function ebs_directorist_filter_listing_permalink( $post_link, $post ) {
	if ( ! ebs_directorist_is_ready() || ! $post instanceof WP_Post ) {
		return $post_link;
	}

	if ( EBS_DIRECTORIST_POST_TYPE !== $post->post_type || '' === $post->post_name ) {
		return $post_link;
	}

	$bases = ebs_directorist_resolved_type_bases();
	if ( ! $bases ) {
		return $post_link;
	}

	$type_id = (int) get_post_meta( $post->ID, '_directory_type', true );
	if ( ! isset( $bases[ $type_id ] ) ) {
		return $post_link;
	}

	return home_url(
		user_trailingslashit( $bases[ $type_id ] . '/' . $post->post_name )
	);
}
add_filter( 'post_type_link', 'ebs_directorist_filter_listing_permalink', 20, 2 );

/**
 * Register the incoming-request rewrite rules for every mapped URL base.
 *
 * For each base (restaurants, events, ...) this registers the single-listing
 * route plus the companion routes WordPress builds for a real permastruct
 * (pagination, comment pages, embed, trackback), all routed through Directorist's
 * own `at_biz_dir` query var. Registration only -- this function does not flush
 * (see the module docblock).
 *
 * Rules are keyed off the base-map values, not the resolved term IDs, so the
 * URL space stays reserved even while a directory type is temporarily missing.
 *
 * Also called directly from ebs_activate() in the main file, because on the
 * plugin-activation request this init:11 hook has not run for the newly active
 * plugin.
 */
function ebs_directorist_register_listing_rewrite_rules() {
	if ( ! ebs_directorist_is_ready() ) {
		return;
	}

	$bases = array_unique( array_values( ebs_directorist_type_base_map() ) );

	foreach ( $bases as $base ) {
		$b = preg_quote( $base, '#' );

		add_rewrite_rule( '^' . $b . '/([^/]+)/?$',                          'index.php?at_biz_dir=$matches[1]',                   'top' );
		add_rewrite_rule( '^' . $b . '/([^/]+)/page/?([0-9]{1,})/?$',        'index.php?at_biz_dir=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $b . '/([^/]+)/comment-page-([0-9]{1,})/?$', 'index.php?at_biz_dir=$matches[1]&cpage=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $b . '/([^/]+)/embed/?$',                    'index.php?at_biz_dir=$matches[1]&embed=true',        'top' );
		add_rewrite_rule( '^' . $b . '/([^/]+)/trackback/?$',               'index.php?at_biz_dir=$matches[1]&tb=1',              'top' );
	}
}
add_action( 'init', 'ebs_directorist_register_listing_rewrite_rules', 11 );

/**
 * Admin notice: one or more mapped directory types could not be resolved.
 *
 * Shown only in wp-admin, only to users who can manage site options, and only
 * when Directorist is active but a configured type slug matches no term. Those
 * listings fall back to Directorist's default base; the rewrite rules for the
 * unused bases stay registered, so nothing 500s or 404s unexpectedly. Not
 * dismissible (that would need a DB write); it clears itself once the directory
 * type exists or the map filter is adjusted. Never runs on front-end requests.
 */
function ebs_directorist_admin_notice_unresolved_types() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! ebs_directorist_is_ready() ) {
		return; // Directorist being inactive is a separate, self-evident situation.
	}

	$resolution = ebs_directorist_type_resolution();
	if ( ! $resolution['missing'] ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'EdistoBeach Site:', 'edistobeach-site' ),
		sprintf(
			/* translators: %s: comma-separated list of expected Directorist directory-type slugs. */
			esc_html__( 'These Directorist directory-type slugs could not be found: %s. Listings of those types will use the default Directorist listing base instead of their pretty URL. Create the matching directory types, or adjust the ebs_directorist_type_base_map filter.', 'edistobeach-site' ),
			esc_html( implode( ', ', $resolution['missing'] ) )
		)
	);
}
add_action( 'admin_notices', 'ebs_directorist_admin_notice_unresolved_types' );
