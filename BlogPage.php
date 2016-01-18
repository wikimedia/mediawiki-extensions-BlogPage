<?php
/**
 * BlogPage -- introduces a new namespace, NS_BLOG (numeric index is 500 by
 * default) and some special handling for the pages in this namespace
 *
 * @file
 * @ingroup Extensions
 * @version 2.3.2
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:BlogPage Documentation
 */

// Extension credits that will show up on Special:Version
$wgExtensionCredits['other'][] = array(
	'name' => 'BlogPage',
	'version' => '2.4.0',
	'author' => array( 'David Pean', 'Jack Phoenix' ),
	'descriptionmsg' => 'blogpage-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:BlogPage',
	'license-name' => 'GPL-2.0+',
);

// Define the namespace constants
define( 'NS_BLOG', 500 );
define( 'NS_BLOG_TALK', 501 );

// Main module, used on *all* blog pages (see the hooks file)
$wgResourceModules['ext.blogPage'] = array(
	'styles' => 'resources/css/BlogPage.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'BlogPage',
	'position' => 'top'
);

// Used on Special:ArticlesHome & Special:ArticleLists
$wgResourceModules['ext.blogPage.articlesHome'] = array(
	'styles' => 'resources/css/ArticlesHome.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'BlogPage',
	'position' => 'top'
);

// Used on Special:CreateBlogPost
$wgResourceModules['ext.blogPage.create.css'] = array(
	'styles' => 'resources/css/CreateBlogPost.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'BlogPage',
	'position' => 'top'
);

$wgResourceModules['ext.blogPage.create.js'] = array(
	'scripts' => 'resources/js/CreateBlogPost.js',
	// 'dependencies' => 'mediawiki.action.edit',
	'messages' => array(
		'blog-js-create-error-need-content', 'blog-js-create-error-need-title',
		'blog-js-create-error-page-exists'
	),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'BlogPage',
);

// Default setup for displaying sections
$wgBlogPageDisplay = array(
	// Output the left-hand column? This column contains the list of authors,
	// recent editors (if enabled), recent voters (if enabled), embed widget
	// (if enabled) and left-side advertisement (if enabled).
	'leftcolumn' => true,
	// Output the right-hand column? This column contains the list of popular
	// blog articles (if enabled), in the news section (if enabled), comments
	// of the day (if enabled), a random casual game (if enabled) and a list of
	// new blog articles.
	'rightcolumn' => true,
	// Display the box that contains some information about the author of the
	// blog post?
	'author' => true,
	// Display some (three, to be exact) other blog articles written by the
	// same user?
	'author_articles' => true,
	// Display a list of people (complete with their avatars) who recently
	// edited this blog post?
	'recent_editors' => true,
	// Display a list of people (complete with their avatars) who recently
	// voted for this blog post?
	'recent_voters' => true,
	// Show an advertisement in the left-hand column?
	'left_ad' => false,
	// Show a listing of the most popular blog posts?
	'popular_articles' => true,
	// Should we display some random news items from [[MediaWiki:Inthenews]]?
	'in_the_news' => true,
	// Show comments of the day (comments with the most votes) in the sidebar
	// on a blog post page?
	'comments_of_day' => true,
	// Display a random casual game (picture game, poll or quiz)?
	// Requires the RandomGameUnit extension.
	'games' => true,
	// Show a listing of the newest blog posts in blog pages?
	'new_articles' => true,
	// Display the widget that allows you to embed the blog post on another
	// site? Off by default since it requires the ContentWidget extension,
	// which is currently ArmchairGM-specific.
	'embed_widget' => false
);

// Set up everything
$wgMessagesDirs['BlogPage'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['BlogPageAlias'] = __DIR__ . '/BlogPage.alias.php';
// Namespace translations
$wgExtensionMessagesFiles['BlogPageNamespaces'] = __DIR__ . '/BlogPage.namespaces.php';

// Autoload the class which is used when rendering pages in the NS_BLOG NS
$wgAutoloadClasses['BlogPage'] = __DIR__ . '/BlogPageClass.php';

// Special pages
$wgAutoloadClasses['ArticlesHome'] = __DIR__ . '/SpecialArticlesHome.php';
$wgAutoloadClasses['ArticleLists'] = __DIR__ . '/SpecialArticleLists.php';
$wgSpecialPages['ArticlesHome'] = 'ArticlesHome';
$wgSpecialPages['ArticleLists'] = 'ArticleLists';

// Special page for creating new blog posts + yet another copy of TagCloud class
$wgAutoloadClasses['BlogTagCloud'] = __DIR__ . '/TagCloudClass.php';
$wgAutoloadClasses['SpecialCreateBlogPost'] = __DIR__ . '/SpecialCreateBlogPost.php';
$wgSpecialPages['CreateBlogPost'] = 'SpecialCreateBlogPost';

$wgAjaxExportList[] = 'SpecialCreateBlogPost::checkTitleExistence';

// New user right, required to create new blog posts via the new special page
$wgAvailableRights[] = 'createblogpost';
$wgGroupPermissions['*']['createblogpost'] = false;
$wgGroupPermissions['user']['createblogpost'] = true;

// Hooked functions
$wgAutoloadClasses['BlogPageHooks'] = __DIR__ . '/BlogPageHooks.php';

$wgHooks['ArticleFromTitle'][] = 'BlogPageHooks::blogFromTitle';
$wgHooks['ArticleSaveComplete'][] = 'BlogPageHooks::updateCreatedOpinionsCount';
$wgHooks['ArticleSave'][] = 'BlogPageHooks::updateCreatedOpinionsCount';
$wgHooks['AlternateEdit'][] = 'BlogPageHooks::allowShowEditBlogPage';
$wgHooks['CanonicalNamespaces'][] = 'BlogPageHooks::onCanonicalNamespaces';
// UserProfile integration
$wgHooks['UserProfileRightSideAfterActivity'][] = 'BlogPageHooks::getArticles';
// Show blogs in profiles; this needs to be defined to prevent "undefined index" notices
$wgUserProfileDisplay['articles'] = true;
