<?php

/**
 * Register when & where user is logged in and what was changed in
 * user preferences. Could be used for restoring wrongly saved preferences (undo).
 *
 * @author Krzysztof Krzyżaniak <eloy@wikia-inc.com>
 */

/**
 * static methods, wait for PHP with namespaces
 */
class UserChangesHistory {

	const LOGIN_AUTO = 0;
	const LOGIN_FORM = 1;
	const LOGIN_REGISTRATION = 2;

	/**
	 * LoginHistoryHook
	 *
	 * store information about when & where user logged in, for stats
	 * purposes, called by Hook UserLoginComplete and UserLoadFromSessionInfo
	 * Data is stored in external storage archive1
	 *
	 * @author Krzysztof Krzyżaniak (eloy) <eloy@wikia-inc.com>
	 * @access public
	 * @static
	 *
	 * @param Integer $from -- which hook call this
	 * @param User    $User -- User class instance
	 * @param String  $type -- UserLoadFromSessionInfo set this to 'cookie' or 'session'
	 *
	 * @return true		process other hooks
	 */
	static public function LoginHistoryHook( $from, $User, $type = false ) {
		global $wgCityId; #--- private wikia identifier, you can use wgDBname
		global $wgExternalDatawareDB;

		wfProfileIn( __METHOD__ );

		/**
		 * if user id is empty it means that user object is not loaded
		 * store information only for registered users
		 */
		$id = $User->getId();
		if ( $id ) {

			if( $from == self::LOGIN_AUTO && $type == "session" ) {
				# ignore
			}
			else {
				$dbw = wfGetDB( DB_MASTER, array(), $wgExternalDatawareDB ) ;

				$status = $dbw->insert(
					"user_login_history",
					array(
						"user_id"   => $id,
						"city_id"   => $wgCityId,
						"ulh_from"  => $from
					),
					__METHOD__
				);
				if ( $dbw->getFlag( DBO_TRX ) ) {
					$dbw->commit();
				}
			}
		}

		wfProfileOut( __METHOD__ );

		return true;
	}


	/**
	 * SavePreferencesHook
	 *
	 * Store row from user table before changes of preferences are saved.
	 * Called by Hook SavePreferences
	 * Data is stored in external storage archive1
	 *
	 * @author Krzysztof Krzyżaniak (eloy) <eloy@wikia-inc.com>
	 * @access public
	 * @static
	 *
	 * @return true		process other hooks
	 */
	static public function SavePreferencesHook( $preferences, $User, $msg ) {

		global $wgExternalDatawareDB;

		wfProfileIn( __METHOD__ );

		$id = $User->getId();
		if( $id ) {
			/**
			 * caanot use "insert from select" because we got two different db
			 * clusters. But we should have all user data already loaded.
			 */

			$dbw = wfGetDB( DB_MASTER, array(), $wgExternalDatawareDB ) ;

			/**
			 * so far encodeOptions is public by default but could be
			 * private in future
			 */
			$status = $dbw->insert(
				"user_history",
				array(
					"user_id"          => $id,
					"user_name"        => $User->mName,
					"user_real_name"   => $User->mRealName,
					"user_password"    => $User->mPassword,
					"user_newpassword" => $User->mNewpassword,
					"user_email"       => $User->mEmail,
					"user_options"     => $User->encodeOptions(),
					"user_touched"     => $User->mTouched,
					"user_token"       => $User->mToken,
				),
				__METHOD__
			);
			if ( $dbw->getFlag( DBO_TRX ) ) {
				$dbw->commit();
			}
		}

		wfProfileOut( __METHOD__ );

		return true;
	}
}
