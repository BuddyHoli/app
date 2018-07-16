/*global define*/
define('ext.wikia.adEngine.lookup.prebid.adapters.kargo', [
	'ext.wikia.adEngine.adContext',
	'ext.wikia.adEngine.context.slotsContext',
	'ext.wikia.adEngine.wad.babDetection'
], function (adContext, slotsContext, babDetection) {
	'use strict';

	var bidderName = 'kargo',
		slots = {
			mercury: {
				MOBILE_IN_CONTENT: {
					sizes: [
						[300, 250]
					]
				}
			}
		};

	function isEnabled() {
		return adContext.get('bidders.kargo') && (!babDetection.isBlocking() || adContext.get('opts.wadIL'));
	}

	function getSlots(skin) {
		return slotsContext.filterSlotMap(slots[skin]);
	}

	function prepareAdUnit(slotName, config) {
		return {
			code: slotName,
			mediaTypes: {
				banner: {
					sizes: config.sizes
				}
			},
			bids: config.sizes.map(function () {
				return {
					bidder: bidderName,
					params: {
						placementId: '_cGWUgEUv0T'
					}
				};
			})
		};
	}

	function getName() {
		return bidderName;
	}

	return {
		isEnabled: isEnabled,
		getName: getName,
		getSlots: getSlots,
		prepareAdUnit: prepareAdUnit
	};
});
