<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer;

/*                                                                                                  *
 * This script belongs to the TYPO3 Flow package "Flowpack.ElasticSearch.ContentRepositoryAdaptor". *
 *                                                                                                  *
 * It is free software; you can redistribute it and/or modify it under                              *
 * the terms of the GNU Lesser General Public License, either version 3                             *
 *  of the License, or (at your option) any later version.                                          *
 *                                                                                                  *
 * The TYPO3 project - inspiring people to share!                                                   *
 *                                                                                                  */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Mapping\NodeTypeMappingBuilder;
use Flowpack\ElasticSearch\Domain\Model\Client;
use Flowpack\ElasticSearch\Domain\Model\Document as ElasticSearchDocument;
use Flowpack\ElasticSearch\Domain\Model\Index;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;

/**
 * Indexer for Content Repository Nodes. Triggered from the NodeIndexingManager.
 *
 * Internally, uses a bulk request.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer {

	/**
	 * Optional postfix for the index, e.g. to have different indexes by timestamp.
	 *
	 * @var string
	 */
	protected $indexNamePostfix = '';

	/**
	 * @Flow\Inject
	 * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient
	 */
	protected $searchClient;

	/**
	 * @Flow\Inject
	 * @var NodeTypeMappingBuilder
	 */
	protected $nodeTypeMappingBuilder;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @Flow\Inject
	 * @var NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * the default context variables available inside Eel
	 *
	 * @var array
	 */
	protected $defaultContextVariables;

	/**
	 * @var \TYPO3\Eel\CompilingEvaluator
	 * @Flow\Inject
	 */
	protected $eelEvaluator;

	/**
	 * The default configuration for a given property type in NodeTypes.yaml, if no explicit elasticSearch section defined there.
	 *
	 * @var array
	 */
	protected $defaultConfigurationPerType;

	/**
	 * The current ElasticSearch bulk request, in the format required by http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-bulk.html
	 *
	 * @var array
	 */
	protected $currentBulkRequest = array();

	/**
	 * @param array $settings
	 */
	public function injectSettings(array $settings) {
		$this->defaultConfigurationPerType = $settings['defaultConfigurationPerType'];
		$this->settings = $settings;
	}

	/**
	 * Returns the index name to be used for indexing, with optional indexNamePostfix appended.
	 *
	 * @return string
	 */
	public function getIndexName() {
		$indexName = $this->searchClient->getIndexName();
		if (strlen($this->indexNamePostfix) > 0) {
			$indexName .= '-' . $this->indexNamePostfix;
		}

		return $indexName;
	}

	/**
	 * Set the postfix for the index name
	 *
	 * @param $indexNamePostfix
	 * @return void
	 */
	public function setIndexNamePostfix($indexNamePostfix) {
		$this->indexNamePostfix = $indexNamePostfix;
	}

	/**
	 * Return the currently active index to be used for indexing
	 *
	 * @return Index
	 */
	public function getIndex() {
		return $this->searchClient->findIndex($this->getIndexName());
	}

	/**
	 * index this node, and add it to the current bulk request.
	 *
	 * @param NodeData $nodeData
	 * @throws \Exception
	 * @return string
	 */
	public function indexNode(NodeData $nodeData) {
		$persistenceObjectIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
		$nodeType = $nodeData->getNodeType();

		$mappingType = $this->getIndex()->findType(NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($nodeType));

		if ($nodeData->isRemoved()) {
			$mappingType->deleteDocumentById($persistenceObjectIdentifier);
			$this->logger->log(sprintf('NodeIndexer: Removed node %s from index (node flagged as removed). Persistence ID: %s', $nodeData->getContextPath(), $persistenceObjectIdentifier), LOG_DEBUG, NULL, 'ElasticSearch (CR)');
		}

		$nodePropertiesToBeStoredInElasticSearchIndex = array();

		foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {

			if (isset($propertyConfiguration['elasticSearch']) && isset($propertyConfiguration['elasticSearch']['indexing'])) {
				if ($propertyConfiguration['elasticSearch']['indexing'] !== '') {
					$nodePropertiesToBeStoredInElasticSearchIndex[$propertyName] = $this->evaluateEelExpression($propertyConfiguration['elasticSearch']['indexing'], $nodeData, $propertyName, ($nodeData->hasProperty($propertyName) ? $nodeData->getProperty($propertyName) : NULL), $persistenceObjectIdentifier);
				}

			} elseif (isset($propertyConfiguration['type']) && isset($this->defaultConfigurationPerType[$propertyConfiguration['type']]['indexing'])) {

				if ($this->defaultConfigurationPerType[$propertyConfiguration['type']]['indexing'] !== '') {
					$nodePropertiesToBeStoredInElasticSearchIndex[$propertyName] = $this->evaluateEelExpression($this->defaultConfigurationPerType[$propertyConfiguration['type']]['indexing'], $nodeData, $propertyName, ($nodeData->hasProperty($propertyName) ? $nodeData->getProperty($propertyName) : NULL), $persistenceObjectIdentifier);
				}

			} else {
				$this->logger->log(sprintf('NodeIndexer (%s) - Property "%s" not indexed because no configuration found.', $persistenceObjectIdentifier, $propertyName), LOG_DEBUG, NULL, 'ElasticSearch (CR)');
			}
		}

		$document = new ElasticSearchDocument($mappingType,
			$nodePropertiesToBeStoredInElasticSearchIndex,
			$persistenceObjectIdentifier
		);

		$isFulltextRoot = FALSE;
		$elasticSearchSettingsForNode = array();
		if ($nodeType->hasElasticSearch()) {
			$elasticSearchSettingsForNode = $nodeType->getElasticSearch();
			if (isset($elasticSearchSettingsForNode['isFulltextRoot']) && $elasticSearchSettingsForNode['isFulltextRoot'] === TRUE) {
				$isFulltextRoot = TRUE;
			}
		}

		$documentData = $document->getData();

		if ($isFulltextRoot === TRUE) {
			$documentData['__fulltext'] = array();

			// for fulltext root documents, we need to preserve the "__fulltext" field. That's why we use the
			// "update" API instead of the "index" API, with a custom script internally; as we
			// shall not delete the "__fulltext" part of the document if it has any.
			$this->currentBulkRequest[] = array(
				'update' => array(
					'_type' => $document->getType()->getName(),
					'_id' => $document->getId()
				)
			);

			// http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-update.html
			$this->currentBulkRequest[] = array(
				'script' => 'fulltext = ctx._source.__fulltext; ctx._source = newData; ctx._source.__fulltext = fulltext',
				'params' => array(
					'newData' => $documentData
				),
				'upsert' => $documentData
			);
		} else {
			// non-fulltext-root documents can be indexed as-they-are
			$this->currentBulkRequest[] = array(
				'index' => array(
					'_type' => $document->getType()->getName(),
					'_id' => $document->getId()
				)
			);

			$this->currentBulkRequest[] = $documentData;
		}

		// TODO: fulltext extraction here


		$this->logger->log(sprintf('NodeIndexer: Added / updated node %s. Persistence ID: %s', $nodeData->getContextPath(), $persistenceObjectIdentifier), LOG_DEBUG, NULL, 'ElasticSearch (CR)');
	}



	/**
	 * schedule node removal into the current bulk request.
	 *
	 * @param NodeData $nodeData
	 * @return string
	 */
	public function removeNode(NodeData $nodeData) {
		$persistenceObjectIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);

		$this->currentBulkRequest[] = array(
			'delete' => array(
				'_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($nodeData->getNodeType()),
				'_id' => $persistenceObjectIdentifier
			)
		);

		$this->logger->log(sprintf('NodeIndexer: Removed node %s from index (node actually removed). Persistence ID: %s', $nodeData->getContextPath(), $persistenceObjectIdentifier), LOG_DEBUG, NULL, 'ElasticSearch (CR)');
	}

	/**
	 * perform the current bulk request
	 *
	 * @return void
	 */
	public function flush() {
		if (count($this->currentBulkRequest) === 0) {
			return;
		}

		$contents = '';
		foreach ($this->currentBulkRequest as $bulkRequestLine) {
			$contents .= json_encode($bulkRequestLine) . "\n";
		}
		$responseAsLines = $this->getIndex()->request('POST', '/_bulk', array(), $contents)->getOriginalResponse()->getContent();
		foreach (explode('\n', $responseAsLines) as $responseLine) {
			if (strpos($responseLine, 'error') !== FALSE) {
				$this->logger->log('Indexing Error: ' . $responseLine, LOG_ERR);
			}
		}
	}

	/**
	 * Evaluate an Eel expression.
	 *
	 * TODO: REFACTOR TO Eel package (as this is copy/pasted from TypoScript Runtime)
	 *
	 * @param string $expression The Eel expression to evaluate
	 * @param NodeData $node
	 * @param string $propertyName
	 * @param mixed $value
	 * @param string $persistenceObjectIdentifier
	 * @return mixed The result of the evaluated Eel expression
	 * @throws Exception
	 */
	protected function evaluateEelExpression($expression, NodeData $node, $propertyName, $value, $persistenceObjectIdentifier) {
		$matches = NULL;
		if (preg_match(\TYPO3\Eel\Package::EelExpressionRecognizer, $expression, $matches)) {
			$contextVariables = array_merge($this->getDefaultContextVariables(), array(
				'node' => $node,
				'propertyName' => $propertyName,
				'value' => $value,
				'persistenceObjectIdentifier' => $persistenceObjectIdentifier
			));

			$context = new \TYPO3\Eel\Context($contextVariables);

			$value = $this->eelEvaluator->evaluate($matches['exp'], $context);
			return $value;
		} else {
			throw new Exception('The Indexing Eel expression "' . $expression . '" used to index property "' . $propertyName . '" of "' . $node->getNodeType()->getName() . '" was not a valid Eel expression. Perhaps you forgot to wrap it in ${...}?', 1383635796);
		}
	}

	/**
	 * Get variables from configuration that should be set in the context by default.
	 * For example Eel helpers are made available by this.
	 *
	 * TODO: REFACTOR TO Eel package (as this is copy/pasted from TypoScript Runtime
	 *
	 * @return array Array with default context variable objects.
	 */
	protected function getDefaultContextVariables() {
		if ($this->defaultContextVariables === NULL) {
			$this->defaultContextVariables = array();
			if (isset($this->settings['defaultContext']) && is_array($this->settings['defaultContext'])) {
				foreach ($this->settings['defaultContext'] as $variableName => $objectType) {
					$currentPathBase = &$this->defaultContextVariables;
					$variablePathNames = explode('.', $variableName);
					foreach ($variablePathNames as $pathName) {
						if (!isset($currentPathBase[$pathName])) {
							$currentPathBase[$pathName] = array();
						}
						$currentPathBase = &$currentPathBase[$pathName];
					}
					$currentPathBase = new $objectType();
				}
			}
		}
		return $this->defaultContextVariables;
	}

	/**
	 * Update the index alias
	 *
	 * @return void
	 * @throws \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception
	 */
	public function updateIndexAlias() {
		$aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name
		if ($this->getIndexName() === $aliasName) {
			throw new \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception('UpdateIndexAlias is only allowed to be called when $this->setIndexNamePostfix has been created.', 1383649061);
		}

		if (!$this->getIndex()->exists()) {
			throw new \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception('the target index for updateIndexAlias does not exist. This shall never happen.', 1383649125);
		}


		$aliasActions = array();
		try {
			$response = $this->searchClient->request('GET', '/*/_alias/' . $aliasName);
			if ($response->getStatusCode() !== 200) {
				throw new \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception('the alias "' . $aliasName . '" was not found with some unexpected error... (return code: ' .  $response->getStatusCode(), 1383650137);
			}

			$indexNames = array_keys($response->getTreatedContent());

			foreach ($indexNames as $indexName) {
				$aliasActions[] = array(
					'remove' => array(
						'index' => $indexName,
						'alias' => $aliasName
					)
				);
			}
		} catch(\Flowpack\ElasticSearch\Transfer\Exception\ApiException $exception) {
			// in case of 404, do not throw an error...
		}

		$aliasActions[] = array(
			'add' => array(
				'index' => $this->getIndexName(),
				'alias' => $aliasName
			)
		);

		$this->searchClient->request('POST', '/_aliases', array(), \json_encode(array('actions' => $aliasActions)));
	}

	/**
	 * Remove old indices which are not active anymore (remember, each bulk index creates a new index from scratch,
	 * making the "old" index a stale one).
	 *
	 * @return array<string> a list of index names which were removed
	 */
	public function removeOldIndices() {
		$aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name

		$currentlyLiveIndices = array_keys($this->searchClient->request('GET', '/*/_alias/' . $aliasName)->getTreatedContent());

		$indexStatus = $this->searchClient->request('GET', '/_status')->getTreatedContent();
		$allIndices = array_keys($indexStatus['indices']);

		$indicesToBeRemoved = array();

		foreach ($allIndices as $indexName) {
			if (strpos($indexName, $aliasName . '-') !== 0) {
				// filter out all indices not starting with the alias-name, as they are unrelated to our application
				continue;
			}

			if (array_search($indexName, $currentlyLiveIndices) !== FALSE) {
				// skip the currently live index names from deletion
				continue;
			}

			$indicesToBeRemoved[] = $indexName;
		}

		if (count($indicesToBeRemoved) > 0) {
			$this->searchClient->request('DELETE', '/' . implode(',', $indicesToBeRemoved) . '/');
		}

		return $indicesToBeRemoved;
	}
}