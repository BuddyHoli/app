<?php

/**
 * TransactionTraceNewrelic implements the TransactionTrace plugin interface and handles reporting
 * transaction type name as newrelic's transaction name and all attributes as custom parameters.
 *
 * @see https://docs.newrelic.com/docs/agents/php-agent/configuration/php-agent-api
 */
class TransactionTraceNewrelic {

	// create custom transactions for given PHP calls
	private static $customTraces = [
		# PLATFORM-1696: RabbitMQ traffic
		'Wikia\Tasks\Tasks\BaseTask::queue',
		'PhpAmqpLib\Wire\IO\StreamIO::read',
	];

	/**
	 * Set up NewRelic integration and custom PHP calls tracer
	 */
	function __construct() {
		if ( function_exists( 'newrelic_add_custom_tracer' ) ) {
			foreach( self::$customTraces as $customTrace ) {
				newrelic_add_custom_tracer( $customTrace );
			}
		}
	}

	/**
	 * Update Newrelic's transaction name
	 *
	 * @param string $type
	 */
	public function onTypeChange( $type ) {
		if ( function_exists( 'newrelic_name_transaction' ) ) {
			newrelic_name_transaction( $type );
		}
	}

	/**
	 * Record an attribute as Newrelic's custom parameter
	 *
	 * @param string $key Attribute key
	 * @param mixed $value Attribute value
	 */
	public function onAttributeChange( $key, $value ) {
		if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
			if ( is_bool( $value ) ) {
				$value = $value ? "yes" : "no";
			}
			newrelic_add_custom_parameter( $key, $value );
		}
	}
}
