require([
	'wikia.window',
	'wikia.cookies',
	'wikia.tracker',
	'wikia.trackingOptIn',
	'wikia.abTest',
	'ext.wikia.adEngine.adContext',
	'wikia.articleVideo.featuredVideo.data',
	'wikia.articleVideo.featuredVideo.autoplay',
	'wikia.articleVideo.featuredVideo.ads',
	'wikia.articleVideo.featuredVideo.moatTracking',
	'wikia.articleVideo.featuredVideo.cookies',
	require.optional('ext.wikia.adEngine.lookup.a9')
], function (
	win,
	cookies,
	tracker,
	trackingOptIn,
	abTest,
	adContext,
	videoDetails,
	featuredVideoAutoplay,
	featuredVideoAds,
	featuredVideoMoatTracking,
	featuredVideoCookieService,
	a9
) {
	if (!videoDetails) {
		return;
	}

	//Fallback to the generic playlist when no recommended videos playlist is set for the wiki
	var recommendedPlaylist = videoDetails.recommendedVideoPlaylist || 'Y2RWCKuS',
		videoTags = videoDetails.videoTags || '',
		inFeaturedVideoClickToPlayABTest = abTest.inGroup('FV_CLICK_TO_PLAY', 'CLICK_TO_PLAY'),
		willAutoplay = featuredVideoAutoplay.isAutoplayEnabled(),
		slotTargeting = {
			plist: recommendedPlaylist,
			vtags: videoTags
		},
		responseTimeout = 2000,
		bidParams;

	function isFromRecirculation() {
		return window.location.search.indexOf('wikia-footer-wiki-rec') > -1;
	}

	function onPlayerReady(playerInstance) {
		define('wikia.articleVideo.featuredVideo.jwplayer.instance', function() {
			return playerInstance;
		});

		win.dispatchEvent(new CustomEvent('wikia.jwplayer.instanceReady', {detail: playerInstance}));

		trackingOptIn.pushToUserConsentQueue(function () {
			featuredVideoAds(playerInstance, bidParams, slotTargeting);
			featuredVideoMoatTracking.track(playerInstance);
		});

		playerInstance.on('autoplayToggle', function (data) {
			featuredVideoCookieService.setAutoplay(data.enabled ? '1' : '0');
		});

		playerInstance.on('captionsSelected', function (data) {
			featuredVideoCookieService.setCaptions(data.selectedLang);
		});
	}

	function setupPlayer() {
		featuredVideoMoatTracking.loadTrackingPlugin();
		win.wikiaJWPlayer('featured-video__player', {
			tracking: {
				track: function (data) {
					tracker.track(data);
				},
				setCustomDimension: win.guaSetCustomDimension,
				comscore: !win.wgDevelEnvironment
			},
			autoplay: willAutoplay,
			selectedCaptionsLanguage: featuredVideoCookieService.getCaptions(),
			settings: {
				showAutoplayToggle: !adContext.get('rabbits.ctpDesktop') && !inFeaturedVideoClickToPlayABTest,
				showQuality: true,
				showCaptions: true
			},
			sharing: true,
			mute: isFromRecirculation() ? false : willAutoplay,
			related: {
				time: 3,
				playlistId: recommendedPlaylist,
				autoplay: featuredVideoAutoplay.inNextVideoAutoplayEnabled()
			},
			videoDetails: {
				description: videoDetails.description,
				title: videoDetails.title,
				playlist: videoDetails.playlist
			},
			logger: {
				clientName: 'oasis'
			},
			lang: videoDetails.lang
		}, onPlayerReady);
	}

	trackingOptIn.pushToUserConsentQueue(function () {
		if (a9 && adContext.get('bidders.a9Video')) {
			a9.waitForResponseCallbacks(
				function onSuccess() {
					bidParams = a9.getSlotParams('FEATURED');
					setupPlayer();
				},
				function onTimeout() {
					bidParams = {};
					setupPlayer();
				},
				responseTimeout
			);
		} else {
			setupPlayer();
		}
	});
});
