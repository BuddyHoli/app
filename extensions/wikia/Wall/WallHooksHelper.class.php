<?php

class WallHooksHelper {
	const RC_WALL_COMMENTS_MAX_LEN = 50;
	const RC_WALL_SECURENAME_PREFIX = 'WallMessage_';
	private $rcWallActionTypes = array('wall_remove', 'wall_restore', 'wall_admindelete');

	public function onBlockIpCompleteWatch($name, $title ) {
		$app = F::App();
		$watchTitle = Title::makeTitle( NS_USER_WALL, $name );
		$app->wg->User->addWatch( $watchTitle );
		return true;
	}

	public function onUserIsBlockedFrom($user, $title, &$blocked, &$allowUsertalk) {

		if ( !$user->mHideName && $allowUsertalk && $title->getNamespace() == NS_USER_WALL_MESSAGE ) {
			$wm =  F::build('WallMessage', array($title), 'newFromTitle');
			if($wm->getWallOwner()->getName() === $user->getName()){
				$blocked = false;
				wfDebug( __METHOD__ . ": self-user wall page, ignoring any blocks\n" );
			}
		}

		return true;
	}

	public function onArticleViewHeader(&$article, &$outputDone, &$useParserCache) {
		$app = F::App();
		$helper = F::build('WallHelper', array());
		$title = $article->getTitle();

		if( $title->getNamespace() === NS_USER_WALL
				&& !$title->isSubpage()
		) {
			//message wall index
			$outputDone = true;
			$action = $app->wg->request->getVal('action');
			$app->wg->Out->addHTML($app->renderView('WallController', 'index', array( 'title' => $article->getTitle() ) ));
		}

		if( $title->getNamespace() === NS_USER_WALL_MESSAGE
				&& intval($title->getText()) > 0
		) {
			//message wall index - brick page
			$outputDone = true;

			$mainTitle = Title::newFromId($title->getText());
			if(empty($mainTitle)) {
				$dbkey = $helper->getDbkeyFromArticleId_forDeleted($title->getText());
				$fromDeleted = true;
			} else {
				$dbkey = $mainTitle->getDBkey();
				$fromDeleted = false;
			}

			if(empty($dbkey)) {
				// try master
				$mainTitle = Title::newFromId($title->getText(), GAID_FOR_UPDATE);
				if(!empty($mainTitle)) {
					$dbkey = $mainTitle->getDBkey();
					$fromDeleted = false;
				}
			}

			if(empty($dbkey) || !$helper->isDbkeyFromWall($dbkey) ) {
				// no dbkey or not from wall, redirect to wall
				$app->wg->Out->redirect($this->getWallTitle()->getFullUrl(), 301);
				return true;
			} else {
				// article exists or existed
				if($fromDeleted) {
					$app->wg->SuppressPageHeader = true;
					$app->wg->Out->addHTML($app->renderView('WallController', 'messageDeleted', array( 'title' =>wfMsg( 'wall-deleted-msg-pagetitle' ) ) ));
					$app->wg->Out->setPageTitle( wfMsg( 'wall-deleted-msg-pagetitle' ) );
					$app->wg->Out->setHTMLTitle( wfMsg( 'errorpagetitle' ) );
				} else {
					$messageTitle = Title::newFromText($dbkey, NS_USER_WALL_MESSAGE );
					$wallMessage = F::build('WallMessage', array($messageTitle), 'newFromTitle' );
					$app->wg->SuppressPageHeader = true;
					$app->wg->WallBrickHeader = $title->getText();
					if( $wallMessage->isVisible($app->wg->User) ||
							($wallMessage->canViewDeletedMessage($app->wg->User) && $app->wg->Request->getVal('show') == '1')
					) {
						$app->wg->Out->addHTML($app->renderView('WallController', 'index',  array('filterid' => $title->getText(),  'title' => $wallMessage->getWallTitle() )));
					} else {
						$app->wg->Out->addHTML($app->renderView('WallController', 'messageDeleted', array( 'title' =>wfMsg( 'wall-deleted-msg-pagetitle' ) ) ));
					}
				}
			}

			return true;
		}

		if( $title->getNamespace() === NS_USER_TALK
				&& !$title->isSubpage()
		) {
			//user talk page -> redirect to message wall
			$outputDone = true;

			$app->wg->request->setVal('dontGetUserFromSession', true);
			$app->wg->Out->redirect($this->getWallTitle()->getFullUrl(), 301);
			return true;
		}

		$parts = explode('/', $title->getText());

		if( $title->getNamespace() === NS_USER_TALK
				&& $title->isSubpage()
				&& !empty($parts[0])
				&& !empty($parts[1])
		) {
			//user talk subpage -> redirects to message wall namespace subpage
			$outputDone = true;

			$title = F::build('Title', array($parts[0].'/'.$parts[1], NS_USER_WALL), 'newFromText');
			$app->wg->Out->redirect($title->getFullUrl(), 301);
			return true;
		}

		if( $title->getNamespace() === NS_USER_WALL
				&& $title->isSubpage()
				&& !empty($app->wg->EnableWallExt)
				&& !empty($parts[1])
				&& mb_strtolower(str_replace(' ', '_', $parts[1])) === mb_strtolower($helper->getArchiveSubPageText())
		) {
			//user talk archive
			$outputDone = true;

			$app->wg->Out->addHTML($app->renderView('WallController', 'renderOldUserTalkPage', array('wallUrl' => $this->getWallTitle()->getFullUrl())));
		} else if( $title->getNamespace() === NS_USER_WALL && $title->isSubpage() ) {
			//message wall subpage (sometimes there are old user talk subpages)
			$outputDone = true;

			$app->wg->Out->addHTML($app->renderView('WallController', 'renderOldUserTalkSubpage', array('subpage' => $parts[1], 'wallUrl' => $this->getWallTitle()->getFullUrl()) ));
			return true;
		}

		return true;
	}

	/**
	 * @brief Hook to change tabs on user wall page
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onSkinTemplateTabs($template, &$contentActions) {
		$app = F::App();

		$app->wg->request->setVal('dontGetUserFromSession', true);

		if( !empty($app->wg->EnableWallExt) ) {
			$helper = F::build('WallHelper', array());
			$title = $app->wg->Title;

			if( $title->getNamespace() === NS_USER ) {
				if( !empty($contentActions['talk']) ) {
					$contentActions['talk']['text'] = $app->wf->Msg('wall-message-wall');

					$userWallTitle = $this->getWallTitle();

					if( $userWallTitle instanceof Title ) {
						$contentActions['talk']['href'] = $userWallTitle->getLocalUrl();
					}
				}
			}

			if( $title->getNamespace() === NS_USER_WALL || $title->getNamespace() === NS_USER_WALL_MESSAGE ) {
				$userPageTitle = $helper->getTitle(NS_USER);
				$contentActionsOld = $contentActions;

				$contentActions = array();

				if($app->wg->User->getName() != $title->getBaseText() && !$title->isSubpage()){
					if(isset($contentActionsOld['watch'])){
						$contentActions['watch'] = $contentActionsOld['watch'];
					}

					if(isset($contentActionsOld['unwatch'])){
						$contentActions['unwatch'] = $contentActionsOld['unwatch'];
					}
				}

				if( $title->getNamespace() === NS_USER_WALL_MESSAGE ) {
					$text = $title->getText();
					$id = intval($text);

					if( $id > 0 ) {
						$wm = F::build('WallMessage', array($id), 'newFromId');
					} else {
						//sometimes (I found it on a revision diff page) $id here isn't a number from (in example) Thread:1234 link
						//it's a text similar to this: AndLuk/@comment-38.127.199.123-20120111182821
						//then we need to use WallMessage constructor method
						$wm = F::build('WallMessage', array($title));
					}

					if( empty($wm) ) {
						//FB#19394
						return true;
					}

					$wall = $wm->getWall();
					$user = $wall->getUser();
				} else {
					$wall = F::build( 'Wall', array($title), 'newFromTitle');
					$user = $wall->getUser();
				}

				if( $user instanceof User ) {
					$contentActions['user-profile'] = array(
							'class' => false,
							'href' => $user->getUserPage()->getFullUrl(),
							'text' => $app->wf->Msg('nstab-user'),
					);
				}

				$contentActions['message-wall'] = array(
						'class' => 'selected',
						'href' => $wall->getUrl(),
						'text' => $app->wf->Msg('wall-message-wall'),
				);

				$contentActions['message-wall-history'] = array(
						'class' => 'selected',
						'href' => $title->getLocalUrl( array('action'=>'history') ),
						'text' => $app->wf->Msg('wall-history'),
				);
			}

			if( $title->getNamespace() === NS_USER_WALL && $title->isSubpage() ) {
				$userTalkPageTitle = $helper->getTitle(NS_USER_TALK);

				$contentActions['view-source'] = array(
						'class' => false,
						'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'edit')),
						'text' => $app->wf->Msg('user-action-menu-view-source'),
				);

				$contentActions['history'] = array(
						'class' => false,
						'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'history')),
						'text' => $app->wf->Msg('user-action-menu-history'),
				);
			}
		}

		return true;
	}

	/**
	 * @brief Redirects any attempts of editing anything in NS_USER_WALL namespace
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onAlternateEdit($editPage) {
		$this->doSelfRedirect();

		return true;
	}

	/**
	 * @brief Redirects any attempts of viewing history of any page in NS_USER_WALL namespace
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */

	public function onBeforePageHistory(&$article, &$wgOut) {
		$title = $article->getTitle();
		$app = F::App();
		$page = $app->wg->Request->getVal('page', 1);

		if( !empty($title) && $title->getNamespace() === NS_USER_WALL  && !$title->isSubpage() ) {
			$app->wg->Out->addHTML($app->renderView('WallHistoryController', 'index', array('title' => $title, 'page' => $page) ));
			return false;
		}

		if( !empty($title) && $title->getNamespace() === NS_USER_WALL_MESSAGE ) {
			$app->wg->Out->addHTML($app->renderView('WallHistoryController', 'index', array('title' => $title, 'page' => $page, 'threadLevelHistory' => true)));
			return false;
		}

		$this->doSelfRedirect();
		return true;
	}

	/**
	 * @brief add history to wall toolbar
	 **/
	function onBeforeToolbarMenu(&$items) {
		$app = F::app();
		$title = $app->wg->Title;
		$action = $app->wg->Request->getText('action');

		if( $title instanceof Title && $title->getNamespace() === NS_USER_WALL_MESSAGE || $title->getNamespace() === NS_USER_WALL  && !$title->isSubpage() && empty($action) ) {
			$item = array(
					'type' => 'html',
					'html' => XML::element('a', array('href' => $title->getFullUrl('action=history')), wfMsg('wall-toolbar-history') )
			);

			if( is_array($items) ) {
				$inserted = false;
				$itemsout = array();

				foreach($items as $value) {
					$itemsout[] = $value;

					if( $value['type'] == 'follow' ) {
						$itemsout[] = $item;
						$inserted = true;
					}
				}

				if( !$inserted ) {
					array_unshift($items, $item);
				} else {
					$items = $itemsout;
				}
			} else {
				$items = array($item);
			}
		}

		return true;
	}



	/**
	 * @brief Redirects any attempts of protecting any page in NS_USER_WALL namespace
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onBeforePageProtect(&$article) {
		$this->doSelfRedirect();

		return true;
	}

	/**
	 * @brief Redirects any attempts of unprotecting any page in NS_USER_WALL namespace
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onBeforePageUnprotect(&$article) {
		$this->doSelfRedirect();

		return true;
	}

	/**
	 * @brief Redirects any attempts of deleting any page in NS_USER_WALL namespace
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onBeforePageDelete(&$article) {
		$this->doSelfRedirect();

		return true;
	}

	/**
	 * @brief Changes "My talk" to "Message wall" in the user links.
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 * @author Piotrek Bablok
	 */
	public function onPersonalUrls(&$personalUrls, &$title) {
		$app = F::App();

		F::build('JSMessages')->enqueuePackage('Wall', JSMessages::EXTERNAL);

		//if( !empty($personalUrls['mytalk']) ) {
		//	unset($personalUrls['mytalk']);
		//}


		if($app->wg->User->isLoggedIn()) {
			$userWallTitle = $this->getWallTitle();
			if( $userWallTitle instanceof Title ) {
				$personalUrls['mytalk']['href'] = $userWallTitle->getLocalUrl();
			}
			$personalUrls['mytalk']['text'] = $app->wf->Msg('wall-message-wall');

			if(!empty($personalUrls['mytalk']['class'])){
				unset($personalUrls['mytalk']['class']);
			}

			if($app->wg->User->getSkin()->getSkinName() == 'monobook') {
				$personalUrls['wall-notifications'] = array(
						'text'=>$app->wf->Msg('wall-notifications'),
						//'text'=>print_r($app->wg->User->getSkin(),1),
						'href'=>'#',
						'class'=>'wall-notifications-monobook',
						'active'=>false
				);
				$app->wg->Out->addScript("<script type=\"{$app->wg->JsMimeType}\" src=\"/skins/common/jquery/jquery.timeago.js?{$app->wg->StyleVersion}\"></script>\n");
				$app->wg->Out->addScript("<link rel=\"stylesheet\" type=\"text/css\" href=\"{$app->wg->ExtensionsPath}/wikia/Wall/css/WallNotificationsMonobook.css?{$app->wg->StyleVersion}\" />\n");
				$app->wg->Out->addScript("<script type=\"{$app->wg->JsMimeType}\" src=\"{$app->wg->ExtensionsPath}/wikia/Wall/js/WallNotifications.js?{$app->wg->StyleVersion}\"></script>\n");
			}
		}

		return true;
	}

	/**
	 * @brief Changes "My talk" to "Message wall" in Oasis (in the tabs on the User page).
	 *
	 * @return true
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onUserPagesHeaderModuleAfterGetTabs(&$tabs, $namespace, $userName) {
		$app = F::App();

		$app->wg->request->setVal('dontGetUserFromSession', true);

		foreach($tabs as $key => $tab) {
			if( !empty($tab['data-id']) && $tab['data-id'] === 'talk' ) {
				$userWallTitle = $this->getWallTitle();

				if( $userWallTitle instanceof Title ) {
					$tabs[$key]['link'] = '<a href="'.$userWallTitle->getLocalUrl().'" title="'.$app->wf->Msg('wall-tab-wall-title', array($userName)).'">'.$app->wf->Msg('wall-message-wall').'</a>';
					$tabs[$key]['data-id'] = 'wall';

					if( $namespace === NS_USER_WALL ) {
						$tabs[$key]['selected'] = true;
					}
				}

				break;
			}
		}

		return true;
	}

	/**
	 * @brief Remove Message Wall:: from back link
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onSkinSubPageSubtitleAfterTitle($title, &$ptext, &$cssClass) {
		if( !empty($title) && $title->getNamespace() == NS_USER_WALL) {
			$ptext = $title->getText();
			$cssClass = 'back-user-wall';
		}

		return true;
	}

	/**
	 * @brief Adds an action button on user talk archive page
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onPageHeaderIndexAfterActionButtonPrepared(&$action, &$dropdown, $ns, $skin) {
		$app = F::App();
		$helper = F::build('WallHelper', array());

		if( !empty($app->wg->EnableWallExt) ) {
			$title = $app->wg->Title;
			$parts = explode( '/', $title->getText() );
			$canEdit = $app->wg->User->isAllowed('editwallarchivedpages');

			if( $title->getNamespace() === NS_USER_WALL
					&& $title->isSubpage()
					&& !empty($parts[1])
					&& mb_strtolower(str_replace(' ', '_', $parts[1])) === mb_strtolower($helper->getArchiveSubPageText())
			) {
				//user talk archive
				$userTalkPageTitle = $helper->getTitle(NS_USER_TALK);

				$action = array(
						'class' => '',
						'text' => $app->wf->Msg('viewsource'),
						'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'edit')),
				);

				$dropdown = array(
						'history' => array(
								'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'history')),
								'text' => $app->wf->Msg('history_short'),
						),
				);

				if( $canEdit ) {
					$action['text'] = $app->wf->Msg('edit');
					$action['id'] = 'talkArchiveEditButton';
				}
			}

			if( $title->getNamespace() === NS_USER_WALL
					&& $title->isSubpage()
					&& !empty($parts[1])
					&& mb_strtolower(str_replace(' ', '_', $parts[1])) !== mb_strtolower($helper->getArchiveSubPageText())
			) {
				//subpage
				$userTalkPageTitle = $helper->getTitle(NS_USER_TALK, $parts[1]);

				$action = array(
						'class' => '',
						'text' => $app->wf->Msg('viewsource'),
						'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'edit')),
				);

				$dropdown = array(
						'history' => array(
								'href' => $userTalkPageTitle->getLocalUrl(array('action' => 'history')),
								'text' => $app->wf->Msg('history_short'),
						),
				);

				if( $canEdit ) {
					$action['text'] = $app->wf->Msg('edit');
					$action['id'] = 'talkArchiveEditButton';
				}
			}
		}

		return true;
	}

	/**
	 * @brief Redirects to current title if it is in NS_USER_WALL namespace
	 *
	 * @return void
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	protected function doSelfRedirect() {
		$app = F::App();
		$title = $app->wg->Title;

		if($app->wg->Request->getVal('action') == 'history' || $app->wg->Request->getVal('action') == 'historysubmit') {
			return true;
		}

		if( $title->getNamespace() === NS_USER_WALL ) {
			$app->wg->Out->redirect($title->getLocalUrl(), 301);
			$app->wg->Out->enableRedirects(false);
		}

		if( $title->getNamespace() === NS_USER_WALL_MESSAGE ) {
			$parts = explode( '/', $title->getText() );

			$title = F::build('Title', array($parts[0], NS_USER_WALL), 'newFromText');
			$app->wg->Out->redirect($title->getFullUrl(), 301);
			$app->wg->Out->enableRedirects(false);
		}
	}

	/**
	 * @brief Returns message wall title if any
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 *
	 * @return Title | null
	 */
	protected function getWallTitle() {
		$helper = F::build('WallHelper', array());
		$app = F::app();

		$userFromSession = !$app->wg->request->getVal('dontGetUserFromSession', false);

		if( $userFromSession ) {
			return $helper->getTitle(NS_USER_WALL, null, $app->wg->User);
		} else {
			return $helper->getTitle(NS_USER_WALL);
		}
	}

	/**
	 *  clean history after delete
	 *
	 **/

	public function onArticleDeleteComplete( &$self, &$user, $reason, $id) {
		$title = $self->getTitle();
		$app = F::app();
		if($title instanceof Title && $title->getNamespace() == NS_USER_WALL_MESSAGE) {
			$wh = F::build('WallHistory', array($app->wg->CityId));
			$wh->remove( $id );
		}
		return true;
	}

	public function onArticleDelete( $article, &$user, &$reason, &$error ){
		$title = $article->getTitle();
		$app = F::app();
		if($title instanceof Title && $title->getNamespace() == NS_USER_WALL_MESSAGE) {
			$wallMessage = F::build('WallMessage', array($title), 'newFromTitle' );
			return $wallMessage->canDelete($user);
		}
		return true;
	}

	public function onRecentChangeSave( $recentChange ){
		wfProfileIn( __METHOD__ );
		// notifications
		$app = F::app();
			
		if($recentChange->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE) {
			$rcType = $recentChange->getAttribute('rc_type');

			//FIXME: WallMessage::remove() creates a new RC but somehow there is no rc_this_oldid
			$revOldId = $recentChange->getAttribute('rc_this_oldid');
			if( $rcType == RC_EDIT && !empty($revOldId) ) {
				$helper = F::build('WallHelper', array());
				$helper->sendNotification($revOldId, $rcType);
			}
		}

		wfProfileOut( __METHOD__ );
		return true;
	}

	public function onArticleCommentBeforeWatchlistAdd($comment) {
		$commentTitle = $comment->getTitle();

		if ($commentTitle instanceof Title && $commentTitle->getNamespace() == NS_USER_WALL_MESSAGE) {
			$parentTitle = $comment->getTopParentObj();

			if (!($comment->mUser instanceof User)) {
				// force load from cache
				$comment->load(true);
			}

			if (!($comment->mUser instanceof User)) {
				// comment in master has no valid User
				// log error
				$logmessage = 'WallHooksHelper.class.php, ' . __METHOD__ . ' ';
				$logmessage .= 'ArticleId: ' . $commentTitle->getArticleID();

				Wikia::log(__METHOD__, false, $logmessage);

				// parse following hooks
				return true;
			}

			if (!empty($parentTitle)) {
				$comment->mUser->addWatch($parentTitle->getTitle());
			} else {
				$comment->mUser->addWatch($comment->getTitle());
			}

			return false;
		}

		return true;
	}


	/**
	 * @brief Allows to edit or not archived talk pages and its subpages
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 *
	 * @return boolean true -- because it's a hook
	 */
	public function onAfterEditPermissionErrors(&$permErrors, $title, $removeArray) {
		$app = F::App();
		$canEdit = $app->wg->User->isAllowed('editwallarchivedpages');

		if( !empty($app->wg->EnableWallExt)
				&& defined('NS_USER_TALK')
				&& $title->getNamespace() == NS_USER_TALK
				&& !$canEdit
		) {
			$permErrors[] = array(
					0 => 'protectedpagetext',
					1 => 'archived'
			);
		}

		return true;
	}

	/**
	 * @brief Just adjusting links and removing history from brick pages (My Tools bar)
	 *
	 * @param array $contentActions passed by reference array with anchors elements
	 *
	 * @return true because this is a hook
	 */
	public function onSkinTemplateContentActions(&$contentActions) {
		$app = F::app();

		if( !empty($app->wg->EnableWallExt) && $app->wg->Title instanceof Title ) {
			$title = $app->wg->Title;
			$parts = explode( '/', $title->getText() );
			$helper = F::build('WallHelper', array());
		}

		if( $title instanceof Title
				&& $title->getNamespace() == NS_USER_WALL
				&& $title->isSubpage() === true
				&& mb_strtolower(str_replace(' ', '_', $parts[1])) !== mb_strtolower($helper->getArchiveSubPageText())
		) {
			//remove "History" and "View source" tabs in Monobook & don't show history in "My Tools" in Oasis
			//because it leads to Message Wall (redirected) and a user could get confused
			if( isset($contentActions['history']['href']) ) {
				//$contentActions['history']['href'] = $this->getWallTitle()->getLocalUrl('action=history');
				unset($contentActions['history']);
			}

			if( isset($contentActions['view-source']['href']) ) {
				//$contentActions['view-source']['href'] = $this->getWallTitle()->getLocalUrl('action=edit');
				unset($contentActions['view-source']);
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method doesn't let display flags for message wall replies (they are displayed only for messages from message wall)
	 *
	 * @param ChangesList $list
	 * @param string $flags
	 * @param RecentChange $rc
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertFlags(&$list, &$flags, $rc) {
		if( $rc->getAttribute('rc_type') == RC_NEW && $rc->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE ) {
			//we don't need flags if this is a reply on a message wall
			$app = F::app();

			$rcTitle = $rc->getTitle();

			if( !($rcTitle instanceof Title) ) {
				//it can be media wiki deletion of an article -- we ignore them
				Wikia::log(__METHOD__, false, "WALL_NOTITLE_FROM_RC " . print_r($rc, true));
				return true;
			}

			$wm = F::build('WallMessage', array($rcTitle));
			$wm->load();

			if( !$wm->isMain() ) {
				$flags = '';
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method shows link to message wall thread page
	 *
	 * @param ChangesList $list
	 * @param string $articleLink
	 * @param string $s
	 * @param RecentChange $rc
	 * @param boolean $unpatrolled
	 * @param boolean $watched
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertArticleLink(&$list, &$articleLink, &$s, &$rc, $unpatrolled, $watched) {
		$rcType = $rc->getAttribute('rc_type');

		if( in_array($rcType, array(RC_NEW, RC_EDIT, RC_LOG)) && $rc->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE ) {
			$app = F::app();

			if( in_array($rc->getAttribute('rc_log_action'), $this->rcWallActionTypes) ) {
				$articleLink = '';

				return true;
			} else {
				$rcTitle = $rc->getTitle();

				if( !($rcTitle instanceof Title) ) {
					//it can be media wiki deletion of an article -- we ignore them
					Wikia::log(__METHOD__, false, "WALL_NOTITLE_FROM_RC " . print_r($rc, true));
					return true;
				}

				$wm = F::build('WallMessage', array($rcTitle));
				$wm->load();

				if( !$wm->isMain() ) {
					$wm = $wm->getTopParentObj();

					if( is_null($wm) ) {
						Wikia::log(__METHOD__, false, "WALL_NO_PARENT_MSG_OBJECT " . print_r($rc, true));
						return true;
					} else {
						$wm->load();
					}
				}

				$link = $wm->getMessagePageUrl();
				$title = $wm->getMetaTitle();
				$wallUrl = $wm->getWallPageUrl();
				$wallOwner = $wm->getWallOwnerName();
				$class = '';

				$articleLink = ' <a href="'.$link.'" class="'.$class.'" >'.$title.'</a> '.$app->wf->Msg('wall-recentchanges-article-link-new-message', array($wallUrl, $wallOwner));
				# Bolden pages watched by this user
				if( $watched ) {
					$articleLink = '<strong class="mw-watched">'.$articleLink.'</strong>';
				}
			}

			# RTL/LTR marker
			$articleLink .= $app->wg->ContLang->getDirMark();
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method doesn't let display diff history links
	 *
	 * @param ChangesList $list
	 * @param string $articleLink
	 * @param string $s
	 * @param RecentChange $rc
	 * @param boolean $unpatrolled
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertDiffHist(&$list, &$diffLink, &$histLink, &$s, &$rc, $unpatrolled) {
		if( intval($rc->getAttribute('rc_namespace')) === NS_USER_WALL_MESSAGE ) {
			$app = F::app();
			$rcTitle = $rc->getTitle();

			if( !($rcTitle instanceof Title) ) {
				//it can be media wiki deletion of an article -- we ignore them
				Wikia::log(__METHOD__, false, "WALL_NOTITLE_FOR_DIFF_HIST " . print_r(array($rc, $row), true));
				return true;
			}

			if( in_array($rc->getAttribute('rc_log_action'), $this->rcWallActionTypes) ) {
				//delete, remove, restore
				$parts = explode('/@', $rcTitle->getText());
				$isThread = ( count($parts) === 2 ) ? true : false;

				if( $isThread ) {
					$wallTitleObj = F::build('Title', array($parts[0], NS_USER_WALL), 'newFromText');
					$historyLink = ( !empty($parts[0]) && $wallTitleObj instanceof Title) ? $wallTitleObj->getFullURL(array('action' => 'history')) : '#';
					$historyLink = Xml::element('a', array('href' => $historyLink), $app->wf->Msg('wall-recentchanges-wall-history-link'));
				} else {
					$wallMessage = F::build('WallMessage', array($rcTitle));
					$historyLink = $wallMessage->getMessagePageUrl(true).'?action=history';
					$historyLink = Xml::element('a', array('href' => $historyLink), $app->wf->Msg('wall-recentchanges-thread-history-link'));
				}

				$s = '(' . $historyLink . ')';
			} else {
				//new, edit
				if( $rc->mAttribs['rc_type'] == RC_NEW || $rc->mAttribs['rc_type'] == RC_LOG ) {
					$diffLink = $app->wf->Msg('diff');
				} else if( !ChangesList::userCan($rc, Revision::DELETED_TEXT) ) {
					$diffLink = $app->wf->Msg('diff');
				} else {
					$query = array(
							'curid' => $rc->mAttribs['rc_cur_id'],
							'diff'  => $rc->mAttribs['rc_this_oldid'],
							'oldid' => $rc->mAttribs['rc_last_oldid']
					);

					if( $unpatrolled ) {
						$query['rcid'] = $rc->mAttribs['rc_id'];
					}

					$diffLink = Xml::element('a', array(
							'href' => $rcTitle->getLocalUrl($query),
							'tabindex' => $rc->counter,
							'class' => 'known noclasses',
					), $app->wf->Msg('diff'));
				}

				$wallMessage = F::build('WallMessage', array($rcTitle));
				$historyLink = $wallMessage->getMessagePageUrl(true).'?action=history';
				$historyLink = Xml::element('a', array('href' => $historyLink), $app->wf->Msg('hist'));
				$s = '('. $diffLink . $app->wf->Msg('pipe-separator') . $historyLink . ') . . ';
			}

		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method doesn't let display rollback link for message wall inputs
	 *
	 * @param ChangesList $list
	 * @param string $s
	 * @param string $rollbackLink
	 * @param RecentChange $rc
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertRollback(&$list, &$s, &$rollbackLink, $rc) {
		if( !empty($rc->mAttribs['rc_namespace']) && $rc->mAttribs['rc_namespace'] == NS_USER_WALL_MESSAGE ) {
			$rollbackLink = '';
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method creates comment to a recent change line
	 *
	 * @param ChangesList $list
	 * @param string $comment
	 * @param string $s
	 * @param RecentChange $rc
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertComment($list, &$comment, &$s, &$rc) {
		$rcType = $rc->getAttribute('rc_type');

		if( in_array($rcType, array(RC_NEW, RC_EDIT, RC_LOG)) && $rc->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE ) {
			$app = F::app();

			if( $rcType == RC_EDIT ) {
				$comment = ' ';
				$comment .= Xml::element('span', array('class' => 'comment'), $app->wf->Msg('wall-recentchanges-edit'));
			} else if( $rcType == RC_LOG && in_array($rc->getAttribute('rc_log_action'), $this->rcWallActionTypes) ) {
				//this will be deletion/removal/restore summary
				$text = $rc->getAttribute('rc_comment');
				if( !empty($text) ) $comment = Xml::element('span', array('class' => 'comment'), ' ('.$text.')');
				else $comment = '';
			} else {
				$comment = '';
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method creates comment about revision deletion of a message on message wall
	 *
	 * @param ChangesList $list
	 * @param string $actionText
	 * @param string $s
	 * @param RecentChange $rc
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onChangesListInsertAction($list, &$actionText, &$s, &$rc) {
		if( $rc->getAttribute('rc_type') == RC_LOG
				&& $rc->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE
				&& in_array($rc->getAttribute('rc_log_action'), $this->rcWallActionTypes) ) {
			$app = F::app();
			$actionText = '';
			$wfMsgOpts = $this->getMessageOptions($rc);

			$msgType = ($wfMsgOpts['isThread']) ? 'thread' : 'reply';

			//created in WallHooksHelper::getMessageOptions()
			//and there is not needed to be passed to wfMsg()
			unset($wfMsgOpts['isThread'], $wfMsgOpts['isNew']);

			switch($rc->getAttribute('rc_log_action')) {
				case 'wall_remove':
					$actionText = wfMsgExt('wall-recentchanges-wall-removed-'.$msgType, array('parseinline'), $wfMsgOpts);
					break;
				case 'wall_restore':
					$actionText = wfMsgExt('wall-recentchanges-wall-restored-'.$msgType, array('parseinline'), $wfMsgOpts);
					break;
				case 'wall_admindelete':
					$actionText = wfMsgExt('wall-recentchanges-wall-deleted-'.$msgType, array('parseinline'), $wfMsgOpts);
					break;
				default:
					$actionText = wfMsg('wall-recentchanges-wall-unrecognized-log-action', $wfMsgOpts);
				break;
			}
		}

		$actionText = ' '.$actionText;

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method clears or leaves as it was the text which is being send as a content of <li /> elements in RC page
	 *
	 * @param ChangesList $list
	 * @param string $s
	 * @param RecentChange $rc
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onOldChangesListRecentChangesLine(&$changelist, &$s, $rc) {
		if( $rc->getAttribute('rc_namespace') == NS_USER_WALL_MESSAGE ) {
			$app = F::app();
			$rcTitle = $rc->getTitle();

			if( !($rcTitle instanceof Title) ) {
				//it can be media wiki deletion of an article -- we ignore them
				Wikia::log(__METHOD__, false, "WALL_NOTITLE_FROM_RC " . print_r($rc, true));
				return true;
			}

			$wm = F::build('WallMessage', array($rcTitle));
			$wm->load();
			if( !$wm->isMain() ) {
				$wm = $wm->getTopParentObj();

				if( is_null($wm) ) {
					Wikia::log(__METHOD__, false, "WALL_NO_PARENT_MSG_OBJECT " . print_r($rc, true));
					return true;
				} else {
					$wm->load();
				}
			}

			if( $wm->isAdminDelete() && $rc->getAttribute('rc_log_action') != 'wall_admindelete' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @brief Getting the title of a message
	 *
	 * @desc Callback method used in WallHooksHelper::onChangesListInsertAction() hook if deleted message was a reply
	 *
	 * @param string $title
	 *
	 * @return string
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	private function getParentTitleTxt($title) {
		if( $title instanceof Title ) {
			$app = F::app();
			$helper = F::build('WallHelper', array());

			$wm = F::build('WallMessage', array($title));
			$titleText = $title->getText();
			$parentTitleTxt = $wm->getTopParentText($titleText);
			if( is_null($parentTitleTxt) ) {
				$parts = explode('/@', $titleText);
				if( count($parts) > 1 ) {
					$parentTitleTxt = $parts[0] . '/@' . $parts[1];
				}
			}

			$articleData = array('text_id' => '');
			$articleId = $helper->getArticleId_forDeleted($parentTitleTxt, $articleData);
			if( !empty($articleId) ) {
				//parent article was deleted as well
				$articleTitleTxt = $helper->getTitleTxtFromMetadata($helper->getDeletedArticleTitleTxt($articleData['text_id']));
			} else {
				$title = F::build('Title', array($parentTitleTxt, NS_USER_WALL_MESSAGE), 'newFromText');

				if( $title instanceof Title ) {
					$parentWallMsg = F::build('WallMessage', array($title));
					$parentWallMsg->load(true);
					$articleTitleTxt = $parentWallMsg->getMetaTitle();
				} else {
					$articleTitleTxt = $app->wf->Msg('wall-recentchanges-deleted-reply-title');
				}
			}
			$articleTitleTxt = empty($articleTitleTxt) ? $app->wf->Msg('wall-recentchanges-deleted-reply-title') : $articleTitleTxt;

			return $articleTitleTxt;
		}

		return $app->wf->Msg('wall-recentchanges-deleted-reply-title');
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method decides rather put a log information about deletion or not
	 *
	 * @param Article $article a referance to Article instance
	 * @param LogPage $logPage a referance to LogPage instance
	 * @param string $logType a referance to string with type of log
	 * @param Title $title
	 * @param string $reason
	 * @param boolean $hookAddedLogEntry set it to true if you don't want Article::doDeleteArticle() to add a log entry
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onArticleDoDeleteArticleBeforeLogEntry(&$article, &$logPage, &$logType, $title, $reason, &$hookAddedLogEntry) {
		if( $title instanceof Title && $title->getNamespace() == NS_USER_WALL_MESSAGE ) {
			$app = F::app();
			$wm = F::build('WallMessage', array($title));
			$parentObj = $wm->getTopParentObj();
			$reason = ''; //we don't want any comment

			if( empty($parentObj) ) {
				//thread message
				$logPage->addEntry( 'delete', $title, $reason, array() );
			} else {
				//reply
				$result = $parentObj->load(true);

				if( $result ) {
					//if its parent still exists only this reply is being deleted, so log about it
					$logPage->addEntry( 'delete', $title, $reason, array() );
				}
			}

			$hookAddedLogEntry = true;
		}

		return true;
	}

	/**
	 * @brief Adjusting recent changes for Wall
	 *
	 * @desc This method decides rather put a log information about restored article or not
	 *
	 * @param PageArchive $pageArchive a referance to Article instance
	 * @param LogPage $logPage a referance to LogPage instance
	 * @param Title $title a referance to Title instance
	 * @param string $reason
	 * @param boolean $hookAddedLogEntry set it to true if you don't want Article::doDeleteArticle() to add a log entry
	 *
	 * @return true because this is a hook
	 *
	 * @author Andrzej 'nAndy' Lukaszewski
	 */
	public function onPageArchiveUndeleteBeforeLogEntry(&$pageArchive, &$logPage, &$title, $reason, &$hookAddedLogEntry) {
		if( $title instanceof Title && $title->getNamespace() == NS_USER_WALL_MESSAGE ) {
			$app = F::app();
			$wm = F::build('WallMessage', array($title));
			$parentObj = $wm->getTopParentObj();
			$reason = ''; //we don't want any comment

			if( empty($parentObj) ) {
				//thread message
				$logPage->addEntry( 'restore', $title, $reason, array() );
			} else {
				//reply
				$result = $parentObj->load(true);

				if( $result ) {
					//if its parent still exists only this reply is being restored, so log about it
					$logPage->addEntry( 'restore', $title, $reason, array() );
				}
			}

			$hookAddedLogEntry = true;
		}

		return true;
	}

	/**
	 * @brief Adjusting select box with namespaces on RecentChanges page
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onXmlNamespaceSelectorAfterGetFormattedNamespaces(&$namespaces, $selected, $all, $element_name, $label) {
		if( defined('NS_USER_WALL') && defined('NS_USER_WALL_MESSAGE') ) {
			if( isset($namespaces[NS_USER_WALL]) && isset($namespaces[NS_USER_WALL_MESSAGE]) ) {
				unset($namespaces[NS_USER_WALL], $namespaces[NS_USER_WALL_MESSAGE]);
				$namespaces[NS_USER_WALL_MESSAGE] = F::app()->wf->Msg('wall-recentchanges-namespace-selector-message-wall');
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting title of a block group on RecentChanges page
	 *
	 * @param ChangeList $oChangeList
	 * @param string $header
	 * @param array $oRCCacheEntryArray
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onChangesListHeaderBlockGroup(&$oChangeList, &$header, Array /*of oRCCacheEntry*/ &$oRCCacheEntryArray) {
		$oRCCacheEntry = null;

		if ( !empty($oRCCacheEntryArray) ) {
			$oRCCacheEntry = $oRCCacheEntryArray[0];
			$oTitle = $oRCCacheEntry->getTitle();
			$namespace = intval( $oRCCacheEntry->getAttribute('rc_namespace') );

			if( $oTitle instanceof Title && $namespace === NS_USER_WALL_MESSAGE ) {
				$app = F::app();

				$wm = F::build('WallMessage', array($oTitle));
				$wallOwnerObj = $wm->getWallOwner();
				$wallMsgUrl = $wm->getMessagePageUrl();
				$wallUrl = $wm->getWallUrl();
				$wallOwnerName = $wm->getWallOwnerName();
				$parent = $wm->getTopParentObj();
				$isMain = is_null($parent);

				if( !$isMain ) {
					$wm = $parent;
					unset($parent);
				}

				$wm->load();
				$wallMsgTitle = $wm->getMetaTitle();

				if( !is_null($oRCCacheEntry) ) {
					$cnt = count($oRCCacheEntryArray);
					$cntChanges = wfMsgExt( 'nchanges', array( 'parsemag', 'escape' ), $app->wg->Lang->formatNum( $cnt ) );

					$userlinks = array();
					foreach( $oRCCacheEntryArray as $id => $oRCCacheEntry ) {
						$u = $oRCCacheEntry->userlink;
						if( !isset($userlinks[$u]) ) {
							$userlinks[$u] = 0;
						}
						$userlinks[$u]++;
					}

					$users = array();
					foreach( $userlinks as $userlink => $count) {
						$text = $userlink;
						$text .= $app->wg->ContLang->getDirMark();
						if( $count > 1 ) {
							$text .= ' (' . $app->wg->Lang->formatNum($count) . '×)';
						}
						array_push($users, $text);
					}

					$vars = array (
							'cntChanges'	=> $cntChanges,
							'hdrtitle'		=> wfMsg('wall-recentchanges-wall-group', array(Xml::element('a', array('href' => $wallMsgUrl), $wallMsgTitle), $wallUrl, $wallOwnerName)),
							'inx'			=> $oChangeList->rcCacheIndex,
							'users'			=> $users
					);

					$header = wfRenderPartial('Wall', 'renderRCHeaderBlock', $vars);
				}
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting blocks on Enhanced Recent Changes page
	 *
	 * @desc Changes $secureName which is an array key in RC cache by which blocks on enchance RC page are displayed
	 *
	 * @param ChangeList $changesList
	 * @param string $secureName
	 * @param RecentChange $rc
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onChangesListMakeSecureName(&$changesList, &$secureName, &$rc) {
		if( intval($rc->getAttribute('rc_namespace')) === NS_USER_WALL_MESSAGE ) {
			$oTitle = $rc->getTitle();

			if( $oTitle instanceof Title ) {
				$wm = F::build('WallMessage', array($oTitle));
				$parent = $wm->getTopParentObj();
				$isMain = is_null($parent);

				if( !$isMain ) {
					$wm = $parent;
					unset($parent);
				}

				$secureName = self::RC_WALL_SECURENAME_PREFIX.$wm->getArticleId();
			}
		}

		return true;
	}

	/**
	 * @brief Changing all links to Message Wall to blue links
	 *
	 * @param Title $title
	 * @param boolean $result
	 *
	 * @return true -- because it's a hook
	 *
	 * @author Andrzej 'nAndy' Łukaszewski
	 */
	public function onLinkBegin($skin, $target, &$text, &$customAttribs, &$query, &$options, &$ret) {
		// paranoia
		if( !($target instanceof Title) ) {
			return true;
		}

		$namespace = $target->getNamespace();
		if( !empty(F::app()->wg->EnableWallExt) && ($namespace == NS_USER_WALL || $namespace == NS_USER_WALL_MESSAGE) ) {
			// remove "broken" assumption/override
			$brokenKey = array_search('broken', $options);
			if ( $brokenKey !== false ) {
				unset($options[$brokenKey]);
			}

			// make the link "blue"
			$options[] = 'known';
		}

		return true;
	}

	/**
	 * getUserPermissionsErrors -  control access to articles in the namespace NS_USER_WALL_MESSAGE_GREETING
	 *
	 * @author Tomek Odrobny
	 *
	 * @access public
	 */
	public function onGetUserPermissionsErrors( &$title, &$user, $action, &$result ) {

		if( $title->getNamespace() == NS_USER_WALL_MESSAGE_GREETING ) {
			$result = array();

			$parts = explode('/', $title->getText());
			$username = empty($parts[0]) ? '':$parts[0];

			if( $user->isAllowed('walledit') || $user->getName() == $username ) {
				$result = null;
				return true;
			} else {
				$result = array('badaccess-group0');
				return false;
			}
		}
		$result = null;
		return true;
	}


	public function onComposeCommonBodyMail($title, &$keys, &$body, $editor) {
		return true;
	}

	public function onArticleSaveComplete(&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId) {
		$app = F::app();
		$title = $article->getTitle();

		if( !empty($app->wg->EnableWallExt)
				&& $title instanceof Title
				&& $title->getNamespace() === NS_USER_TALK
				&& !$title->isSubpage() )
		{
			//user talk page was edited -> redirect to user talk archive
			$helper = F::build('WallHelper', array());

			$app->wg->request->setVal('dontGetUserFromSession', true);
			$app->wg->Out->redirect($this->getWallTitle()->getFullUrl().'/'.$helper->getArchiveSubPageText(), 301);
			$app->wg->Out->enableRedirects(false);
		}

		return true;
	}

	public function onAllowNotifyOnPageChange( $editor, $title ) {
		if($title->getNamespace() == NS_USER_WALL  || $title->getNamespace() == NS_USER_WALL_MESSAGE || $title->getNamespace() == NS_USER_WALL_MESSAGE_GREETING){
			return false;
		}
		return true;
	}

	public function onWatchArticle(&$user, &$article) {
		$app = F::app();
		$title = $article->getTitle();

		if( !empty($app->wg->EnableWallExt) && $this->isWallMainPage($title) ) {
			$this->processActionOnWatchlist($user, $title->getText(), 'add');
		}

		return true;
	}

	public function onUnwatchArticle(&$user, &$article) {
		$app = F::app();
		$title = $article->getTitle();

		if( !empty($app->wg->EnableWallExt) && $this->isWallMainPage($title) ) {
			$this->processActionOnWatchlist($user, $title->getText(), 'remove');
		}

		return true;
	}

	private function isWallMainPage($title) {
		if( $title->getNamespace() == NS_USER_WALL && strpos($title->getText(), '/') === false ) {
			return true;
		}

		return false;
	}

	private function processActionOnWatchlist($user, $followedUserName, $action) {
		$watchTitle = Title::newFromText($followedUserName, NS_USER);

		if( $watchTitle instanceof Title ) {
			$wl = new WatchedItem;
			$wl->mTitle = $watchTitle;
			$wl->id = $user->getId();
			$wl->ns = $watchTitle->getNamespace();
			$wl->ti = $watchTitle->getDBkey();

			if( $action === 'add' ) {
				$wl->addWatch();
			} elseif( $action === 'remove' ) {
				$wl->removeWatch();
			}
		} else {
			//just-in-case -- it shouldn't happen but if it does we want to know about it
			Wikia::log( __METHOD__, false, 'WALL_HOOK_ERROR: No title instance while syncing follows. User name: '.$followedUserName);
		}
	}

	public function onGetPreferences( $user, &$preferences ) {
		$app = F::app();

		if( $user->isLoggedIn() ) {
			if ($app->wg->EnableUserPreferencesV2Ext) {
				$message = 'wallshowsource-toggle-v2';
				$section = 'under-the-hood/advanced-displayv2';
			}
			else {
				$message = 'wallshowsource-toggle';
				$section = 'misc/wall';
			}
			$preferences['wallshowsource'] = array(
					'type' => 'toggle',
					'label-message' => $message, // a system message
					'section' => $section
			);

			if($user->isAllowed('walldelete')) {
				$preferences['walldelete'] = array(
						'type' => 'toggle',
						'label-message' => 'walldelete-toggle', // a system message
						'section' => $section
				);
			}
		}

		return true;
	}

	/**
	 * @brief Adjusting Special:Contributions
	 *
	 * @param ContribsPager $contribsPager
	 * @param String $ret string passed to wgOutput
	 * @param Object $row Std Object with values from database table
	 *
	 * @return true
	 */
	public function onContributionsLineEnding(&$contribsPager, &$ret, $row) {
		if( isset($row->page_namespace) && intval($row->page_namespace) === NS_USER_WALL_MESSAGE ) {
			$app = F::app();
			$topmarktext = '';

			$rev = new Revision($row);
			$page = $rev->getTitle();
			$page->resetArticleId($row->rev_page);
			$skin = $app->wg->User->getSkin();

			$wfMsgOpts = $this->getMessageOptions(null, $row);

			$isThread = $wfMsgOpts['isThread'];
			$isNew = $wfMsgOpts['isNew'];

			//created in WallHooksHelper::getMessageOptions()
			//and there is not needed to be passed to wfMsg()
			unset($wfMsgOpts['isThread'], $wfMsgOpts['isNew']);

			$wfMsgOpts[4] = Xml::element('a', array('href' => $wfMsgOpts[0]), $app->wg->Lang->timeanddate( $app->wf->Timestamp(TS_MW, $row->rev_timestamp), true) );

			if( $isNew ) {
				$wfMsgOpts[5] = $app->wf->Msg('diff');
			} else {
				$query = array(
						'diff' => 'prev',
						'oldid' => $row->rev_id,
				);

				$wfMsgOpts[5] = Xml::element('a', array(
						'href' => $rev->getTitle()->getLocalUrl($query),
				), $app->wf->Msg('diff'));
			}

			$wallMessage = F::build('WallMessage', array($page));
			$historyLink = $wallMessage->getMessagePageUrl(true).'?action=history';
			$wfMsgOpts[6] = Xml::element('a', array('href' => $historyLink), $app->wf->Msg('hist'));

			if( $isThread && $isNew ) {
				$wfMsgOpts[7] = Xml::element('strong', array(), 'N ');
			} else {
				$wfMsgOpts[7] = '';
			}

			// Don't show useless link to people who cannot hide revisions
			$canHide = $app->wg->User->isAllowed('deleterevision');
			if( $canHide || ($rev->getVisibility() && $app->wg->User->isAllowed('deletedhistory')) ) {
				if( !$rev->userCan(Revision::DELETED_RESTRICTED) ) {
					$del = $skin->revDeleteLinkDisabled($canHide); // revision was hidden from sysops
				} else {
					$query = array(
							'type'		=> 'revision',
							'target'	=> $page->getPrefixedDbkey(),
							'ids'		=> $rev->getId()
					);
					$del = $skin->revDeleteLink($query, $rev->isDeleted(Revision::DELETED_RESTRICTED), $canHide);
				}
				$del .= ' ';
			} else {
				$del = '';
			}

			$ret = $del;
			$ret .= $app->wf->Msg('wall-contributions-wall-line', $wfMsgOpts);

			if( !$isNew ) {
				$ret .= ' ' . Xml::openElement('span', array('class' => 'comment')) . $app->wf->Msg('wall-recentchanges-edit') . Xml::closeElement('span');
			}
		}

		return true;
	}

	/**
	 * @brief Collects data basing on RC object or std object
	 * @desc Those lines of code were used a lot in this class. Better keep them in one place.
	 *
	 * @param RecentChanges $rc
	 * @param Object $row
	 * @param Title $objTitle
	 *
	 * @return Array
	 */
	private function getMessageOptions($rc = null, $row = null) {
		$helper = F::build('WallHelper', array());

		if( !is_null($rc) ) {
			$actionUser = $rc->getAttribute('rc_user_text');
		} else {
			$actionUser = '';
		}

		if( is_object($row) ) {
			$objTitle = F::build('Title', array($row->page_title, $row->page_namespace), 'newFromText');
			$userText = !empty($row->rev_user_text) ? $row->rev_user_text : '';

			$isNew = (!empty($row->page_is_new) && $row->page_is_new === '1') ? true : false;

			if( !$isNew ) {
				$isNew = (isset($row->rev_parent_id) && $row->rev_parent_id === '0') ? true : false;
			}
		} else {
			$objTitle = $rc->getTitle();
			$userText = $rc->getAttribute('rc_user_text');
			$isNew = false; //it doesn't metter for rc -- we've got there rc_log_action
		}

		$wallTitleObj = F::build('Title', array($userText, NS_USER_WALL), 'newFromText');
		$wallUrl = ($wallTitleObj instanceof Title) ? $wallTitleObj->getLocalUrl() : '#';

		if( !($objTitle instanceof Title) ) {
			//it can be media wiki deletion of an article -- we ignore them
			Wikia::log(__METHOD__, false, "WALL_NOTITLE_FOR_MSG_OPTS " . print_r(array($rc, $row), true));
			return true;
		}

		$parts = explode('/@', $objTitle->getText());
		$isThread = ( count($parts) === 2 ) ? true : false;

		$app = F::app();
		$articleTitleTxt = $this->getParentTitleTxt($objTitle);
		$wm = F::build('WallMessage', array($objTitle));
		$articleId = $wm->getId();
		$wallMsgNamespace = $app->wg->Lang->getNsText(NS_USER_WALL_MESSAGE);
		$articleUrl = !empty($articleId) ? $wallMsgNamespace.':'.$articleId : '#';
		$wallOwnerName = $wm->getWallOwnerName();
		$userText = empty($wallOwnerName) ? $userText : $wallOwnerName;
		$wallNamespace = $app->wg->Lang->getNsText(NS_USER_WALL);
		$wallUrl = $wallNamespace.':'.$userText;

		return array(
			$articleUrl,
			$articleTitleTxt,
			$wallUrl,
			$userText,
			$actionUser,
			'isThread' => $isThread,
			'isNew' => $isNew,
		);
	}

	/**
	 * @brief Adjusting Special:Whatlinkshere
	 *
	 * @param Object $row
	 * @param Integer $level
	 * @param Boolean $defaultRendering
	 *
	 * @return Boolean
	 */
	public function onRenderWhatLinksHereRow(&$row, &$level, &$defaultRendering) {
		if( isset($row->page_namespace) && intval($row->page_namespace) === NS_USER_WALL_MESSAGE ) {
			$defaultRendering = false;
			$title = F::build('Title', array($row->page_title, $row->page_namespace), 'newFromText');

			$app = F::app();
			$wlhTitle = SpecialPage::getTitleFor( 'Whatlinkshere' );
			$wfMsgOpts = $this->getMessageOptions(null, $row);
			$app->wg->Out->addHtml(
					Xml::openElement('li') .
					$app->wf->Msg('wall-whatlinkshere-wall-line', $wfMsgOpts) .
					' (' .
					Xml::element('a', array(
							'href' => $wlhTitle->getFullUrl(array('target' => $title->getPrefixedText())),
					), $app->wf->Msg('whatlinkshere-links') ) .
					')' .
					Xml::closeElement('li')
			);
		}

		return true;
	}

	/**
	 * @desc Changes fields in a DifferenceEngine instance to display correct content in <title /> tag
	 *
	 * @param DifferenceEngine $differenceEngine
	 * @param Revivion $oldRev
	 * @param Revivion $newRev
	 *
	 * @return true
	 */
	public function onDiffViewHeader($differenceEngine, $oldRev, $newRev) {
		$app = F::App();
		$diff = $app->wg->request->getVal('diff', false);
		$oldId = $app->wg->request->getVal('oldid', false);

		if( $app->wg->Title instanceof Title && $app->wg->Title->getNamespace() === NS_USER_WALL_MESSAGE ) {
			$metaTitle = $this->getMetatitleFromTitleObject($app->wg->Title);
			$differenceEngine->mOldPage->mPrefixedText = $metaTitle;
			$differenceEngine->mNewPage->mPrefixedText = $metaTitle;
		}

		return true;
	}

	/**
	 * @desc Changes fields in a PageHeaderModule instance to display correct content in <h1 /> and <h2 /> tags
	 *
	 * @param PageHeaderModule $pageHeaderModule
	 * @param int $ns
	 * @param Boolean $isPreview
	 * @param Boolean $isShowChanges
	 * @param Boolean $isDiff
	 * @param Boolean $isEdit
	 * @param Boolean $isHistory
	 *
	 * @return true
	 */
	public function onPageHeaderEditPage($pageHeaderModule, $ns, $isPreview, $isShowChanges, $isDiff, $isEdit, $isHistory) {
		if( $ns === NS_USER_WALL_MESSAGE && $isDiff ) {
			$app = F::App();
			$wmRef = '';
			$pageHeaderModule->title = $this->getMetatitleFromTitleObject($app->wg->Title, $wmRef);
			$pageHeaderModule->subtitle = Xml::element('a', array('href' => $wmRef->getMessagePageUrl()), $app->wf->Msg('oasis-page-header-back-to-article'));
		}

		return true;
	}

	/**
	 * @desc Helper method which gets meta title from an WallMessage instance; used in WallHooksHelper::onDiffViewHeader() and WallHooksHelper::onPageHeaderEditPage()
	 * @param Title $title
	 * @param mixed $wmRef a variable which value will be created WallMessage instance
	 *
	 * @return String
	 */
	private function getMetatitleFromTitleObject($title, &$wmRef = null) {
		$wm = F::build('WallMessage', array($title));

		if( $wm instanceof WallMessage ) {
			$wm->load();
			$metaTitle = $wm->getMetaTitle();
			if( empty($metaTitle) ) {
			//if wall message is a reply
				$wmParent = $wm->getTopParentObj();
				if( $wmParent instanceof WallMessage ) {
					$wmParent->load();
					if( !is_null($wmRef) ) {
						$wmRef = $wmParent;
					}

					return $wmParent->getMetaTitle();
				}
			}

			if( !is_null($wmRef) ) {
				$wmRef = $wm;
			}

			return $metaTitle;
		}

		return '';
	}

	/**
	 * @desc Changes link from User_talk: page to Message_wall: page of the user
	 *
	 * @param int $id id of user who's contributions page is displayed
	 * @param Title $nt instance of Title object of the page
	 * @param Array $tools a reference to an array with links in the header of Special:Contributions page
	 *
	 * @return true
	 */
	public function onContributionsToolLinks($id, $nt, &$tools) {
		$app = F::app();

		if( !empty($app->wg->EnableWallExt) && !empty($tools[0]) && $nt instanceof Title ) {
			//tools[0] is the first link in subheading of Special:Contributions which is "User talk" page
			$wallTitle = F::build('Title', array($nt->getText(), NS_USER_WALL), 'newFromText');

			if( $wallTitle instanceof Title ) {
				$tools[0] = Xml::element('a', array(
						'href' => $wallTitle->getFullUrl(),
						'title' => $wallTitle->getPrefixedText(),
				), $app->wf->Msg('wall-message-wall-shorten'));
			}
		}

		return true;
	}

}

