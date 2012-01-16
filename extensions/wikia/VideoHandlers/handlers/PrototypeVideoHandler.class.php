<?php

class PrototypeVideoHandler extends VideoHandler {

	protected $apiName = 'PrototypeApiWrapper';
	protected static $aspectRatio = 1.7777778;

	function getEmbed($articleId, $width, $autoplay=false, $isAjax=false){
		// hardcoden but this is only a prototype. In future it will be handled in a better way
		$width = 660;
		$height = (integer) ($width / self::$aspectRatio);
		return '<div class="embedHtml">
				<object id="rg_player_ac330d90-cb46-012e-f91c-12313d18e962" name="rg_player_ac330d90-cb46-012e-f91c-12313d18e962" type="application/x-shockwave-flash" width="660" height="371" classid="clsid:ac330d90-cb46-012e-f91c-12313d18e962" style="visibility: visible;" data="http://anomaly.realgravity.com/flash/player.swf">
				<param name="allowscriptaccess" value="always">
				<param name="allowNetworking" value="all">
				<param name="menu" value="false">
				<param name="wmode" value="transparent">
				<param name="allowFullScreen" value="true">
				<param name="flashvars" value="&amp;config=http://mediacast.realgravity.com/vs/api/playerxml/ac330d90-cb46-012e-f91c-12313d18e962">
				<embed id="ac330d90-cb46-012e-f91c-12313d18e962" name="ac330d90-cb46-012e-f91c-12313d18e962" width="'.$width.'" height="'.$height.'" allownetworking="all" allowscriptaccess="always" allowfullscreen="true" wmode="transparent" flashvars="config=http://mediacast.realgravity.com/vs/api/playerxml/ac330d90-cb46-012e-f91c-12313d18e962?video_guid=6fb1e3829bb6915446d08368cd8b1a2300f0" src="http://anomaly.realgravity.com/flash/player.swf">
			</object></div>';
	}
	
}
