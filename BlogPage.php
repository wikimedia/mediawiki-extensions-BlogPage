<?php
/**
 * BlogPage -- introduces a new namespace, NS_BLOG (numeric index is 500 by
 * default) and some special handling for the pages in this namespace
 *
 * @file
 * @ingroup Extensions
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:BlogPage Documentation
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'BlogPage' );
	$wgMessagesDirs['BlogPage'] =  __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for BlogPage extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the BlogPage extension requires MediaWiki 1.25+' );
}