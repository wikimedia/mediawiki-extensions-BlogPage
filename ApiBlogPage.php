<?php
/**
 * BlogPage API module
 *
 * As of 23 February 2016, this does precisely one thing:
 * checks if there is already a blog post with the given title.
 *
 * @file
 * @ingroup API
 * @date 23 February 2016
 * @see https://www.mediawiki.org/wiki/API:Extensions#ApiSampleApiExtension.php
 */
class ApiBlogPage extends ApiBase {

	/**
	 * Main entry point.
	 */
	public function execute() {
		// Get the request parameters
		$params = $this->extractRequestParams();

		$pageName = $params['pageName'];
		$output = SpecialCreateBlogPost::checkTitleExistence( $pageName );

		// Top level
		$this->getResult()->addValue( null, $this->getModuleName(),
			array( 'result' => $output )
		);

		return true;
	}

	/**
	 * @return array
	 */
	public function getAllowedParams() {
		return array(
			'pageName' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			)
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=blogpage&pageName=My%20Cool%20New%20Blog%20Post' => 'apihelp-blogpage-example-1'
		);
	}
}
