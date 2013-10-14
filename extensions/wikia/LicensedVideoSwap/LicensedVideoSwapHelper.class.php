<?php

use NlpTools\Tokenizers\WhitespaceAndPunctuationTokenizer, NlpTools\Stemmers\PorterStemmer, NlpTools\Similarity\JaccardIndex;

/**
 * LicensedVideoSwap Helper
 * @author Garth Webb
 * @author Liz Lee
 * @author Saipetch Kongkatong
 */
class LicensedVideoSwapHelper extends WikiaModel {

	const STATUS_KEEP = 1;
	const STATUS_SWAP_NORM = 2;
	const STATUS_SWAP_EXACT = 3;
	const STATUS_KEEP_FOREVER = 4;
	const STATUS_SWAPPABLE = 5;
	const STATUS_NEW_KEEP = 6;          // kept videos with new suggestions
	const STATUS_NEW_SWAPPABLE = 7;     // swappable videos with new suggestions

	/**
	 * Duration variation in seconds
	 * @var int
	 */
	const DURATION_DELTA = 10;

	/**
	 * Threshold used with duration to constrain matches
	 * @var float
	 */
	const MIN_JACCARD_SIMILARITY = 0.7;

	const VIDEOS_PER_PAGE = 10;
	const THUMBNAIL_WIDTH = 500;
	const THUMBNAIL_HEIGHT = 309;
	const POSTED_IN_ARTICLES = 100;
	const NUM_SUGGESTIONS = 5;

	/**
	 * TTL of 604800 is 7 days.  Expire suggestion cache after this
	 * @var int
	 */
	const SUGGESTIONS_TTL = 604800;

	/**
	 * Tokenizer for text processing
	 * @var NlpTools\Tokenizers\WhitespaceAndPunctuationTokenizer
	 */
	protected $tokenizer;

	/**
	 * Stemmer for text processing
	 * @var NlpTools\Stemmers\PorterStemmer
	 */
	protected $stemmer;

	/**
	 * Used to compute Jaccard similarity
	 * @var NlpTools\Similarity\JaccardIndex
	 */
	protected $jaccard;

	// list of status that can be displayed in Special:LicensedVideoSwap page
	protected static $displayList = array(
		self::STATUS_SWAPPABLE, self::STATUS_NEW_KEEP, self::STATUS_NEW_SWAPPABLE,
	);

	/**
	 * Gets a list of videos that have not yet been swapped (e.g., no decision to keep or not keep the
	 * original video has been made)
	 *
	 * @param string $sort - The sort order for the video list (options: recent, popular, trend)
	 * @param int $limit - The number of videos to return
	 * @param int $page - Which page of video to return
	 * @param bool $use_master - Whether to use the master DB to do this query
	 * @return array - An array of video metadata
	 */
	public function getUnswappedVideoList ( $sort = 'popular', $limit = 10, $page = 1, $use_master = false ) {
		wfProfileIn( __METHOD__ );

		if ( $use_master ) {
			$db = wfGetDB( DB_MASTER );
		} else {
			$db = wfGetDB( DB_SLAVE );
		}

		// We want to make sure the video hasn't been removed, is not premium and does not exist
		// in the video_swap table
		$statusProp = WPP_LVS_STATUS;
		$pageNS = NS_FILE;
		$swappableStatus = implode( ',', self::$displayList );
		$offset = ( $page - 1 ) * $limit;

		// Get the right sorting
		switch ( $sort ) {
			case 'popular': $order = 'views_total DESC';
				break;
			case 'trend'  : $order = 'views_30day DESC';
				break;
			default:        $order = 'added_at DESC';
		}

		$sql = <<<SQL
			SELECT video_title, added_at, added_by, page.page_id, props
			FROM video_info
			JOIN page ON video_title = page_title AND page_namespace = $pageNS
			LEFT JOIN page_wikia_props ON page.page_id = page_wikia_props.page_id
				AND propname = $statusProp AND props in ($swappableStatus)
			WHERE removed = 0 AND premium = 0 AND page_wikia_props.page_id is not null
			ORDER BY $order
			LIMIT $limit
			OFFSET $offset
SQL;

		// Select video info making sure to skip videos that have entries in the video_swap table
		$result = $db->query( $sql, __METHOD__ );

		// Build the return array
		$videoList = array();
		while ( $row = $db->fetchObject( $result ) ) {
			$videoList[] = array(
				'title' => $row->video_title,
				'addedAt' => $row->added_at,
				'addedBy' => $row->added_by,
				'pageId' => $row->page_id,
				'status' => $row->props,
			);
		}

		wfProfileOut( __METHOD__ );

		return $videoList;
	}

	/**
	 * Get the total number of unswapped videos
	 * @return int - The number of unswapped videos
	 */
	public function getUnswappedVideoTotal ( ) {
		wfProfileIn( __METHOD__ );

		$db = wfGetDB( DB_SLAVE );

		// We want to make sure the video hasn't been removed, is not premium and does not exist
		// in the video_swap table
		$statusProp = WPP_LVS_STATUS;
		$pageNS = NS_FILE;
		$swappableStatus = implode( ',', self::$displayList );

		$sql = <<<SQL
			SELECT count(*) as total
			FROM video_info
			JOIN page ON video_title = page_title AND page_namespace = $pageNS
			LEFT JOIN page_wikia_props ON page.page_id = page_wikia_props.page_id
				AND propname = $statusProp AND props in ($swappableStatus)
			WHERE removed = 0 AND premium = 0 AND page_wikia_props.page_id is not null
SQL;

		// Select video info making sure to skip videos that have entries in the video_swap table
		$result = $db->query( $sql, __METHOD__ );

		// Get the total count of relavent videos
		while ( $row = $db->fetchObject( $result ) ) {
			$total = $row->total;

			// Should only be one result
			break;
		}

		wfProfileOut( __METHOD__ );

		return $total;
	}

	/**
	 * Get a list of non-premium video that is available to swap
	 *
	 * @param string $sort - The sort order for the video list (options: recent, popular, trend)
	 * @param int $page - Which page to display. Each page contains self::VIDEOS_PER_PAGE videos
	 * @param bool $use_master
	 * @return array - Returns a list of video metadata
	 */
	public function getRegularVideoList ( $sort, $page, $use_master = false ) {
		wfProfileIn( __METHOD__ );

		// Get the play button image to overlay on the video
		$playButton = WikiaFileHelper::videoPlayButtonOverlay( self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT );

		// Get the list of videos that haven't been swapped yet
		$videoList = $this->getUnswappedVideoList( $sort, self::VIDEOS_PER_PAGE, $page, $use_master );

		// Reuse code from VideoHandlerHelper
		$helper = new VideoHandlerHelper();

		// Go through each video and add additional detail needed to display the video
		$videos = array();
		foreach ( $videoList as $videoInfo ) {
			$suggestions = $this->getVideoSuggestions( $videoInfo['title'] );

			// Leave out this video if it has no suggestions
			if ( empty( $suggestions ) ) {
				continue;
			}

			$videoDetail = $helper->getVideoDetail( $videoInfo, self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT, self::POSTED_IN_ARTICLES );
			if ( !empty($videoDetail) ) {
				$videoOverlay =  WikiaFileHelper::videoInfoOverlay( self::THUMBNAIL_WIDTH, $videoDetail['fileTitle'] );

				$videoDetail['videoPlayButton'] = $playButton;
				$videoDetail['videoOverlay'] = $videoOverlay;
				$videoDetail['videoSuggestions'] = $suggestions;

				$seeMoreLink = SpecialPage::getTitleFor( "WhatLinksHere" )->escapeLocalUrl();
				$seeMoreLink .= '/' . $this->app->wg->ContLang->getNsText( NS_FILE ). ':' . $videoDetail['title'];

				$videoDetail['seeMoreLink'] = $seeMoreLink;

				$videoDetail['confirmKeep'] = ( $videoInfo['status'] == self::STATUS_NEW_KEEP );

				$videos[] = $videoDetail;
			} else {
				// Something is wrong with the existing video.  Mark this as having no suggestions
				// to hide it here.  Could mark it as skipped but then it would show up on the
				// history page and that would be confusing.
				$titleObj = Title::newFromText( $videoInfo['title'], NS_FILE );
				$articleId = $titleObj->getArticleID();

				wfSetWikiaPageProp( WPP_LVS_EMPTY_SUGGEST, $articleId, 1 );
			}
		}

		wfProfileOut( __METHOD__ );

		return $videos;
	}

	/**
	 * Get video suggestions
	 * @param $title - The title of the video
	 * @return array videos
	 */
	public function getVideoSuggestions( $title ) {
		wfProfileIn( __METHOD__ );

		// Find the article ID for this title
		$titleObj = Title::newFromText( $title, NS_FILE );
		$articleId = $titleObj->getArticleID();

		// See if we've already cached suggestions for this video
		$videos = wfGetWikiaPageProp( WPP_LVS_SUGGEST, $articleId );
		if ( !empty($videos) ) {
			$age = wfGetWikiaPageProp( WPP_LVS_SUGGEST_DATE, $articleId );

			// If we're under the TTL then return these results, otherwise
			// fall through and generate some new ones
			if ( time() - $age < self::SUGGESTIONS_TTL ) {
				wfProfileOut( __METHOD__ );
				return $videos;
			}
		}

		$videos = $this->suggestionSearch( $titleObj );

		wfProfileOut( __METHOD__ );
		return $videos;
	}

	/**
	 * Search for related video titles
	 * @param Title|string $title - Either a Title object or title text
	 * @param bool $test - Operate in test mode.  Allows commandline scripts to implement --test
	 * @return array - A list of suggested videos
	 */
	public function suggestionSearch( $title, $test = false ) {
		// Accept either a title string or title object here
		$titleObj = is_object( $title ) ? $title : Title::newFromText( $title, NS_FILE );
		$articleId = $titleObj->getArticleID();

		$readableTitle = $titleObj->getText();

		$params = array( 'title' => $readableTitle );

		$file = wfFindFile( $titleObj );
		if ( !empty( $file ) ) {
			$serializedMetadata = $file->getMetadata();
			if ( !empty( $serializedMetadata ) ) {
				$metadata = unserialize( $serializedMetadata );
				if ( !empty( $metadata['duration'] ) ) {
					$duration = $metadata['duration'];
					$params['minseconds'] = $duration - min( [ $duration, self::DURATION_DELTA ] );
					$params['maxseconds'] = $duration + min( [ $duration, self::DURATION_DELTA ] );
				}
			}
		}

		// get search results
		$videoRows = $this->app->sendRequest( 'WikiaSearchController', 'searchVideosByTitle', $params )
								->getData();

		if ( !$test ) {
			wfSetWikiaPageProp( WPP_LVS_SUGGEST_DATE, $articleId, time() );
		}

		// Reuse code from VideoHandlerHelper
		$helper = new VideoHandlerHelper();

		// Get the play button image to overlay on the video
		$playButton = WikiaFileHelper::videoPlayButtonOverlay( self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT );

		// get videos that have been suggested (kept videos)
		$historicalSuggestions = array_flip( $this->getHistoricalSuggestions( $articleId ) );

		// get current suggestions
		$suggestTitles = array();
		$suggestions = wfGetWikiaPageProp( WPP_LVS_SUGGEST, $articleId );
		if ( empty( $suggestions ) ) {
			$suggestions = array();
		} else {
			foreach ( $suggestions as $video ) {
				$suggestTitles[$video['title']] = 1;
			}
		}

		// flag for kept video
		$isKeptVideo = $this->isKept( $articleId );

		$videos = array();
		$count = 0;
		$isNewKeep = false;
		$isNewSwappable = false;

		$titleTokenized = $this->getNormalizedTokens( $readableTitle );

		foreach ( $videoRows as $videoInfo ) {
			$videoRowTitleTokenized = $this->getNormalizedTokens( preg_replace( '/^File:/', '',  $videoInfo['title'] ) );
			if ( $this->getJaccard()->similarity( $titleTokenized, $videoRowTitleTokenized ) < self::MIN_JACCARD_SIMILARITY ) {
				continue;
			}

			$videoTitle = preg_replace( '/.+File:/', '', urldecode( $videoInfo['url'] ) );

			// skip if the video has already been suggested (from kept videos)
			if ( $isKeptVideo ) {
				if ( array_key_exists( $videoTitle, $historicalSuggestions ) ) {
					continue;
				} else {
					$isNewKeep = true;
				}
			}

			// skip if the video exists in the current suggestions
			if ( array_key_exists( $videoTitle, $suggestTitles ) ) {
				continue;
			} else if ( !$isNewKeep ) {
				$isNewSwappable = true;
			}

			// get video detail
			$videoDetail = $helper->getVideoDetailFromWiki( $this->wg->WikiaVideoRepoDBName,
															$videoInfo['title'],
															self::THUMBNAIL_WIDTH,
															self::THUMBNAIL_HEIGHT,
															self::POSTED_IN_ARTICLES );

			// Go to the next suggestion if we can't get any details for this one
			if ( empty( $videoDetail ) ) {
				continue;
			}

			// get video overlay
			$videoOverlay =  WikiaFileHelper::videoInfoOverlay( self::THUMBNAIL_WIDTH, $videoDetail['fileTitle'] );
			$videoDetail['videoPlayButton'] = $playButton;
			$videoDetail['videoOverlay'] = $videoOverlay;

			$videos[] = $videoDetail;

			$count++;
			if ( $count >= self::NUM_SUGGESTIONS ) {
				break;
			}
		}

		// combine current suggestions and new suggestions
		$videos = array_slice( array_merge( $videos, $suggestions ), 0 , self::NUM_SUGGESTIONS );

		// If we're just testing don't set any page props
		if ( !$test ) {
			// Cache these suggestions
			if ( empty( $videos ) ) {
				wfSetWikiaPageProp( WPP_LVS_EMPTY_SUGGEST, $articleId, 1 );
			} else {
				wfDeleteWikiaPageProp( WPP_LVS_EMPTY_SUGGEST, $articleId );
				wfSetWikiaPageProp( WPP_LVS_SUGGEST, $articleId, $videos );

				// set page status
				if ( !$this->isSwapped( $articleId ) && !$this->isKeptForever( $articleId ) ) {
					if ( $isNewKeep ) {
						$this->setPageStatusNewKeep( $articleId );
					} else if ( $isNewSwappable ) {
						$this->setPageStatusNewSwappable( $articleId );
					}
				}
			}
		}

		wfProfileOut( __METHOD__ );

		// The first video in the array is the top choice.
		return $videos;
	}

	/**
	 * Get file object (video only)
	 * @param string $videoTitle
	 * @param boolean $force
	 * @return File|null $result
	 */
	public function getVideoFile( $videoTitle, $force = false ) {
		$result = null;

		$title = Title::newFromText( $videoTitle,  NS_FILE );
		if ( $title instanceof Title ) {
			// clear cache for file object
			if ( $force ) {
				RepoGroup::singleton()->clearCache( $title );
			}

			$file = wfFindFile( $title );
			if ( $file instanceof File && $file->exists() && WikiaFileHelper::isFileTypeVideo( $file ) ) {
				$result = $file;
			}
		}

		return $result;
	}

	/**
	 * Set the LVS status info of this file page to swapped
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusInfoSwap( $articleId, $value = array() ) {
		wfSetWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId, $value );
	}

	/**
	 * Set the LVS status of this file page to swapped
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusSwap( $articleId, $value = array() ) {
		$value['status'] =  self::STATUS_SWAP_NORM;
		$value['created'] = time();
		$value['userid'] = $this->wg->User->getId();
		wfSetWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId, $value );
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, $value['status'] );
	}

	/**
	 * Set the LVS status of this file page to swapped with an exact match
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusSwapExact( $articleId, $value = array() ) {
		$value['status'] =  self::STATUS_SWAP_EXACT;
		$value['created'] = time();
		$value['userid'] = $this->wg->User->getId();
		wfSetWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId, $value );
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, $value['status'] );
	}

	/**
	 * Set the LVS status of this file page to kept
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusKeep( $articleId, $value = array() ) {
		$value['created'] = time();
		$value['userid'] = $this->wg->User->getId();
		wfSetWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId, $value );
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, $value['status'] );
	}

	/**
	 * Set the LVS status of this file page to swappable
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusSwappable( $articleId ) {
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, self::STATUS_SWAPPABLE );
	}

	/**
	 * Set the LVS status of this file page to new for kept videos
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusNewKeep( $articleId ) {
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, self::STATUS_NEW_KEEP );
	}

	/**
	 * Set the LVS status of this file page to new for swappable videos
	 * @param integer $articleId - The ID of a video's file page
	 * @param array $value
	 */
	public function setPageStatusNewSwappable( $articleId ) {
		wfSetWikiaPageProp( WPP_LVS_STATUS, $articleId, self::STATUS_NEW_SWAPPABLE );
	}

	/**
	 * delete the LVS status and status info of this file page
	 * @param type $articleId
	 */
	public function deletePageStatus( $articleId ) {
		wfDeleteWikiaPageProp( WPP_LVS_STATUS, $articleId );
		wfDeleteWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId );
	}

	/**
	 * Delete the LVS status info of this file page
	 * @param type $articleId
	 */
	public function deletePageStatusInfo( $articleId ) {
		wfDeleteWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId );
	}

	/**
	 * Get the status of this file page
	 * @param integer $articleId
	 * @return array|null
	 */
	public function getPageStatus( $articleId ) {
		return  wfGetWikiaPageProp( WPP_LVS_STATUS, $articleId );
	}

	/**
	 * Get the LVS status info of this file page [STATUS_KEEP, STATUS_SWAP_EXACT, STATUS_SWAP_NORM]
	 * @param integer $articleId
	 * @return array|null
	 */
	public function getPageStatusInfo( $articleId ) {
		return  wfGetWikiaPageProp( WPP_LVS_STATUS_INFO, $articleId );
	}

	/**
	 * Move suggestion data to new article for WPP_LVS_SUGGEST, WPP_LVS_SUGGEST_DATE
	 * @param integer $oldArticleId
	 * @param integer $newArticleId
	 */
	public function moveSuggestionData( $oldArticleId, $newArticleId ) {
		$this->moveWikiaPageProp( WPP_LVS_SUGGEST, $oldArticleId, $newArticleId );
		$this->moveWikiaPageProp( WPP_LVS_SUGGEST_DATE, $oldArticleId, $newArticleId );
	}

	/**
	 * Move prop in page_wikia_props table to new article
	 * @param integer $type
	 * @param integer $oldArticleId
	 * @param integer $newArticleId
	 */
	public function moveWikiaPageProp( $type, $oldArticleId, $newArticleId ) {
		$prop = wfGetWikiaPageProp( $type, $oldArticleId );
		if ( !empty( $prop ) ) {
			wfDeleteWikiaPageProp( $type, $oldArticleId );
			wfSetWikiaPageProp( $type, $newArticleId, $prop );
		}
	}

	/**
	 * Add log to RecentChange
	 * @param Title $title
	 * @param string $action
	 * @param string $reason
	 */
	public function addLog( $title, $action, $reason = '' ) {
		$user = $this->wg->User;
		RecentChange::notifyLog( wfTimestampNow(), $title, $user, '', '', RC_EDIT, $action, $title, $reason, '' );
	}

	/**
	 * Check if the video is swapped
	 * @param integer $articleId
	 * @return boolean $status
	 */
	public function isSwapped( $articleId ) {
		$pageStatus = $this->getPageStatus( $articleId );
		$status = ( !empty( $pageStatus ) && ( $pageStatus == self::STATUS_SWAP_EXACT || $pageStatus == self::STATUS_SWAP_NORM ) );

		return $status;
	}

	/**
	 * Check if the video is kept
	 * @param integer $articleId
	 * @return boolean $status
	 */
	public function isKept( $articleId ) {
		$pageStatus = $this->getPageStatus( $articleId );
		$status = ( !empty( $pageStatus ) && ( $pageStatus == self::STATUS_KEEP || $pageStatus == self::STATUS_NEW_KEEP ) );

		return $status;
	}

	/**
	 * Check if the video is kept forever
	 * @param integer $articleId
	 * @return boolean $status
	 */
	public function isKeptForever( $articleId ) {
		$pageStatus = $this->getPageStatus( $articleId );
		$status = ( !empty( $pageStatus ) && $pageStatus == self::STATUS_KEEP_FOREVER );

		return $status;
	}

	/**
	 * Check if the video has new suggestions
	 * @param integer $articleId
	 * @return boolean $status
	 */
	public function isNew( $articleId ) {
		$pageStatus = $this->getPageStatus( $articleId );
		$status = ( !empty( $pageStatus ) && ( $pageStatus == self::STATUS_NEW_KEEP || $pageStatus == self::STATUS_NEW_SWAPPABLE ) );

		return $status;
	}

	/**
	 * Add redirect link to the article
	 * @param Title $title
	 * @param Title $newTitle
	 * @return Status $status
	 */
	public function addRedirectLink( $title, $newTitle ) {
		wfProfileIn( __METHOD__ );

		$article = new Article( $title );
		$content = "#REDIRECT [[{$newTitle->getPrefixedText()}]]";
		$summary = wfMessage( 'autoredircomment', $title->getFullText() )->inContentLanguage()->text();
		$status = $article->doEdit( $content, $summary );

		wfProfileOut( __METHOD__ );

		return $status;
	}

	/**
	 * Remove redirect link (last revision)
	 * @param Article $article
	 * @return Status $status
	 */
	public function removeRedirectLink( $article ) {
		wfProfileIn( __METHOD__ );

		$lastRevision = $article->getRevision();
		$previousRevision = $lastRevision->getPrevious();
		if ( $previousRevision instanceof Revision ) {
			$baseRevId = $previousRevision->getId();
			$previousText = $previousRevision->getText();
		} else {
			$baseRevId = false;
			$previousText = '';
		}
		$summary = wfMessage( 'lvs-log-removed-redirected-link' )->inContentLanguage()->text();
		$status = $article->doEdit( $previousText, $summary, EDIT_UPDATE, $baseRevId );

		wfProfileOut( __METHOD__ );

		return $status;
	}

	/**
	 * Undelete page (local file)
	 * @param Title $title
	 * @param boolean $removePremium
	 * @return Status $status
	 */
	public function undeletePage( $title, $removePremium = false ) {
		wfProfileIn( __METHOD__ );

		// use Phalanx to check recovered page title
		if ( !wfRunHooks( 'CreatePageTitleCheck', array( $title, false ) ) ) {
			wfProfileOut( __METHOD__ );
			return Status::newFatal( wfMessage( 'spamprotectiontext' )->text() );
		}

		$archive = new PageArchive( $title );
		wfRunHooks( 'UndeleteForm::undelete', array( &$archive, $title ) );

		$time = '';
		$comment = '';
		$fileVersions = array();
		$unsuppress = false;

		$status = $archive->undelete( $time, $comment, $fileVersions, $unsuppress );
		if ( is_array( $status ) ) {
			// Undeleted file count
			if ( $status[1] ) {
				// remove premium video from video_info table if swapping same video titles
				if ( $removePremium ) {
					wfRunHooks( 'RemovePremiumVideo', array( $title ) );
				}

				// clear file cache
				RepoGroup::singleton()->clearCache( $title );

				wfRunHooks( 'FileUndeleteComplete', array( $title, $fileVersions, $this->wg->User, $comment ) );
			}
			$status = Status::newGood();
		} else {
			$status = Status::newFatal( wfMessage( 'cannotundelete' )->text() );
		}

		wfProfileOut( __METHOD__ );

		return $status;
	}

	/**
	 * Get pagination
	 * @param integer $currentPage
	 * @param string $selectedSort
	 * @return string $pagination
	 */
	public function getPagination( $currentPage, $selectedSort ) {
		$pagination = '';
		$totalVideos = $this->getUnswappedVideoTotal();
		if ( $totalVideos > self::VIDEOS_PER_PAGE ) {
			$pages = Paginator::newFromArray( array_fill( 0, $totalVideos, '' ), self::VIDEOS_PER_PAGE );
			$pages->setActivePage( $currentPage - 1 );

			$linkToSpecialPage = SpecialPage::getTitleFor( "LicensedVideoSwap" )->escapeLocalUrl();
			$pagination = $pages->getBarHTML( $linkToSpecialPage.'?currentPage=%s&sort='.$selectedSort );
		}

		return $pagination;
	}

	/**
	 * Lazy-loads dependency
	 * @return \NlpTools\Stemmers\PorterStemmer
	 */
	protected function getStemmer() {
		if ( $this->stemmer === null ) {
			$this->stemmer = new PorterStemmer;
		}
		return $this->stemmer;
	}

	/**
	 * Lazy-loads dependency
	 * @return \NlpTools\Tokenizers\WhitespaceAndPunctuationTokenizer
	 */
	protected function getTokenizer() {
		if ( $this->tokenizer === null ) {
			$this->tokenizer = new WhitespaceAndPunctuationTokenizer;
		}
		return $this->tokenizer;
	}

	/**
	 * Lazy-loads dependency
	 * @return \NlpTools\Similarity\JaccardIndex
	 */
	protected function getJaccard() {
		if ( $this->jaccard === null ) {
			$this->jaccard = new JaccardIndex;
		}
		return $this->jaccard;
	}

	/**
	 * This gets all important tokens by tokenizing, stemming, and then stripping punctuation tokens
	 * @param string $str
	 * @return array
	 */
	protected function getNormalizedTokens( $str ) {
		return array_filter( $this->getStemmer()->stemAll( $this->getTokenizer()->tokenize( $str ) ),
				function( $val ) { return preg_match( '/[[:punct:]]/', $val ) == 0; } );
	}

	/**
	 * Get list of swapped or kept videos that we can undo
	 * @param $context
	 * @return array $videoList
	 */
	public function getUndoList( $context ) {
		$db = wfGetDB( DB_SLAVE );
		$result = $db->select(
			array( 'page_wikia_props' ),
			array( '*' ),
			array( 'propname' => WPP_LVS_STATUS_INFO ),
			__METHOD__
		);

		$videoList = array();
		while( $row = $db->fetchObject($result) ) {
			// Don't continue if we can't load the article
			$article = Article::newFromID( $row->page_id );
			if ( empty( $article ) ) {
				continue;
			}

			// The props hold all the information about the swap/keep
			$props = unserialize( $row->props );

			// Get the username and link to the user page
			$userName = $userLink = '';
			if ( !empty($props['userid']) ) {
				$userObj = User::newFromId( $props['userid'] );
				if ( $userObj ) {
					$userName = $userObj->getName();
					$userLink = $userObj->getUserPage()->getLocalURL();
				}
			}

			// Get the date in the proper format
			if ( empty($props['created']) ) {
				$createDate = '';
			} else {
				$createDate = $context->getLanguage()->userTimeAndDate( $props['created'], $context->getUser() );
			}

			// Get a few versions of the title text
			$titleObj = $article->getTitle();
			$dbKey    = urlencode( $titleObj->getDBKey() );
			$titleURL = $titleObj->getLocalURL();

			// Create some links needed for linking to the file page and undoing swaps
			$titleLink = '<a href="'.$titleURL.'">'.$titleObj->getText().'</a>';
			$undoLink  = $this->wg->Server."/wikia.php?controller=LicensedVideoSwapSpecial&method=restoreVideo&format=json&videoTitle={$dbKey}";
			$undoText  = wfMessage('lvs-undo-swap')->plain();

			// Generate the proper log message and undo link for the swap/keep type
			switch ( $props['status'] ) {
				case self::STATUS_SWAP_NORM:
					$newTitleLink = $newDbKey = '';
					$redirect = $article->getRedirectTarget();
					if ( !empty($redirect) ) {
						$newDbKey = urlencode( $redirect->getDBKey() );
						$redirectURL = $redirect->getLocalURL();
						$newTitleLink = '<a href="'.$redirectURL.'">'.$redirect->getText().'</a>';
					}
					$logMessage = wfMessage( 'lvs-history-swapped', $titleLink, $newTitleLink )->plain();
					$undoLink  .= "&newTitle=".$newDbKey;
					break;

				case self::STATUS_SWAP_EXACT:
					$logMessage = wfMessage( 'lvs-history-swapped-exact', $titleLink )->plain();
					break;

				case self::STATUS_KEEP:
				case self::STATUS_KEEP_FOREVER:
				default:
					$logMessage = wfMessage( 'lvs-history-kept', $titleLink )->plain();
					$undoText   = wfMessage( 'lvs-undo-keep' )->plain();
					break;
			}

			$video = array(
				'pageId'      => $row->page_id,
				'logMessage'  => $logMessage,
				'undoLink'    => $undoLink,
				'undoText'    => $undoText,
				'userName'    => $userName,
				'userLink'    => $userLink,
				'created'     => empty( $props['created'] ) ? '' : $props['created'], // Used for sorting below
				'createDate'  => $createDate,
				'undo'        => $this->wg->Server."/wikia.php?controller=LicensedVideoSwapSpecial&method=restoreVideo&format=json&videoTitle={$dbKey}",
			);

			$videoList[] = $video;
		}

		usort($videoList, function ($a, $b) { return strcmp($b['created'], $a['created']); });
		return $videoList;
	}

	/**
	 * Get historical suggested videos from page status (WPP_LVS_STATUS_INFO)
	 * @param integer $articleId
	 * @return array $suggestedVideos
	 */
	public function getHistoricalSuggestions( $articleId ) {
		$pageStatus = $this->getPageStatusInfo( $articleId );
		$suggestedVideos = empty( $pageStatus['suggested'] ) ? array() : $pageStatus['suggested'];

		return $suggestedVideos;
	}

	/**
	 * Get valid videos - list of video title
	 * @param array $videos
	 * @return array $validVideos
	 */
	public function getValidVideos( $videos ) {
		$validVideos = array();
		foreach ( $videos as $videoTitle ) {
			$file = $this->getVideoFile( urldecode( $videoTitle ) );
			if ( !empty( $file ) ) {
				$validVideos[] = $file->getTitle()->getDBKey();
			}
		}

		return $validVideos;
	}

}
