/*global define*/
define('ext.wikia.adEngine.ml.ctp.ctpMobile', [
	'ext.wikia.adEngine.adContext',
	'ext.wikia.adEngine.ml.ctp.ctpMobileDataSource',
	'ext.wikia.adEngine.ml.modelFactory',
	'ext.wikia.adEngine.ml.model.linear'
], function (adContext, source, modelFactory, linearModel) {
	'use strict';

	return modelFactory.create({
		dataSource: source,
		model: linearModel.create(source.coefficients, source.intercept),
		name: 'ctpmobile',
		enabled: function () {
			return !!adContext.get('targeting.hasFeaturedVideo') &&
				adContext.get('rabbits.ctpMobile') &&
				adContext.get('targeting.skin') === 'mercury';
		},
		cachePrediction: true
	});
});
