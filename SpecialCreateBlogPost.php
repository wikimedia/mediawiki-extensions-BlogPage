<?php
/**
 * A special page to create new blog posts (pages in the NS_BLOG namespace).
 * Based on the CreateForms extension by Aaron Wright and David Pean.
 *
 * @file
 * @ingroup Extensions
 * @date 4 January 2012
 */
class SpecialCreateBlogPost extends SpecialPage {

	public $tabCounter = 1;

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'CreateBlogPost', 'createblogpost' );
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the special page or null
	 */
	public function execute( $par ) {
		global $wgContLang;

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		// If the user can't create blog posts, display an error
		if ( !$user->isAllowed( 'createblogpost' ) ) {
			$out->permissionRequired( 'createblogpost' );
			return;
		}

		// Show a message if the database is in read-only mode
		if ( wfReadOnly() ) {
			$out->readOnlyPage();
			return;
		}

		// If user is blocked, s/he doesn't need to access this page
		if ( $user->isBlocked() ) {
			$out->blockedPage( false );
			return false;
		}

		// Set page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS & JS
		$out->addModuleStyles( 'ext.blogPage.create.css' );
		$out->addModules( 'ext.blogPage.create.js' );

		// If the request was POSTed, we haven't submitted a request yet AND
		// we have a title, create the page...otherwise just display the
		// creation form
		if (
			$request->wasPosted() &&
			$_SESSION['alreadysubmitted'] == false
		)
		{
			$_SESSION['alreadysubmitted'] = true;

			// Protect against cross-site request forgery (CSRF)
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
				return;
			}

			// Create a Title object, or try to, anyway
			$userSuppliedTitle = $request->getVal( 'title2' );
			$title = Title::makeTitleSafe( NS_BLOG, $userSuppliedTitle );

			// @todo CHECKME: are these still needed? The JS performs these
			// checks already but then again JS is also easy to fool...

			// The user didn't supply a title? Ask them to supply one.
			if ( !$userSuppliedTitle ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-need-title' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			}

			// The user didn't supply the blog post text? Ask them to supply it.
			if ( !$request->getVal( 'pageBody' ) ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-need-content' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			}

			// Localized variables that will be used when creating the page
			$localizedCatNS = $wgContLang->getNsText( NS_CATEGORY );
			$today = $wgContLang->date( wfTimestampNow() );

			// Create the blog page if it doesn't already exist
			$article = new Article( $title, 0 );
			if ( $article->exists() ) {
				$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
				$out->addWikiMsg( 'blog-create-error-page-exists' );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			} else {
				// The blog post will be by default categorized into two
				// categories, "Articles by User $1" and "(today's date)",
				// but the user may supply some categories themselves, so
				// we need to take those into account, too.
				$categories = array(
					'[[' . $localizedCatNS . ':' .
						$this->msg(
							'blog-by-user-category',
							$this->getUser()->getName()
						)->inContentLanguage()->text() .
					']]' .
					"[[{$localizedCatNS}:{$today}]]"
				);

				$userSuppliedCategories = $request->getVal( 'pageCtg' );
				if ( !empty( $userSuppliedCategories ) ) {
					// Explode along commas so that we will have an array that
					// we can loop over
					$userSuppliedCategories = explode( ',', $userSuppliedCategories );
					foreach ( $userSuppliedCategories as $cat ) {
						$cat = trim( $cat ); // GTFO@excess whitespace
						if ( !empty( $cat ) ) {
							$categories[] = "[[{$localizedCatNS}:{$cat}]]";
						}
					}
				}

				// Convert the array into a string
				$wikitextCategories = implode( "\n", $categories );

				// Perform the edit
				$article->doEdit(
					// Instead of <vote />, Wikia had Template:Blog Top over
					// here and Template:Blog Bottom at the bottom, where we
					// have the comments tag right now
					'<vote />' . "\n" . '<!--start text-->' . "\n" .
						$request->getVal( 'pageBody' ) . "\n\n" .
						'<comments />' . "\n\n" . $wikitextCategories .
						"\n__NOEDITSECTION__",
					$this->msg( 'blog-create-summary' )->inContentLanguage()->text()
				);

				$articleId = $article->getID();
				// Add a vote for the page
				// This was originally in its own global function,
				// wfFinishCreateBlog and after that in the BlogHooks class but
				// it just wouldn't work with Special:CreateBlogPost so I
				// decided to move it here since this is supposed to be like
				// the primary way of creating new blog posts...
				// Using OutputPageBeforeHTML hook, which, according to its
				// manual page, runs on *every* page view was such a stupid
				// idea IMHO.
				$vote = new Vote( $articleId );
				$vote->insert( 1 );

				$stats = new UserStatsTrack( $user->getID(), $user->getName() );
				$stats->updateWeeklyPoints( $stats->point_values['opinions_created'] );
				$stats->updateMonthlyPoints( $stats->point_values['opinions_created'] );

				// Redirect the user to the new blog post they just created
				$out->redirect( $title->getFullURL() );
			}
		} else {
			$_SESSION['alreadysubmitted'] = false;

			// Start building the HTML
			$output = '';

			// Show the blog rules, if the message containing them ain't empty
			$message = $this->msg( 'blog-create-rules' );
			if ( !$message->isDisabled() ) {
				$output .= $message->escaped() . '<br />';
			}

			// Main form
			$output .= $this->displayForm();

			// Show everything to the user
			$out->addHTML( $output );
		}
	}

	/**
	 * Show the input field where the user can enter the blog post title.
	 * @return string HTML
	 */
	public function displayFormPageTitle() {
		$output = '<span class="create-title">' . $this->msg( 'blog-create-title' )->escaped() .
			'</span><br /><input class="createbox" type="text" tabindex="' .
				$this->tabCounter . '" name="title2" id="title" style="width: 500px;"><br /><br />';
		$this->tabCounter++;
		return $output;
	}

	/**
	 * Show the input field where the user can enter the blog post body.
	 * @return string HTML
	 */
	public function displayFormPageText() {
		$output = '<span class="create-title">' . $this->msg( 'blog-create-text' )->escaped() .
			'</span><br />';
		// The EditPage toolbar wasn't originally present here but I figured
		// that adding it might be more helpful than anything else.
		// Guess what...turns out that resources/mediawiki.action/mediawiki.action.edit.js
		// assumes way too many things and no longer is suitable for different
		// editing interfaces, such as this special page.
		// I miss the old edit.js...
		// $output .= EditPage::getEditToolbar();
		$output .= '<textarea class="createbox" tabindex="' .
			$this->tabCounter . '" accesskey="," name="pageBody" id="pageBody" rows="10" cols="80"></textarea><br /><br />';
		$this->tabCounter++;
		return $output;
	}

	/**
	 * Show the category cloud.
	 * @return string HTML
	 */
	public function displayFormPageCategories() {
		$cloud = new BlogTagCloud( 20 );

		$tagcloud = '<div id="create-tagcloud">';
		$tagnumber = 0;
		foreach ( $cloud->tags as $tag => $att ) {
			$tag = trim( $tag );
			$blogUserCat = str_replace( '$1', '', $this->msg( 'blog-by-user-category' )->inContentLanguage()->text() );
			// Ignore "Articles by User X" categories
			if ( !preg_match( '/' . $blogUserCat . '/', $tag ) ) {
				$slashedTag = $tag; // define variable
				// Fix for categories that contain an apostrophe
				if ( strpos( $tag, "'" ) ) {
					$slashedTag = str_replace( "'", "\'", $tag );
				}
				$tagcloud .= " <span id=\"tag-{$tagnumber}\" style=\"font-size:{$cloud->tags[$tag]['size']}{$cloud->tags_size_type}\">
					<a class=\"tag-cloud-entry\" data-blog-slashed-tag=\"" . $slashedTag . "\" data-blog-tag-number=\"{$tagnumber}\">{$tag}</a>
				</span>";
				$tagnumber++;
			}
		}
		$tagcloud .= '</div>';

		$output = '<div class="create-title">' .
			$this->msg( 'blog-create-categories' )->escaped() .
			'</div>
			<div class="categorytext">' .
				$this->msg( 'blog-create-category-help' )->escaped() .
			'</div>' . "\n";
		$output .= $tagcloud . "\n";
		$output .= '<textarea class="createbox" tabindex="' . $this->tabCounter .
			'" accesskey="," name="pageCtg" id="pageCtg" rows="2" cols="80"></textarea><br /><br />';
		$this->tabCounter++;

		return $output;
	}

	/**
	 * Display the standard copyright notice that is shown on normal edit page,
	 * on the upload form etc.
	 *
	 * @return string HTML
	 */
	public function displayCopyrightWarning() {
		global $wgRightsText;
		if ( $wgRightsText ) {
			$copywarnMsg = 'copyrightwarning';
			$copywarnMsgParams = array(
				'[[' . $this->msg( 'copyrightpage' )->inContentLanguage()->text() . ']]',
				$wgRightsText
			);
		} else {
			$copywarnMsg = 'copyrightwarning2';
			$copywarnMsgParams = array(
				'[[' . $this->msg( 'copyrightpage' )->inContentLanguage()->text() . ']]'
			);
		}
		return '<div class="copyright-warning">' .
			$this->msg( $copywarnMsg, $copywarnMsgParams )->parseAsBlock() .
			'</div>';
	}

	/**
	 * Show the form for creating new blog posts.
	 * @return string HTML
	 */
	public function displayForm() {
		$user = $this->getUser();
		$accessKey = Linker::accessKey( 'save' );
		$output = '<form id="editform" name="editform" method="post" action="' .
			htmlspecialchars( $this->getTitle()->getFullURL() ) . '" enctype="multipart/form-data">';
		$output .= "\n" . $this->displayFormPageTitle() . "\n";
		$output .= "\n" . $this->displayFormPageText() . "\n";

		$output .= "\n" . $this->displayFormPageCategories() . "\n";
		$output .= "\n" . $this->displayCopyrightWarning() . "\n";
		$output .= '<input type="button" value="' . $this->msg( 'blog-create-button' )->escaped() .
			'" name="wpSave" class="createsubmit site-button" accesskey="' . $accessKey . '" title="' .
			$this->msg( 'tooltip-save' )->escaped() . '" />
			<input type="hidden" value="" name="wpSection" />
			<input type="hidden" value="" name="wpEdittime" />
			<input type="hidden" value="" name="wpTextbox1" id="wpTextbox1" />
			<input type="hidden" value="' . htmlspecialchars( $user->getEditToken() ) .
				'" name="wpEditToken" />';
		$output .= "\n" . '</form>' . "\n";

		return $output;
	}

	/**
	 * Check if there is already a blog post with the given title.
	 *
	 * @param string $pageName Page title to check
	 * @return string 'OK' when there isn't such a page, else 'Page exists'
	 */
	public static function checkTitleExistence( $pageName ) {
		// Construct page title object to convert to database key
		$pageTitle = Title::makeTitle( NS_MAIN, urldecode( $pageName ) );
		$dbKey = $pageTitle->getDBkey();

		// Database key would be in page title if the page already exists
		$dbr = wfGetDB( DB_MASTER );
		$s = $dbr->selectRow(
			'page',
			array( 'page_id' ),
			array( 'page_title' => $dbKey, 'page_namespace' => NS_BLOG ),
			__METHOD__
		);

		if ( $s !== false ) {
			return 'Page exists';
		} else {
			return 'OK';
		}
	}
}
