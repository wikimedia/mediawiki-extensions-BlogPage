( function ( mw, $ ) {
	var CreateBlogPost = {
	/**
	 * Insert a tag (category) from the category cloud into the inputbox below
	 * it on Special:CreateBlogPost
	 *
	 * @param {string} tagname category name
	 * @param {number} tagnumber
	 */
		insertTag: function ( tagname, tagnumber ) {
			$( '#tag-' + tagnumber ).css( 'color', '#CCCCCC' ).text( tagname );
			// Funny...if you move this getElementById call into a variable and use
			// that variable here, this won't work as intended
			document.getElementById( 'pageCtg' ).value +=
			( ( document.getElementById( 'pageCtg' ).value ) ? ', ' : '' ) +
			tagname;
		},

		/**
	 * Check that the user has given a title for the blog post and has supplied
	 * some content; then check the existence of the title and notify the user
	 * if there's already a blog post with the same name as their blog post.
	 *
	 * @param {jQuery.Event} e
		 * @return {string}
	 */
		performChecks: function ( e ) {
		// In PHP, we need to use $wgRequest->getVal( 'title2' ); 'title'
		// contains the current special page's name instead of the blog post
		// name
			var title = document.getElementById( 'title' ).value,
				textarea = document.getElementById( 'wpTextbox1' ).value;
			if ( !title || title === '' ) {
			// First prevent the default action (which would be to submit the form)
				e.preventDefault();
				alert( mw.msg( 'blog-js-create-error-need-title' ) );
				return '';
			}
			if ( !textarea || textarea === '' ) {
			// First prevent the default action (which would be to submit the form)
				e.preventDefault();
				alert( mw.msg( 'blog-js-create-error-need-content' ) );
				return '';
			}

			$.post(
				mw.util.wikiScript( 'api' ), {
					action: 'blogpage',
					format: 'json',
					pageName: title
				},
				function ( data ) {
					if ( data.blogpage.result === 'OK' ) {
						document.editform.submit();
					} else {
						alert( mw.msg( 'blog-js-create-error-page-exists' ) );
					}
				}
			);
		}
	};

	$( function () {
	// Tag cloud
		$( 'a.tag-cloud-entry' ).each( function () {
			var $that = $( this );
			$that.on( 'click', function () {
				CreateBlogPost.insertTag(
					$that.data( 'blog-slashed-tag' ),
					$that.data( 'blog-tag-number' )
				);
			} );
		} );

		// Save button
		$( 'input[name="wpSave"]' ).on( 'click', function ( e ) {
			CreateBlogPost.performChecks( e );
		} );
	} );
	// eslint-disable-next-line no-undef
}( mediaWiki, jQuery ) );
