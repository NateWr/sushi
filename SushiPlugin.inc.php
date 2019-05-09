<?php
/**
 * @file plugins/generic/sushi/SushiPlugin.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SushiPlugin
 * @ingroup plugins_generic_sushi
 *
 * @brief A plugin to provide COUNTER stats through a REST API that implements the SUSHI-Lite protocol.
 */
class SushiPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register
	 */
	public function register($category, $path, $mainContextId = NULL) {
		$success = parent::register($category, $path);
		if ($success && $this->getEnabled()) {
			HookRegistry::register('APIHandler::endpoints', [$this, 'endpoints']);
		}
		return $success;
	}

	/**
	 * @copydoc PKPPlugin::getDisplayName
	 */
	public function getDisplayName() {
		return __('plugins.generic.sushi.name');
	}

	/**
	 * @copydoc PKPPlugin::getDescription
	 */
	public function getDescription() {
		return __('plugins.generic.sushi.description');
	}

	/**
	 * Add endpoints to the stats APIHandler to serve the
	 * COUNTER reports
	 *
	 * @param string $hookName APIHandler::endpoints
	 * @param array $args [
	 * 	@option array The endpoints
	 *  @option APIHandler The handler for endpoints
	 * ]
	 */
	public function endpoints($hookName, $args) {
		$endpoints =& $args[0];
		$apiHandler = $args[1];

		if (!is_a($apiHandler, 'PKPStatsPublicationHandler')) {
			return;
		}

		array_unshift(
			$endpoints['GET'],
			[
				'pattern' => '/{contextPath}/api/{version}/stats/publications/sushi/reports/tr_j1',
				'handler' => [$this, 'tr_j1'],
				'roles' => [ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER],
			]
		);
	}

	/**
	 * Provide the tr_j1 COUNTER report
	 *
	 * Provides total abstract and galley views for a journal
	 * during the requested period.
	 *
	 * @param Request $slimRequest Slim request object
	 * @param APIResponse $response PSR-7 Response object
	 * @param array $args array
	 * @return APIResponse Response
	 */
	public function tr_j1($slimRequest, $response, $args) {
		$request = $this->getRequest();

		// Don't allow this endpoint to be accessed from the
		// site-wide path. Require a context
		if (!$request->getContext()) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$defaultParams = [
			'count' => 100,
			'position_token' => 0,
		];

		$requestParams = array_merge($defaultParams, $slimRequest->getQueryParams());

		// Require a customer_id of 'test'
		if (empty($requestParams['customer_id']) || $requestParams['customer_id'] !== 'test') {
			return $response->withStatus(400)->withJsonError('plugins.generic.sushi.api.invalidCustomerId');
		}

		// Require valid start and end dates
		if (empty($requestParams['begin_date']) || !$this->isValidDate($requestParams['begin_date'])
				|| empty($requestParams['end_date']) || !$this->isValidDate($requestParams['end_date'])) {
			return $response->withStatus(400)->withJsonError('plugins.generic.sushi.api.invalidDates');
		}

		// Require a count between 1 and 100
		if (!is_int($requestParams['count']) || $requestParams['count'] > 100 || $requestParams['count'] < 1) {
			return $response->withStatus(400)->withJsonError('plugins.generic.sushi.api.invalidCount');
		}

		// Require a valid offset
		if (!is_int($requestParams['position_token']) || $requestParams['position_token'] < 0) {
			return $response->withStatus(403)->withJsonError('plugins.generic.sushi.api.invalidPositionToken');
		}

		// Get a list of total abstract and galley views for each context
		$args = [
			'count' => $requestParams['count'],
			'dateStart' => DateTime::createFromFormat('Y-m-d', $requestParams['begin_date'] . '-01-01')->format('Y-m-d'),
			'dateEnd' => DateTime::createFromFormat('Y-m-d', $requestParams['end_date'] . '-12-31')->format('Y-m-d'),
			'offset' => $requestParams['position_token'],

			// Only include records for abstract and galley views.
			'assocTypes' => [ASSOC_TYPE_SUBMISSION, ASSOC_TYPE_SUBMISSION_FILE],

			// Pass an empty context array to include records from any context
			'contextIds' => [],
		];
		$totals = Services::get('stats')
			->getOrderedObjects(
				STATISTICS_DIMENSION_CONTEXT_ID, // Group results by context
				STATISTICS_ORDER_DESC,
				$args
			);

		// Prepare COUNTER format
		$rows = [];
		foreach ($totals as $total) {

			$context = \Services::get('context')->get($total['id']);

			// Drop stats for contexts that no longer exist
			if (!$context) {
				continue;
			}

			$rows[] = [
				'Title' => $context->getLocalizedData('name'),
				'Item_ID' => [
					[
						'Type' => 'Print_ISSN',
						'Value' => $context->getData('printIssn'),
					],
					[
						'Type' => 'Online_ISSN',
						'Value' => $context->getData('onlineIssn'),
					],
				],
				'Platform' => '...',
				'Publisher' => $context->getData('publisherInstitution'),
				'Performance' => [
					[
						'Period' => [
							'Begin_Date' => $args['dateStart'],
							'End_Date' => $args['dateEnd'],
						],
						'Instance' => [
							[
								'MetricType' => 'Unique_Item_Request',
								'Count' => $total['total'],
							],
						],
					],
				]
			];
		}

		return $response->withJson([
			'Report_Header' => [
				'Created' => date('c', time()),
				'Created_By' => '...',
				'Customer_ID' => $requestParams['customer_id'],
				'Report_ID' => 'TR_J1',
				'Release' => 5,
				'Report_Name' => '...',
				'Institution_Name' => '...',
				'Report_Filters' => [
					[
						'Name' => 'Platform',
						'Value' => '...',
					],
					[
						'Name' => 'Begin_Date',
						'Value' => $requestParams['begin_date'],
					],
					[
						'Name' => 'End_Date',
						'Value' => $requestParams['end_date'],
					],
					[
						'Name' => 'Data_Type',
						'Value' => 'Journal',
					],
				],
			],
			'Report_Items' => $rows
		]);
	}

	/**
	 * Check if a date is valid
	 *
	 * In this example we require begin_date and end_date to be years, but
	 * the SUSHI spec supports YYYY, YYYY-MM and YYYY-MM-DD.
	 *
	 * @param string $date
	 * @return boolean
	 */
	public function isValidDate($date) {
		$d = DateTime::createFromFormat('Y', $date);
		return $d && $d->format('Y') === $date;
	}
}
