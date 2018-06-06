/*global define*/
define('ext.wikia.adEngine.wrappers.prebid', [
	'ext.wikia.adEngine.adContext',
	'wikia.location',
	'wikia.window'
], function (adContext, loc, win) {
	'use strict';

	var validResponseStatusCode = 1,
		errorResponseStatusCode = 2,
		isNewPrebidEnabled = adContext.get('opts.isNewPrebidEnabled'),
		isConsentStringEnabled = adContext.get('opts.isConsentStringEnabled'),
		prebidConfig = {
			debug: loc.href.indexOf('pbjs_debug=1') >= 0,
			enableSendAllBids: true,
			bidderSequence: 'random',
			bidderTimeout: 2000,
			userSync: {
				iframeEnabled: true,
				enabledBidders: [],
				syncDelay: 6000
			}
		};

	win.pbjs = win.pbjs || {};
	win.pbjs.que = win.pbjs.que || [];

	if (isConsentStringEnabled) {
		prebidConfig.consentManagement = {
			cmpApi: 'iab',
			timeout: 2000,
			allowAuctionWithoutConsent: false
		};
	}

	if (isNewPrebidEnabled) {
		win.pbjs.que.push(function() {
			win.pbjs.setConfig(prebidConfig);
		});
	}

	function get() {
		return win.pbjs;
	}

	function getBidByAdId(adId) {
		// TODO: clean up after GDPR rollout
		var bids = [];

		if (!win.pbjs || (typeof win.pbjs.getBidResponses !== 'function' && !win.pbjs._bidsReceived)) {
			return null;
		}

		if (win.pbjs._bidsReceived) {
			bids = win.pbjs._bidsReceived.filter(function (bid) {
				return adId === bid.adId;
			});
		} else {
			bids = win.pbjs.getAllPrebidWinningBids().filter(function (bid) {
				return adId === bid.adId;
			});

			if (!bids.length) {
				var responses = win.pbjs.getBidResponses();
				Object.keys(responses).forEach(function (adUnit) {
					var adUnitsBids = responses[adUnit].bids.filter(function (bid) {
						return adId === bid.adId;
					});

					bids = bids.concat(adUnitsBids);
				});
			}
		}

		return bids.length ? bids[0] : null;
	}

	function getWinningVideoBidBySlotName(slotName, allowedBidders) {
		var bids;

		if (!win.pbjs || !win.pbjs.getBidResponsesForAdUnitCode) {
			return null;
		}

		bids = win.pbjs.getBidResponsesForAdUnitCode(slotName).bids || [];

		return bids.filter(function (bid) {
			var canUseThisBidder = !allowedBidders || allowedBidders.indexOf(bid.bidderCode) !== -1,
				hasVast = bid.vastUrl || bid.vastContent;

			return canUseThisBidder && hasVast && bid.cpm > 0;
		}).reduce(function (previousBid, currentBid) {
			if (previousBid === null || currentBid.cpm > previousBid.cpm) {
				return currentBid;
			}

			return previousBid;
		}, null);
	}

	function push(callback) {
		win.pbjs.que.push(callback);
	}

	return {
		validResponseStatusCode: validResponseStatusCode,
		errorResponseStatusCode: errorResponseStatusCode,
		get: get,
		getBidByAdId: getBidByAdId,
		getWinningVideoBidBySlotName: getWinningVideoBidBySlotName,
		push: push
	};
});
