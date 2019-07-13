<?php
/**
 * Class for handling the viewing of pages in the NS_BLOG namespace.
 *
 * @file
 */
use MediaWiki\MediaWikiServices;

class BlogPage extends Article {

	public $title = null;
	public $authors = [];

	public function __construct( Title $title ) {
		parent::__construct( $title );
		$this->setContent();
		$this->getAuthors();
	}

	public function setContent() {
		// Get the page content for later use
		$this->pageContent = ContentHandler::getContentText(
			$this->getContentObject()
		);

		// If it's a redirect, in order to get the *real* content for later use,
		// we have to load the text for the real page
		// Note: If $this->getContent() is called anywhere before parent::view,
		// the real article text won't get loaded on the page
		if ( $this->isRedirect() ) {
			wfDebugLog( 'BlogPage', __METHOD__ );

			$target = $this->followRedirect();
			if ( !$target instanceof Title ) {
				// Correctly handle interwiki redirects and the like
				// WikiPage::followRedirect() can return either a Title, boolean
				// or a string! A string is returned for interwiki redirects,
				// and the string in question is the target URL with the rdfrom
				// URL parameter appended to it, which -- since it's an interwiki
				// URL -- won't resolve to a valid local Title.
				// Doing the redirection here is somewhat hacky, but ::getAuthors(),
				// which is called right after this function in the constructor,
				// attempts to read $this->pageContent...
				// @see https://github.com/Brickimedia/brickimedia/issues/370
				$this->getContext()->getOutput()->redirect( $target );
			} else {
				$rarticle = new Article( $target );
				$rcontent = $rarticle->getContentObject();
				$this->pageContent = ContentHandler::getContentText( $rcontent );

				// If we don't clear, the page content will be [[redirect-blah]],
				// and not the actual page
				$this->clear();
			}
		}
	}

	public function view() {
		global $wgBlogPageDisplay;

		$context = $this->getContext();
		$user = $context->getUser();
		$output = $context->getOutput();

		$sk = $context->getSkin();

		wfDebugLog( 'BlogPage', __METHOD__ );

		// Don't throw a bunch of E_NOTICEs when we're viewing the page of a
		// nonexistent blog post
		if ( !$this->getID() ) {
			parent::view();
			return '';
		}

		$output->addHTML( "\t\t" . '<div id="blog-page-container">' . "\n" );

		if ( $wgBlogPageDisplay['leftcolumn'] == true ) {
			$output->addHTML( "\t\t\t" . '<div id="blog-page-left">' . "\n" );

			$output->addHTML( "\t\t\t\t" . '<div class="blog-left-units">' . "\n" );

			$output->addHTML(
				"\t\t\t\t\t" . '<h2>' .
				wfMessage( 'blog-author-title' )
					->numParams( count( $this->authors ) )
					->escaped() . '</h2>' . "\n"
			);
			// Why was this commented out? --ashley, 11 July 2011
			if ( count( $this->authors ) > 1 ) {
				$output->addHTML( $this->displayMultipleAuthorsMessage() );
			}

			// Output each author's box in the order that they appear in [[Category:Opinions by X]]
			for ( $x = 0; $x <= count( $this->authors ); $x++ ) {
				$output->addHTML( $this->displayAuthorBox( $x ) );
			}

			$output->addHTML( $this->recentEditors() );
			$output->addHTML( $this->recentVoters() );

			$output->addHTML( '</div>' . "\n" );
		}

		$output->addHTML( "\t\t\t" . '</div><!-- #blog-page-left -->' . "\n" );

		$output->addHTML( '<div id="blog-page-middle">' . "\n" );
		$output->addHTML( $this->getByLine() );

		$output->addHTML( "\n<!--start Article::view-->\n" );
		parent::view();

		/**
		 * The page title is being set here before the Article::view()
		 * call above, which overrides whatever we set if we set the title
		 * above that line.
		 *
		 * @see https://phabricator.wikimedia.org/T143145
		 */
		$output->setHTMLTitle( $this->getTitle()->getText() );
		$output->setPageTitle( $this->getTitle()->getText() );

		// Get categories
		$cat = $sk->getCategoryLinks();
		if ( $cat ) {
			$output->addHTML( "\n<div id=\"catlinks\" class=\"catlinks\">{$cat}</div>\n" );
		}

		$output->addHTML( "\n<!--end Article::view-->\n" );

		$output->addHTML( '</div>' . "\n" );

		if ( $wgBlogPageDisplay['rightcolumn'] == true ) {
			$output->addHTML( '<div id="blog-page-right">' . "\n" );

			$output->addHTML( $this->getPopularArticles() );
			$output->addHTML( $this->getInTheNews() );
			$output->addHTML( $this->getCommentsOfTheDay() );
			$output->addHTML( $this->getRandomCasualGame() );
			$output->addHTML( $this->getNewArticles() );

			$output->addHTML( '</div>' . "\n" );
		}

		$output->addHTML( '<div class="visualClear"></div>' . "\n" );
		$output->addHTML( '</div><!-- #blog-page-container -->' . "\n" );
	}

	/**
	 * Get the authors of this blog post and store them in the authors member
	 * variable.
	 */
	public function getAuthors() {
		global $wgContLang;

		$articleText = $this->pageContent;
		$categoryName = $wgContLang->getNsText( NS_CATEGORY );
		$categoryName = preg_quote( $categoryName, '/' );

		// This unbelievably weak and hacky regex is used to find out the
		// author's name from the category. See also getBlurb(), which uses a
		// similar regex.
		$regexp = "/\[\[(?:(?:c|C)ategory|{$categoryName}):\s*" .
			preg_quote( wfMessage( 'blog-by-user-category' )->inContentLanguage()->plain(), '/' ) .
			"\s*\]\]/";
		// $1 will be the author name. Convert it to a capture pattern
		// It needs to be escaped since the message text was escaped too
		$regexp = str_replace( preg_quote( '$1', '/' ), '(.*)', $regexp );
		preg_match_all( $regexp, $articleText, $matches );
		if ( !isset( $matches[1] ) ) {
			return;
		}
		$authors = $matches[1];

		foreach ( $authors as $author ) {
			$authorUserId = User::idFromName( $author );
			$this->authors[] = [
				'user_name' => trim( $author ),
				'user_id' => $authorUserId
			];
		}
	}

	/**
	 * Get the creation date of the page with the given ID from the revision
	 * table and cache it in memcached.
	 * The return value of this function can be passed to the various $wgLang
	 * methods for i18n-compatible code.
	 *
	 * @param int $pageId Page ID number
	 * @return int Page creation date
	 */
	public static function getCreateDate( $pageId ) {
		global $wgMemc;

		// Try memcached first
		$key = $wgMemc->makeKey( 'page', 'create_date', $pageId );
		$data = $wgMemc->get( $key );

		if ( !$data ) {
			wfDebugLog( 'BlogPage', "Loading create_date for page {$pageId} from database" );
			$dbr = wfGetDB( DB_REPLICA );
			$createDate = $dbr->selectField(
				'revision',
				'rev_timestamp', // 'UNIX_TIMESTAMP(rev_timestamp) AS create_date',
				[ 'rev_page' => $pageId ],
				__METHOD__,
				[ 'ORDER BY' => 'rev_timestamp ASC' ]
			);
			$wgMemc->set( $key, $createDate, 7 * IExpiringStore::TTL_WEEK );
		} else {
			wfDebugLog( 'BlogPage', "Loading create_date for page {$pageId} from cache" );
			$createDate = $data;
		}

		return $createDate;
	}

	/**
	 * Get the "by X, Y and Z" line, which also contains other nifty
	 * information, such as the date of the last edit and the creation date.
	 *
	 * @return string
	 */
	public function getByLine() {
		$lang = $this->getContext()->getLanguage();

		$count = 0;

		// Get date of last edit
		$timestamp = $this->getTimestamp();
		$edit_time = [];
		$edit_time['date'] = $lang->date( $timestamp, true );
		$edit_time['time'] = $lang->time( $timestamp, true );
		$edit_time['datetime'] = $lang->timeanddate( $timestamp, true );

		// Get date of when article was created
		$timestamp = self::getCreateDate( $this->getId() );
		$create_time = [];
		$create_time['date'] = $lang->date( $timestamp, true );
		$create_time['time'] = $lang->time( $timestamp, true );
		$create_time['datetime'] = $lang->timeanddate( $timestamp, true );

		$output = '<div class="blog-byline">' . wfMessage( 'blog-by' )->escaped() . ' ';

		$authors = '';
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		foreach ( $this->authors as $author ) {
			$count++;
			$userTitle = Title::makeTitle( NS_USER, $author['user_name'] );
			if ( $authors && count( $this->authors ) > 2 ) {
				$authors .= ', ';
			}
			if ( $count == count( $this->authors ) && $count != 1 ) {
				$authors .= wfMessage( 'word-separator' )->escaped() .
					wfMessage( 'blog-and' )->escaped() .
					wfMessage( 'word-separator' )->escaped();
			}
			$authors .= $linkRenderer->makeLink(
				$userTitle,
				$author['user_name']
			);
		}

		$output .= $authors;

		$output .= '</div>';

		$edit_text = '';
		if ( $create_time['datetime'] != $edit_time['datetime'] ) {
			$edit_text = ', ' .
				wfMessage(
					'blog-last-edited',
					$edit_time['datetime'],
					$edit_time['date'],
					$edit_time['time']
				)->escaped();
		}
		$output .= "\n" . '<div class="blog-byline-last-edited">' .
			wfMessage(
				'blog-created',
				$create_time['datetime'],
				$create_time['date'],
				$create_time['time']
			)->escaped() .
			" {$edit_text}</div>";
		return $output;
	}

	public function displayMultipleAuthorsMessage() {
		$count = 0;

		$authors = '';
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		foreach ( $this->authors as $author ) {
			$count++;
			$userTitle = Title::makeTitle( NS_USER, $author['user_name'] );
			if ( $authors && count( $this->authors ) > 2 ) {
				$authors .= ', ';
			}
			if ( $count == count( $this->authors ) ) {
				$authors .= wfMessage( 'word-separator' )->escaped() .
					wfMessage( 'blog-and' )->escaped() .
					wfMessage( 'word-separator' )->escaped();
			}
			$authors .= $linkRenderer->makeLink(
				$userTitle,
				$author['user_name']
			);
		}

		$output = '<div class="multiple-authors-message">' .
			wfMessage( 'blog-multiple-authors' )->rawParams( $authors )->escaped() .
			'</div>';

		return $output;
	}

	public function displayAuthorBox( $author_index ) {
		global $wgBlogPageDisplay;

		$out = $this->getContext()->getOutput();
		if ( $wgBlogPageDisplay['author'] == false ) {
			return '';
		}

		$author_user_name = $author_user_id = '';
		if (
			isset( $this->authors[$author_index] ) &&
			isset( $this->authors[$author_index]['user_name'] )
		) {
			$author_user_name = $this->authors[$author_index]['user_name'];
		}
		if (
			isset( $this->authors[$author_index] ) &&
			isset( $this->authors[$author_index]['user_id'] )
		) {
			$author_user_id = $this->authors[$author_index]['user_id'];
		}

		if ( empty( $author_user_id ) ) {
			return '';
		}

		$authorTitle = Title::makeTitle( NS_USER, $author_user_name );

		$profile = new UserProfile( $author_user_name );
		$profileData = $profile->getProfile();

		$avatar = new wAvatar( $author_user_id, 'm' );

		$articles = $this->getAuthorArticles( $author_index );
		$cssFix = '';
		if ( !$articles ) {
			$cssFix = ' author-container-fix';
		}
		$output = "\t\t\t\t\t<div class=\"author-container$cssFix\">
						<div class=\"author-info\">
							<a href=\"" . htmlspecialchars( $authorTitle->getFullURL() ) . "\" rel=\"nofollow\">
								{$avatar->getAvatarURL()}
							</a>
							<div class=\"author-title\">
								<a href=\"" . htmlspecialchars( $authorTitle->getFullURL() ) .
									'" rel="nofollow">' .
									str_replace( "\n", '<br/>', htmlspecialchars( wordwrap( $author_user_name, 12, "\n", true ) ) ) .
								'</a>
							</div>';
		// If the user has supplied some information about themselves on their
		// social profile, show that data here.
		if ( $profileData['about'] ) {
			$output .= $out->parse( $profileData['about'], false );
		}
		$output .= "\n\t\t\t\t\t\t</div><!-- .author-info -->
						<div class=\"visualClear\"></div>
					</div><!-- .author-container -->
		{$this->getAuthorArticles( $author_index )}";

		return $output;
	}

	public function getAuthorArticles( $author_index ) {
		global $wgBlogPageDisplay, $wgMemc;

		if ( $wgBlogPageDisplay['author_articles'] == false ) {
			return '';
		}

		$user_name = $this->authors[$author_index]['user_name'];
		$user_id = $this->authors[$author_index]['user_id'];

		$archiveLink = Title::makeTitle(
			NS_CATEGORY,
			wfMessage( 'blog-by-user-category', $user_name )->text()
		);

		$articles = [];

		// Try cache first
		$key = $wgMemc->makeKey( 'blog', 'author', 'articles', $user_id );
		$data = $wgMemc->get( $key );

		if ( $data != '' ) {
			wfDebugLog( 'BlogPage', "Got blog author articles for user {$user_name} from cache" );
			$articles = $data;
		} else {
			wfDebugLog( 'BlogPage', "Got blog author articles for user {$user_name} from DB" );
			$dbr = wfGetDB( DB_REPLICA );
			$categoryTitle = Title::newFromText(
				 wfMessage( 'blog-by-user-category', $user_name )->text()
			);
			$res = $dbr->select(
				[ 'page', 'categorylinks' ],
				[ 'DISTINCT(page_id) AS page_id', 'page_title' ],
				/* WHERE */[
					'cl_to' => [ $categoryTitle->getDBkey() ],
					'page_namespace' => NS_BLOG
				],
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC',
					'LIMIT' => 4
				],
				[
					'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ]
				]
			);

			$array_count = 0;

			foreach ( $res as $row ) {
				if ( $row->page_id != $this->getId() && $array_count < 3 ) {
					$articles[] = [
						'page_title' => $row->page_title,
						'page_id' => $row->page_id
					];

					$array_count++;
				}
			}

			// Cache for half an hour
			$wgMemc->set( $key, $articles, 60 * 30 );
		}

		$output = '';
		if ( count( $articles ) > 0 ) {
			$css_fix = '';

			if (
				count( $this->getVotersList() ) == 0 &&
				count( $this->getEditorsList() ) == 0
			) {
				$css_fix = ' more-container-fix';
			}

			$output .= "<div class=\"more-container{$css_fix}\">
			<h3>" . wfMessage( 'blog-author-more-by', $user_name )->escaped() . '</h3>';

			$x = 1;

			foreach ( $articles as $article ) {
				$articleTitle = Title::makeTitle( NS_BLOG, $article['page_title'] );

				$output .= '<div class="author-article-item">
					<a href="' . htmlspecialchars( $articleTitle->getFullURL() ) . '">' . htmlspecialchars( $articleTitle->getText() ) . '</a>
					<div class="author-item-small">' .
						wfMessage(
							'blog-author-votes',
							self::getVotesForPage( $article['page_id'] )
						)->escaped() .
						', ' .
						wfMessage(
							'blog-author-comments',
							self::getCommentsForPage( $article['page_id'] )
						)->escaped() .
						'</div>
				</div>';

				$x++;
			}

			$output .= '<div class="author-archive-link">
				<a href="' . htmlspecialchars( $archiveLink->getFullURL() ) . '">' .
					wfMessage( 'blog-view-archive-link' )->escaped() .
				'</a>
			</div>
		</div>';
		}

		return $output;
	}

	/**
	 * Get the eight newest editors for the current blog post from the revision
	 * table.
	 *
	 * @return array Array containing each editors' user ID and user name
	 */
	public function getEditorsList() {
		global $wgMemc;

		$pageTitleId = $this->getId();

		$key = $wgMemc->makeKey( 'recenteditors', 'list', $pageTitleId );
		$data = $wgMemc->get( $key );
		$editors = [];

		if ( !$data ) {
			wfDebugLog( 'BlogPage', "Loading recent editors for page {$pageTitleId} from DB" );
			$dbr = wfGetDB( DB_REPLICA );

			$where = [
				'rev_page' => $pageTitleId,
				'rev_user <> 0', // exclude anonymous editors
				"rev_user_text <> 'MediaWiki default'", // exclude MW default
			];

			// Get authors and exclude them
			foreach ( $this->authors as $author ) {
				$where[] = 'rev_user_text <> ' . $dbr->addQuotes( $author['user_name'] );
			}

			$res = $dbr->select(
				'revision',
				[ 'DISTINCT rev_user', 'rev_user_text' ],
				$where,
				__METHOD__,
				[ 'ORDER BY' => 'rev_user_text ASC', 'LIMIT' => 8 ]
			);

			foreach ( $res as $row ) {
				$editors[] = [
					'user_id' => $row->rev_user,
					'user_name' => $row->rev_user_text
				];
			}

			// Store in memcached for five minutes
			$wgMemc->set( $key, $editors, 60 * 5 );
		} else {
			wfDebugLog( 'BlogPage', "Loading recent editors for page {$pageTitleId} from cache" );
			$editors = $data;
		}

		return $editors;
	}

	/**
	 * Get the avatars of the people who recently edited this blog post, if
	 * this feature is enabled in BlogPage config.
	 *
	 * @return string HTML or nothing
	 */
	public function recentEditors() {
		global $wgBlogPageDisplay;

		if ( $wgBlogPageDisplay['recent_editors'] == false ) {
			return '';
		}

		$editors = $this->getEditorsList();

		$output = '';

		if ( count( $editors ) > 0 ) {
			$output .= '<div class="recent-container">
			<h2>' . wfMessage( 'blog-recent-editors' )->escaped() . '</h2>
			<div>' . wfMessage( 'blog-recent-editors-message' )->escaped() . '</div>';

			foreach ( $editors as $editor ) {
				$avatar = new wAvatar( $editor['user_id'], 'm' );
				$userTitle = Title::makeTitle( NS_USER, $editor['user_name'] );

				$output .= '<a href="' . htmlspecialchars( $userTitle->getFullURL() ) .
					'">' . $avatar->getAvatarURL( [ 'alt' => $userTitle->getText() ] ) . '</a>';
			}

			$output .= '</div>';
		}

		return $output;
	}

	/**
	 * Get the eight newest voters for the current blog post from VoteNY's
	 * Vote table.
	 *
	 * @return array Array containing each voters' user ID and user name
	 */
	public function getVotersList() {
		global $wgMemc;

		// Gets the page ID for the query
		$pageTitleId = $this->getId();

		$key = $wgMemc->makeKey( 'recentvoters', 'list', $pageTitleId );
		$data = $wgMemc->get( $key );

		$voters = [];
		if ( !$data ) {
			wfDebugLog( 'BlogPage', "Loading recent voters for page {$pageTitleId} from DB" );
			$dbr = wfGetDB( DB_REPLICA );

			$where = [
				'vote_page_id' => $pageTitleId,
				'vote_user_id <> 0'
			];

			// Exclude the authors of the blog post from the list of recent
			// voters
			foreach ( $this->authors as $author ) {
				$where[] = 'username <> ' . $dbr->addQuotes( $author['user_name'] );
			}

			$res = $dbr->select(
				'Vote',
				[ 'DISTINCT username', 'vote_user_id', 'vote_page_id' ],
				$where,
				__METHOD__,
				[ 'ORDER BY' => 'vote_id DESC', 'LIMIT' => 8 ]
			);

			foreach ( $res as $row ) {
				$voters[] = [
					'user_id' => $row->vote_user_id,
					'user_name' => $row->username
				];
			}

			$wgMemc->set( $key, $voters, 60 * 5 );
		} else {
			wfDebugLog( 'BlogPage', "Loading recent voters for page {$pageTitleId} from cache" );
			$voters = $data;
		}

		return $voters;
	}

	/**
	 * Get the avatars of the people who recently voted for this blog post, if
	 * this feature is enabled in BlogPage config.
	 *
	 * @return string HTML or nothing
	 */
	public function recentVoters() {
		global $wgBlogPageDisplay;

		if ( $wgBlogPageDisplay['recent_voters'] == false ) {
			return '';
		}

		$voters = $this->getVotersList();

		$output = '';

		if ( count( $voters ) > 0 ) {
			$output .= '<div class="recent-container bottom-fix">
				<h2>' . wfMessage( 'blog-recent-voters' )->escaped() . '</h2>
				<div>' . wfMessage( 'blog-recent-voters-message' )->escaped() . '</div>';

			foreach ( $voters as $voter ) {
				$userTitle = Title::makeTitle( NS_USER, $voter['user_name'] );
				$avatar = new wAvatar( $voter['user_id'], 'm' );

				$output .= '<a href="' . htmlspecialchars( $userTitle->getFullURL() ) .
					"\">{$avatar->getAvatarURL()}</a>";
			}

			$output .= '</div>';
		}

		return $output;
	}

	/**
	 * Get some random news items from MediaWiki:Inthenews, if this feature is
	 * enabled in BlogPage config and that interface message has some content.
	 *
	 * @return string HTML or nothing
	 */
	public function getInTheNews() {
		global $wgBlogPageDisplay, $wgMemc;

		if ( $wgBlogPageDisplay['in_the_news'] == false ) {
			return '';
		}

		$output = '';
		$message = wfMessage( 'inthenews' )->inContentLanguage();
		if ( !$message->isDisabled() ) {
			$newsArray = explode( "\n\n", $message->plain() );
			$newsItem = $newsArray[array_rand( $newsArray )];
			$output = '<div class="blog-container">
			<h2>' . wfMessage( 'blog-in-the-news' )->escaped() . '</h2>
			<div>' . $this->getContext()->getOutput()->parse( $newsItem, false ) . '</div>
		</div>';
		}

		return $output;
	}

	/**
	 * Get the five most popular blog articles, if this feature is enabled in
	 * BlogPage config.
	 *
	 * @return string HTML or nothing
	 */
	public function getPopularArticles() {
		global $wgMemc, $wgBlogPageDisplay;

		if ( $wgBlogPageDisplay['popular_articles'] == false ) {
			return '';
		}

		// Try cache first
		$key = $wgMemc->makeKey( 'blog', 'popular', 'five' );
		$data = $wgMemc->get( $key );

		if ( $data != '' ) {
			wfDebugLog( 'BlogPage', 'Got popular articles from cache' );
			$popularBlogPosts = $data;
		} else {
			wfDebugLog( 'BlogPage', 'Got popular articles from DB' );
			$dbr = wfGetDB( DB_REPLICA );
			// Code sporked from Rob Church's NewestPages extension
			// @todo FIXME: adding categorylinks table and that one where
			// clause causes an error about "unknown column 'page_id' on ON
			// clause"
			$commentsTable = $dbr->tableName( 'Comments' );
			$voteTable = $dbr->tableName( 'Vote' );
			$res = $dbr->select(
				[ 'page', /*'categorylinks',*/ 'Comments', 'Vote' ],
				[
					'DISTINCT page_id', 'page_namespace', 'page_is_redirect',
					'page_title',
				],
				[
					'page_namespace' => NS_BLOG,
					'page_is_redirect' => 0,
					'page_id = Comment_Page_ID',
					'page_id = vote_page_id',
					// If you can figure out how to do this without a subquery,
					// please let me know. Until that...
					"((SELECT COUNT(*) FROM $voteTable WHERE vote_page_id = page_id) >= 5 OR
					(SELECT COUNT(*) FROM $commentsTable WHERE Comment_Page_ID = page_id) >= 5)",
				],
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC',
					'LIMIT' => 10
				],
				[
					'Comments' => [ 'INNER JOIN', 'page_id = Comment_Page_ID' ],
					'Vote' => [ 'INNER JOIN', 'page_id = vote_page_id' ]
				]
			);

			$popularBlogPosts = [];
			foreach ( $res as $row ) {
				$popularBlogPosts[] = [
					'title' => $row->page_title,
					'id' => $row->page_id
				];
			}

			// Cache in memcached for 15 minutes
			$wgMemc->set( $key, $popularBlogPosts, 60 * 15 );
		}

		$html = '<div class="listpages-container">';
		foreach ( $popularBlogPosts as $popularBlogPost ) {
			$titleObj = Title::makeTitle( NS_BLOG, $popularBlogPost['title'] );
			$html .= '<div class="listpages-item">
					<a href="' . htmlspecialchars( $titleObj->getFullURL() ) . '">' .
						htmlspecialchars( $titleObj->getText() ) .
					'</a>
				</div>
				<div class="visualClear"></div>';
		}
		$html .= '</div>'; // .listpages-container

		$output = '<div class="blog-container">
			<h2>' . wfMessage( 'blog-popular-articles' )->escaped() . '</h2>
			<div>' . $html . '</div>
		</div>';

		return $output;
	}

	/**
	 * Get the newest blog articles, if this feature is enabled in BlogPage
	 * config.
	 *
	 * @return string HTML or nothing
	 */
	public function getNewArticles() {
		global $wgMemc, $wgBlogPageDisplay;

		if ( $wgBlogPageDisplay['new_articles'] == false ) {
			return '';
		}

		// Try cache first
		$key = $wgMemc->makeKey( 'blog', 'newest', '5' );
		$data = $wgMemc->get( $key );

		if ( $data != '' ) {
			wfDebugLog( 'BlogPage', 'Got new articles from cache' );
			$newBlogPosts = $data;
		} else {
			wfDebugLog( 'BlogPage', 'Got new articles from DB' );
			// We could do complicated LIKE stuff with the categorylinks table,
			// but I think we can safely assume that stuff in the NS_BLOG NS
			// is blog-related :)
			$dbr = wfGetDB( DB_REPLICA );
			// Code sporked from Rob Church's NewestPages extension
			$res = $dbr->select(
				'page',
				[ 'page_namespace', 'page_title', 'page_is_redirect', 'page_id' ],
				[ 'page_namespace' => NS_BLOG, 'page_is_redirect' => 0 ],
				__METHOD__,
				[ 'ORDER BY' => 'page_id DESC', 'LIMIT' => 5 ]
			);

			$newBlogPosts = [];
			foreach ( $res as $row ) {
				$newBlogPosts[] = [
					'title' => $row->page_title,
					'ns' => $row->page_namespace,
					'id' => $row->page_id
				];
			}

			// Cache in memcached for 15 minutes
			$wgMemc->set( $key, $newBlogPosts, 60 * 15 );
		}

		$html = '<div class="listpages-container">';
		foreach ( $newBlogPosts as $newBlogPost ) {
			$titleObj = Title::makeTitle( NS_BLOG, $newBlogPost['title'] );
			$html .= '<div class="listpages-item">
					<a href="' . htmlspecialchars( $titleObj->getFullURL() ) . '">' .
						htmlspecialchars( $titleObj->getText() ) .
					'</a>
				</div>
				<div class="visualClear"></div>';
		}
		$html .= '</div>'; // .listpages-container

		$output = '<div class="blog-container bottom-fix">
			<h2>' . wfMessage( 'blog-new-articles' )->escaped() . '</h2>
			<div>' . $html . '</div>
		</div>';

		return $output;
	}

	/**
	 * Get a random casual game, if this feature is enabled in BlogPage config
	 * and the RandomGameUnit extension is installed.
	 *
	 * @return string HTML or nothing
	 */
	public function getRandomCasualGame() {
		global $wgBlogPageDisplay;

		if (
			$wgBlogPageDisplay['games'] == false ||
			!ExtensionRegistry::getInstance()->isLoaded( 'RandomGameUnit' )
		) {
			return '';
		}

		$this->getContext()->getOutput()->addModuleStyles( 'ext.RandomGameUnit.css' );

		return RandomGameUnit::getRandomGameUnit();
	}

	/**
	 * Get comments of the day, if this feature is enabled in BlogPage config.
	 * Requires the Comments extension.
	 *
	 * @return string HTML or nothing
	 */
	public function getCommentsOfTheDay() {
		global $wgBlogPageDisplay, $wgMemc;

		if ( $wgBlogPageDisplay['comments_of_day'] == false ) {
			return '';
		}

		$comments = CommentsOfTheDay::get(
			false/* do NOT skip cache! */,
			60 * 15 /* cache for fifteen minutes */,
			[
				'comment_page_id = page_id',
				// different time-related code here than the cache time!
				'UNIX_TIMESTAMP(comment_date) > ' . ( time() - ( 60 * 60 * 24 ) ),
				'page_namespace' => NS_BLOG
			]
		);

		$output = '';
		if ( count( $comments ) === 0 ) {
			return $output;
		}

		foreach ( $comments as $comment ) {
			$page_title = Title::makeTitle( $comment['namespace'], $comment['title'] );

			if ( $comment['user_id'] != 0 ) {
				$commentPosterDisplay = $comment['user_name'];
			} else {
				$commentPosterDisplay = wfMessage( 'blog-anonymous-name' )->escaped();
			}

			$comment['comment_text'] = strip_tags( $comment['comment_text'] );
			$comment_text = $this->getContext()->getLanguage()->truncateForVisual(
				$comment['comment_text'],
				( 70 - mb_strlen( $commentPosterDisplay ) )
			);

			$output .= '<div class="cod-item">';
			$output .= "<span class=\"cod-score\">{$comment['plus_count']}</span> ";
			$output .= ' <span class="cod-comment"><a href="' .
				htmlspecialchars( $page_title->getFullURL() ) .
				"#comment-{$comment['comment_id']}\" title=\"" .
				htmlspecialchars( $page_title->getText() ) . '">' . htmlspecialchars( $comment_text ) . '</a></span>';
			$output .= '</div>';
		}

		$output = '<div class="blog-container">
			<h2>' . wfMessage( 'blog-comments-of-day' )->escaped() . '</h2>' .
			$output .
		'</div>';

		return $output;
	}

	/**
	 * Get the amount (COUNT(*)) of comments for the given page, identified via
	 * its ID and cache this info in memcached for 15 minutes.
	 *
	 * @param int $id Page ID
	 * @return int Amount of comments
	 */
	public static function getCommentsForPage( $id ) {
		global $wgMemc;

		// Try cache first
		$key = $wgMemc->makeKey( 'blog', 'comments', 'count', 'pageid-' . $id );
		$data = $wgMemc->get( $key );

		if ( $data != '' ) {
			wfDebugLog( 'BlogPage', "Got comments count for the page with ID {$id} from cache" );
			$commentCount = $data;
		} else {
			wfDebugLog( 'BlogPage', "Got comments count for the page with ID {$id} from DB" );
			$dbr = wfGetDB( DB_REPLICA );
			$commentCount = (int)$dbr->selectField(
				'Comments',
				'COUNT(*) AS count',
				[ 'Comment_Page_ID' => intval( $id ) ],
				__METHOD__
			);
			// Store in memcached for 15 minutes
			$wgMemc->set( $key, $commentCount, 60 * 15 );
		}

		return $commentCount;
	}

	/**
	 * Get the amount (COUNT(*)) of votes for the given page, identified via
	 * its ID and cache this info in memcached for 15 minutes.
	 *
	 * @param int $id Page ID
	 * @return int Amount of votes
	 */
	public static function getVotesForPage( $id ) {
		global $wgMemc;

		// Try cache first
		$key = $wgMemc->makeKey( 'blog', 'vote', 'count', 'pageid-' . $id );
		$data = $wgMemc->get( $key );

		if ( $data != '' ) {
			wfDebugLog( 'BlogPage', "Got vote count for the page with ID {$id} from cache" );
			$voteCount = $data;
		} else {
			wfDebugLog( 'BlogPage', "Got vote count for the page with ID {$id} from DB" );
			$dbr = wfGetDB( DB_REPLICA );
			$voteCount = (int)$dbr->selectField(
				'Vote',
				'COUNT(*) AS count',
				[ 'vote_page_id' => intval( $id ) ],
				__METHOD__
			);
			// Store in memcached for 15 minutes
			$wgMemc->set( $key, $voteCount, 60 * 15 );
		}

		return $voteCount;
	}

	/**
	 * Get the first $maxChars characters of a page.
	 *
	 * @param string $pageTitle Page title
	 * @param int $namespace Namespace where the page is in
	 * @param int $maxChars Get the first this many characters of the page
	 * @param string $fontSize Font size; small, medium or large
	 * @return string First $maxChars characters from the page
	 */
	public static function getBlurb( $pageTitle, $namespace, $maxChars, $fontSize = 'small' ) {
		global $wgContLang;

		// Get raw text
		$title = Title::makeTitle( $namespace, $pageTitle );
		$article = new Article( $title );
		$content = $article->getContentObject();
		$text = ContentHandler::getContentText( $content );

		// Remove some problematic characters
		$text = str_replace( '* ', '', $text );
		$text = str_replace( '===', '', $text );
		$text = str_replace( '==', '', $text );
		$text = str_replace( '{{Comments}}', '', $text ); // Template:Comments
		$text = preg_replace( '@<youtube[^>]*?>.*?</youtube>@si', '', $text ); // <youtube> tags (provided by YouTube extension)
		$text = preg_replace( '@<video[^>]*?>.*?</video>@si', '', $text ); // <video> tags (provided by Video extension)
		$text = preg_replace( '@<comments[^>]*?>.*?</comments>@si', '', $text ); // <comments> tags (provided by Comments extension)
		$text = preg_replace( '@<comments[^>]*?\/>@si', '', $text ); // more of the above -- this catches the self-closing variant, <comments />
		$text = preg_replace( '@<vote[^>]*?>.*?</vote>@si', '', $text ); // <vote> tags (provided by Vote extension)
		$text = preg_replace( '@<vote[^>]*?\/>@si', '', $text ); // more of the above -- this catches the self-closing variant, <vote />, although it's unlikely to ever be present in the body of a blog post
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Video' ) ) {
			$videoNS = $wgContLang->getNsText( NS_VIDEO );
			if ( $videoNS === false ) {
				$videoNS = 'Video';
			}
			// [[Video:]] links (provided by Video extension)
			$text = preg_replace( "@\[\[{$videoNS}:[^\]]*?].*?\]@si", '', $text );
		}
		$localizedCategoryNS = $wgContLang->getNsText( NS_CATEGORY );
		$text = preg_replace( "@\[\[(?:(c|C)ategory|{$localizedCategoryNS}):[^\]]*?].*?\]@si", '', $text ); // categories
		// $text = preg_replace( "@\[\[{$localizedCategoryNS}:[^\]]*?].*?\]@si", '', $text ); // original version of the above line

		// Start looking at text after content, and force no Table of Contents
		$pos = strpos( $text, '<!--start text-->' );
		if ( $pos !== false ) {
			$text = substr( $text, $pos );
		}

		$text = '__NOTOC__ ' . $text;

		// Run text through parser
		$blurbText = $article->getContext()->getOutput()->parse( $text );
		$blurbText = strip_tags( $blurbText );

		$blurbText = preg_replace( '/&lt;comments&gt;&lt;\/comments&gt;/i', '', $blurbText );
		$blurbText = preg_replace( '/&lt;vote&gt;&lt;\/vote&gt;/i', '', $blurbText );

		// $blurbText = $text;
		$pos = strpos( $blurbText, '[' );
		if ( $pos !== false ) {
			$blurbText = substr( $blurbText, 0, $pos );
		}

		// Take first N characters, and then make sure it ends on last full word
		$max = 300;
		if ( strlen( $blurbText ) > $max ) {
			$blurbText = strrev( strstr( strrev( substr( $blurbText, 0, $max ) ), ' ' ) );
		}

		// Prepare blurb font size
		$blurbFont = '<span class="listpages-blurb-size-';
		if ( $fontSize == 'small' ) {
			$blurbFont .= 'small';
		} elseif ( $fontSize == 'medium' ) {
			$blurbFont .= 'medium';
		} elseif ( $fontSize == 'large' ) {
			$blurbFont .= 'large';
		}
		$blurbFont .= '">';

		// Fix multiple whitespace, returns etc
		$blurbText = trim( $blurbText ); // remove trailing spaces
		$blurbText = preg_replace( '/\s(?=\s)/', '', $blurbText ); // remove double whitespace
		$blurbText = preg_replace( '/[\n\r\t]/', ' ', $blurbText ); // replace any non-space whitespace with a space

		return $blurbFont . $blurbText . '. . . <a href="' .
			htmlspecialchars( $title->getFullURL() ) . '">' . wfMessage( 'blog-more' )->escaped() .
			'</a></span>';
	}

	/**
	 * Get the image associated with the given page (via the page's ID).
	 *
	 * @param int $pageId Page ID number
	 * @return string File name or nothing
	 */
	public static function getPageImage( $pageId ) {
		global $wgMemc;

		$key = $wgMemc->makeKey( 'blog', 'page', 'image', $pageId );
		$data = $wgMemc->get( $key );

		if ( !$data ) {
			$dbr = wfGetDB( DB_REPLICA );
			$il_to = $dbr->selectField(
				'imagelinks',
				'il_to',
				[ 'il_from' => intval( $pageId ) ],
				__METHOD__
			);
			// Cache in memcached for a minute
			$wgMemc->set( $key, $il_to, 60 );
		} else {
			wfDebugLog( 'BlogPage', "Loading image for page {$pageId} from cache\n" );
			$il_to = $data;
		}

		return $il_to;
	}

	/**
	 * Yes, these are those fucking time-related functions once more.
	 * You probably have seen these in UserBoard, Comments...god knows where.
	 * Seriously, this stuff is all over the place.
	 */
	public static function dateDiff( $date1, $date2 ) {
		$dtDiff = $date1 - $date2;

		$totalDays = intval( $dtDiff / ( 24 * 60 * 60 ) );
		$totalSecs = $dtDiff - ( $totalDays * 24 * 60 * 60 );
		$dif = [];
		$dif['w'] = intval( $totalDays / 7 );
		$dif['d'] = $totalDays;
		$dif['h'] = $h = intval( $totalSecs / ( 60 * 60 ) );
		$dif['m'] = $m = intval( ( $totalSecs - ( $h * 60 * 60 ) ) / 60 );
		$dif['s'] = $totalSecs - ( $h * 60 * 60 ) - ( $m * 60 );

		return $dif;
	}

	public static function getTimeOffset( $time, $timeabrv, $timename ) {
		$timeStr = '';
		if ( $time[$timeabrv] > 0 ) {
			$timeStr = wfMessage( "blog-time-$timename", $time[$timeabrv] )->text();
		}
		if ( $timeStr ) {
			$timeStr .= ' ';
		}
		return $timeStr;
	}

	public static function getTimeAgo( $time ) {
		$timeArray = self::dateDiff( time(), $time );
		$timeStr = '';
		$timeStrD = self::getTimeOffset( $timeArray, 'd', 'days' );
		$timeStrH = self::getTimeOffset( $timeArray, 'h', 'hours' );
		$timeStrM = self::getTimeOffset( $timeArray, 'm', 'minutes' );
		$timeStrS = self::getTimeOffset( $timeArray, 's', 'seconds' );
		$timeStr = $timeStrD;
		if ( $timeStr < 2 ) {
			$timeStr .= $timeStrH;
			$timeStr .= $timeStrM;
			if ( !$timeStr ) {
				$timeStr .= $timeStrS;
			}
		}
		if ( !$timeStr ) {
			$timeStr = wfMessage( 'blog-time-seconds' )->numParams( 1 )->text();
		}
		return $timeStr;
	}
}
