/*global define*/
define('ext.wikia.adEngine.lookup.prebid.adapters.appnexusAst', [
	'ext.wikia.adEngine.adContext',
	'ext.wikia.adEngine.context.slotsContext',
	'wikia.location'
], function (adContext, slotsContext, loc) {
	'use strict';

	var bidderName = 'appnexusAst',
		aliases = {
			'appnexus': [bidderName]
		},
		debugPlacementId = '5768085',
		slots = {
			oasis: {
				INCONTENT_PLAYER: {
					placementId: '11543172'
				}
			},
			mercury: {
				MOBILE_IN_CONTENT: {
					placementId: '11543173'
				}
			}
		};

	function isEnabled() {
		return adContext.get('bidders.appnexusAst');
	}

	function prepareAdUnit(slotName, config) {
		var isDebugMode = loc.href.indexOf('appnexusast_debug_mode=1') >= 0;

		return {
			code: slotName,
			mediaTypes: {
				video: {
					context: 'outstream',
					playerSize: [640, 480]
				}
			},
			bids: [
				{
					bidder: bidderName,
					params: {
						placementId: isDebugMode ? debugPlacementId : config.placementId,
						video: {
							skippable: false,
							playback_method: ['auto_play_sound_off']
						}
					}
				}
			]
		};
	}

	function getSlots(skin) {
		return slotsContext.filterSlotMap(slots[skin]);
	}

	function getName() {
		return bidderName;
	}

	function getAliases() {
		return aliases;
	}

	return {
		isEnabled: isEnabled,
		getName: getName,
		getAliases: getAliases,
		getSlots: getSlots,
		prepareAdUnit: prepareAdUnit
	};
});
