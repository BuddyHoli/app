<?php

class CommunityPageSpecialHelpModel {
	public function getData() {
		return [
			'title' => wfMessage( 'communitypage-help-module-title' )->plain(),
			'editPage' => wfMessage( 'communitypage-help-edit-page' )->plain(),
			'addLinks' => wfMessage( 'communitypage-help-add-link' )->plain(),
			'addNewPage' => wfMessage( 'communitypage-help-add-new-page' )->plain(),
			'communityPolicy' => wfMessage( 'communitypage-help-policy' )->plain(),
			'editPageLink' => $this->getHelpPageLink( 'communitypage-help-module-edit-page-name' ),
			'addLinksPageLink' => $this->getHelpPageLink( 'communitypage-help-module-add-link-name' ),
			'addNewPageLink' => $this->getHelpPageLink( 'communitypage-help-module-new-page-name' ),
			'communityPolicyLink' => $this->getPolicyLink()
		];
	}

	private function getHelpPageLink( $messageKey ){
		return Title::newFromText(
			wfMessage( $messageKey )->inContentLanguage()->plain(), NS_HELP
		)->getLocalURL();
	}

	private function getPolicyLink() {
		$title = Title::newFromText(
			wfMessage( 'communitypage-policy-module-link-page-name' )->inContentLanguage()->plain(),
 			NS_MAIN
		);

 		if ( $title instanceof Title ) {
			return $title->getFullURL();
 		}

 		return '';
 	}
}
