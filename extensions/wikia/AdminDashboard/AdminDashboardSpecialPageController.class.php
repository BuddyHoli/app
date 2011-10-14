<?php

/**
 * Admin Dashboard Special Page
 * @author Hyun
 * @author Owen
 *
 */
class AdminDashboardSpecialPageController extends WikiaSpecialPageController {

	public function __construct() {
		wfLoadExtensionMessages('AdminDashboard');
		parent::__construct('AdminDashboard', '', false);
	}

	/**
	 * @brief Displays the main menu for the admin dashboard
	 * 
	 */
	public function index() {
		$this->wg->Out->setPageTitle(wfMsg('admindashboard-title'));
		if (!$this->wg->User->isAllowed( 'admindashboard' )) {
			$this->displayRestrictionError();
			return false;  // skip rendering
		}
		$this->tab = $this->getVal('tab', 'general');
		
		// links
		$this->urlThemeDesigner = Title::newFromText('ThemeDesigner', NS_SPECIAL)->getFullURL();
		$this->urlRecentChanges = Title::newFromText('RecentChanges', NS_SPECIAL)->getFullURL();
		$this->urlTopNavigation = Title::newFromText('Wiki-navigation', NS_MEDIAWIKI)->getFullURL('action=edit');
		$this->urlWikiFeatures = Title::newFromText('WikiFeatures', NS_SPECIAL)->getFullURL();
		$this->urlPageLayoutBuilder = Title::newFromText('PageLayoutBuilder', NS_SPECIAL)->getFullURL('action=list');
		
		$this->urlListUsers = Title::newFromText('ListUsers', NS_SPECIAL)->getFullURL();
		$this->urlUserRights = Title::newFromText('UserRights', NS_SPECIAL)->getFullURL();
		
		$this->urlCommunityCorner = Title::newFromText('Community-corner', NS_MEDIAWIKI)->getFullURL('action=edit');
		$this->urlAllCategories = Title::newFromText('Categories', NS_SPECIAL)->getFullURL();
		$this->urlAddPage = Title::newFromText('CreatePage', NS_SPECIAL)->getFullURL();
		$this->urlAddPhoto = Title::newFromText('Upload', NS_SPECIAL)->getFullURL();
		$this->urlCreateBlogPage = Title::newFromText('CreateBlogPage', NS_SPECIAL)->getFullURL();
		$this->urlMultipleUpload = Title::newFromText('MultipleUpload', NS_SPECIAL)->getFullURL();
		
		// special:specialpages
		$this->advancedSection = (string)$this->app->sendRequest( 'AdminDashboardSpecialPage', 'getAdvancedSection', array());
		
		// icon display logic
		$this->displayPageLayoutBuilder = !empty($this->wg->EnablePageLayoutBuilder);
		$this->displayWikiFeatures = !empty($this->wg->EnableWikiFeatures);
	}
	
	/**
	 * @brief Copied and pasted code from wfSpecialSpecialpages() that have been modified and refactored.  Also removes some special pages from list.
	 *
	 */
	public function getAdvancedSection() {
		
		if (!$this->wg->User->isAllowed( 'admindashboard' )) {
			$this->displayRestrictionError();
			return false; // skip rendering
		}

		$this->wg->MessageCache->loadAllMessages();	
		$this->sk = $this->wg->User->getSkin();

		$pages = SpecialPage::getUsablePages();
	
		if( count( $pages ) == 0 ) {
			return;
		}
	
		/** Put them into a sortable array */
		$groups = array();
		foreach ( $pages as $pagename => $page ) {
			if ( !AdminDashboardLogic::isGeneralApp($pagename) && $page->isListed() ) {
				$group = SpecialPage::getGroup( $page );
				if( !isset($groups[$group]) ) {
					$groups[$group] = array();
				}
				$groups[$group][$page->getDescription()] = array( $page->getTitle(), $page->isRestricted() );
			}
		}
	
		/** Sort */
		if ( $this->wg->SortSpecialPages ) {
			foreach( $groups as $group => $sortedPages ) {
				ksort( $groups[$group] );
			}
		}
	
		/** Always move "other" to end */
		if( array_key_exists('other',$groups) ) {
			$other = $groups['other'];
			unset( $groups['other'] );
			$groups['other'] = $other;
		}
		$this->groups = $groups;		
	}
	
	public function chromedArticleHeader() {
		if (!$this->wg->User->isAllowed( 'admindashboard' )) {
			$this->displayRestrictionError();
			return false; // skip rendering
		}
		$page = $this->getVal('page', '');
		if(empty($page)) {
			$headerText = $this->getVal('headerText', '');
		} else {
			$headerText = SpecialPage::getTitleFor($page)->getText();
		}
		$this->headerText = $headerText;
	}
	
	/**
	 * @brief This returns the HTML output of any SpecialPage::execute function
	 * @details
	 * 	SpecialPage::capturePath will skip SpecialPages which are not "includable" 
	 *	(which is all the interesting ones)  So we need to force it.
	 *  
	 * @requestParam string page the name of the Special page to invoke
	 * @responseParam string output the HTML output of the special page
	 */
	
	public function GetSpecialPage () {
		if (!$this->wg->User->isAllowed( 'admindashboard' )) {
			$this->displayRestrictionError();
			return false; // skip rendering
		}

		// Construct title object from request params
		$pageName = $this->getVal("page");
		$title = SpecialPage::getTitleFor($pageName);
		
		// Save global variables and initialize context for special page
		global $wgOut, $wgTitle;
		$oldTitle = $wgTitle;
		$oldOut = $wgOut;
		$wgOut = new OutputPage;
		$wgOut->setTitle( $title );
		$wgTitle = $title;
				
		// Construct special page object
		try {
			$basePages = array("Categories", "Recentchanges", "Specialpages");
			if (in_array($pageName, $basePages)) {
				$sp = SpecialPage::getPage($pageName);
			} else {
				$sp = new $pageName(); 
			}
		} catch (Exception $e) {
			print_pre("Could not construct special page object");
		}
		if ($sp instanceof SpecialPage) {
			$ret = $sp->execute(false);
		} else {
			print_pre("Object is not a special page.");
		}

		// TODO: check retval of special page call?
		
		$this->output = $wgOut->getHTML();
		
		// Restore global variables
		$wgTitle = $oldTitle;
		$wgOut = $oldOut;
		
	}
		
}