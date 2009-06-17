<?php
    /* 
     * Homepage cache managing special page
     * @author Gerard Adamczewski <gerard@wikia.com>
     */
     
     if ( ! defined( 'MEDIAWIKI' ) ) {
	die();
     }
     
     require_once 'CacheFunctions.php';

     $wgSpecialPages['EditingCacheEditor'] = array('SpecialPage','EditingCacheEditor','cacheedit');
     $wgAvailableRights[] = 'cacheedit';
     $wgGroupPermissions['staff']['cacheedit'] = true;
     
     function eceManageData()
     {
        global $wgMemc, $wgRequest, $wgExternalSharedDB;
	$data = explode( "\n", $wgRequest->getText( 'data' ) );
	$wgMemc->set( 'wikia:editingcachedata' , $data, 60*24*60 );
	$db = wfGetDB( DB_MASTER, array(), $wgExternalSharedDB );
	$db->replace( 'homepage_caches', array(), array(
	    'memckey' => 'wikia:editingcachedata',
	    'value' => serialize( $data ) )
	);
	return 'Current data has been saved.';
     }
     
     function eceDrawForm( $info )
     {
        global $wgOut, $wgTitle, $wgMemc, $wgExternalSharedDB, $wgExternalStatsDB;

	$cnt = 100;
	$formAction = $wgTitle->escapeLocalURL();
	$d = array();
	$dbs = wfGetDB(DB_SLAVE, array(), $wgExternalStatsDB);
	$res = $dbs->query( "select rc_timestamp, rc_title, rc_type, rc_namespace, rc_city_id from city_recentchanges_3_days where mod( rc_namespace, 2) = 0 " . limit2langs('rc_city_id')  . "group by rc_title order by rc_timestamp desc limit $cnt" );
	$dbr = wfGetDB( DB_SLAVE, array(), $wgExternalSharedDB );
	while( $o = $dbs->fetchObject( $res ) )
	{
	    $r = $dbr->query( 'select city_url from city_list where city_id='.$o->rc_city_id );
	    $u = $dbr->fetchObject( $r );
	    $title = Title::makeTitleSafe( $o->rc_namespace, $o->rc_title );
	    $url = substr( $u->city_url, 0, -1 ) . $title->getLocalURL();
            $d[] = $url . ' ' . str_replace( '_', ' ', $o->rc_title );
	    $dbr->freeResult( $r );
        }
	$dbs->freeResult( $res );
	$c = getData( 'wikia:editingcachedata' );
	$data = ( is_array( $d ) ) ? implode( "\n", $d ) : '';
	$curr = ( is_array( $c ) ) ? implode( "\n", $c ) : '';
	$text = "<form action='$formAction' method='POST' onSubmit='return confirm(\"Are you sure you want to update configuration?\");'>
		    <div>$info</div>
		    <div>Typed:</div>
		    <div><textarea rows='10' readonly>$data</textarea></div>
		    <div>Current:</div>
		    <div><textarea name='data' rows='10'>$curr</textarea></div>
		    <div><input type='submit' name='submit' value='Save' /></div>
		</form>";
	$wgOut->addHtml( $text );
     }
     
     function wfSpecialeditingCacheEditor()
     {
        global $wgOut, $wgRequest;
	
        $ceType = 'Editing';
        $wgOut->setPageTitle("Manage $ceType Cache data");
	$wgOut->setHtmlTitle("Manage $ceType Cache data");
	$ceInfo = '';
	if( $wgRequest->getVal( 'submit' ) == 'Save' )
	{
	    $ceInfo = eceManageData();
	}
	eceDrawForm( $ceInfo );
     }
?>
