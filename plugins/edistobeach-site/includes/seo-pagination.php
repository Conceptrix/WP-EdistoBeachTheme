<?php
/**
 * SEO: noindex for paginated archive and search subpages.
 *
 * Page 2, 3, ... of any archive, taxonomy, author, date, or search-results
 * listing (and of the blog posts index) carry little unique value and dilute
 * crawl focus. This tells search engines not to index those subpages while still
 * following their links, so the posts linked from them stay discoverable.
 *
 * Replaces EdistoBeach WPCode snippet 71704 ("Pagination"), which uses Yoast's
 * long-deprecated `wpseo_robots` string filter. Yoast 28 builds its robots
 * directive as an array and exposes it through `wpseo_robots_array`; the old
 * string filter is unreliable on current versions.
 *
 * Scope:
 *   - Page 1 of any listing is untouched -- is_paged() is false there.
 *   - Singular posts and pages are untouched: multipage single content uses the
 *     `page` query var, not `paged`, so is_paged() is false for it.
 *   - Only the `index` directive is changed; whatever Yoast decided for `follow`
 *     (and every other directive) is preserved, so a page already marked
 *     noindex or nofollow keeps its stricter setting.
 *   - The `wpseo_robots_array` filter is registered unconditionally, with no
 *     WPSEO_VERSION or class_exists check. This is safe irrespective of plugin
 *     load order: WordPress lets a filter be hooked before or after the code
 *     that later applies it. If Yoast SEO is active it applies this filter while
 *     rendering the document head and the callback runs; if Yoast is inactive
 *     the filter is never applied and the callback never runs. Either way the
 *     module does nothing harmful.
 *
 * Performs no database writes and registers no rewrite rules.
 *
 * @package EdistoBeach_Site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Force `noindex` on paginated archive / taxonomy / author / date / search
 * subpages, via Yoast's robots-array filter.
 *
 * @param array $robots Yoast robots directives keyed by directive name
 *                      (`index`, `follow`, `max-snippet`, ...).
 * @return array
 */
function ebs_seo_noindex_paginated_robots( $robots ) {
	if ( ! is_array( $robots ) ) {
		return $robots;
	}

	if ( is_paged() ) {
		$robots['index'] = 'noindex';
	}

	return $robots;
}
add_filter( 'wpseo_robots_array', 'ebs_seo_noindex_paginated_robots', 10, 1 );
