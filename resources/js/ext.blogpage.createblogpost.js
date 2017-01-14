( function ( mw, $ ) {
var CreateBlogPost = {
	/**
	 * Insert a tag (category) from the category cloud into the inputbox below
	 * it on Special:CreateBlogPost
	 *
	 * @param tagname String: category name
	 * @param tagnumber Integer
	 */
	insertTag: function( tagname, tagnumber ) {
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
	 */
	performChecks: function() {
		/*global alert */
		// In PHP, we need to use $wgRequest->getVal( 'title2' ); 'title'
		// contains the current special page's name instead of the blog post
		// name
		var title = document.getElementById( 'title' ).value;
		if ( !title || title === '' ) {
			alert( mw.msg( 'blog-js-create-error-need-title' ) );
			return '';
		}
		var pageBody = document.getElementById( 'pageBody' ).value;
		if ( !pageBody || pageBody === '' ) {
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

$( function() {
	// Tag cloud
	$( 'a.tag-cloud-entry' ).each( function () {
		var that = $( this );
		that.click( function() {
			CreateBlogPost.insertTag(
				that.data( 'blog-slashed-tag' ),
				that.data( 'blog-tag-number' )
			);
		} );
	} );

	// Save button
	$( 'input[name="wpSave"]' ).click( function() {
		CreateBlogPost.performChecks();
	} );
} );
}( mediaWiki, jQuery ) );
