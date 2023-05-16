<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\Domain\Search\ResultSet;

use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\QueryFields;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\Query;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\QueryBuilder;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\SearchQuery;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\Parser\ResultParserRegistry;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResult;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResultBuilder;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequest;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequestAware;
use ApacheSolrForTypo3\Solr\Domain\Variants\VariantsProcessor;
use ApacheSolrForTypo3\Solr\Query\Modifier\Modifier;
use ApacheSolrForTypo3\Solr\Search;
use ApacheSolrForTypo3\Solr\Search\QueryAware;
use ApacheSolrForTypo3\Solr\Search\SearchAware;
use ApacheSolrForTypo3\Solr\Search\SearchComponentManager;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use ApacheSolrForTypo3\Solr\System\Solr\SolrIncompleteResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use UnexpectedValueException;

use function get_class;

/**
 * The SearchResultSetService is responsible to build a SearchResultSet from a SearchRequest.
 * It encapsulates the logic to trigger a search in order to be able to reuse it in multiple places.
 *
 * @author Timo Schmidt <timo.schmidt@dkd.de>
 */
class SearchResultSetService
{
    protected Search $search;

    protected ?SearchResultSet $lastResultSet = null;

    protected ?bool $isSolrAvailable = null;

    protected TypoScriptConfiguration $typoScriptConfiguration;

    protected SolrLogManager $logger;

    protected SearchResultBuilder $searchResultBuilder;

    protected QueryBuilder $queryBuilder;

    public function __construct(
        TypoScriptConfiguration $configuration,
        Search $search,
        ?SolrLogManager $solrLogManager = null,
        ?SearchResultBuilder $resultBuilder = null,
        ?QueryBuilder $queryBuilder = null
    ) {
        $this->search = $search;
        $this->typoScriptConfiguration = $configuration;
        $this->logger = $solrLogManager ?? GeneralUtility::makeInstance(SolrLogManager::class, __CLASS__);
        $this->searchResultBuilder = $resultBuilder ?? GeneralUtility::makeInstance(SearchResultBuilder::class);
        $this->queryBuilder = $queryBuilder ?? GeneralUtility::makeInstance(QueryBuilder::class, $configuration, $solrLogManager);
    }

    public function getIsSolrAvailable(bool $useCache = true): bool
    {
        if ($this->isSolrAvailable === null) {
            $this->isSolrAvailable = $this->search->ping($useCache);
        }
        return $this->isSolrAvailable;
    }

    /**
     * Retrieves the used search instance.
     */
    public function getSearch(): Search
    {
        return $this->search;
    }

    protected function initializeRegisteredSearchComponents(Query $query, SearchRequest $searchRequest): void
    {
        $searchComponents = $this->getRegisteredSearchComponents();

        foreach ($searchComponents as $searchComponent) {
            /* @var Search\SearchComponent $searchComponent */
            $searchComponent->setSearchConfiguration($this->typoScriptConfiguration->getSearchConfiguration());

            if ($searchComponent instanceof QueryAware) {
                $searchComponent->setQuery($query);
            }

            if ($searchComponent instanceof SearchRequestAware) {
                $searchComponent->setSearchRequest($searchRequest);
            }

            $searchComponent->initializeSearchComponent();
        }
    }

    protected function getResultSetClassName(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['searchResultSetClassName '] ?? SearchResultSet::class;
    }

    /**
     * Performs a search and returns a SearchResultSet.
     *
     * @throws Facets\InvalidFacetPackageException
     */
    public function search(SearchRequest $searchRequest): SearchResultSet
    {
        $resultSet = $this->getInitializedSearchResultSet($searchRequest);
        $this->lastResultSet = $resultSet;

        $resultSet = $this->handleSearchHook('beforeSearch', $resultSet);
        if ($this->shouldReturnEmptyResultSetWithoutExecutedSearch($searchRequest)) {
            $resultSet->setHasSearched(false);
            return $resultSet;
        }

        $query = $this->queryBuilder->buildSearchQuery(
            $searchRequest->getRawUserQuery(),
            $searchRequest->getResultsPerPage(),
            $searchRequest->getAdditionalFilters()
        );
        $this->initializeRegisteredSearchComponents($query, $searchRequest);
        $resultSet->setUsedQuery($query);

        // performing the actual search, sending the query to the Solr server
        $query = $this->modifyQuery($query, $searchRequest, $this->search);
        $response = $this->doASearch($query, $searchRequest);

        if ($searchRequest->getResultsPerPage() === 0) {
            // when resultPerPage was forced to 0 we also set the numFound to 0 to hide results, e.g.
            // when results for the initial search should not be shown.
            // @extensionScannerIgnoreLine
            $response->response->numFound = 0;
        }

        $resultSet->setHasSearched(true);
        $resultSet->setResponse($response);

        $this->getParsedSearchResults($resultSet);

        $resultSet->setUsedAdditionalFilters($this->queryBuilder->getAdditionalFilters());

        /* @var VariantsProcessor $variantsProcessor */
        $variantsProcessor = GeneralUtility::makeInstance(
            VariantsProcessor::class,
            /** @scrutinizer ignore-type */
            $this->typoScriptConfiguration,
            /** @scrutinizer ignore-type */
            $this->searchResultBuilder
        );
        $variantsProcessor->process($resultSet);

        /* @var ResultSetReconstitutionProcessor $searchResultReconstitutionProcessor */
        $searchResultReconstitutionProcessor = GeneralUtility::makeInstance(ResultSetReconstitutionProcessor::class);
        $searchResultReconstitutionProcessor->process($resultSet);

        $resultSet = $this->getAutoCorrection($resultSet);

        return $this->handleSearchHook('afterSearch', $resultSet);
    }

    /**
     * Uses the configured parser and retrieves the parsed search results.
     */
    protected function getParsedSearchResults(SearchResultSet $resultSet): void
    {
        /* @var ResultParserRegistry $parserRegistry */
        $parserRegistry = GeneralUtility::makeInstance(ResultParserRegistry::class, $this->typoScriptConfiguration);
        $useRawDocuments = (bool)$this->typoScriptConfiguration->getValueByPathOrDefaultValue('plugin.tx_solr.features.useRawDocuments', false);
        $parserRegistry->getParser($resultSet)->parse($resultSet, $useRawDocuments);
    }

    /**
     * Evaluates conditions on the request and configuration and returns true if no search should be triggered and an empty
     * SearchResultSet should be returned.
     */
    protected function shouldReturnEmptyResultSetWithoutExecutedSearch(SearchRequest $searchRequest): bool
    {
        if ($searchRequest->getRawUserQueryIsNull() && !$this->getInitialSearchIsConfigured()) {
            // when no rawQuery was passed or no initialSearch is configured, we pass an empty result set
            return true;
        }

        if ($searchRequest->getRawUserQueryIsEmptyString() && !$this->typoScriptConfiguration->getSearchQueryAllowEmptyQuery()) {
            // the user entered an empty query string "" or "  " and empty querystring is not allowed
            return true;
        }

        return false;
    }

    /**
     * Initializes the SearchResultSet from the SearchRequest
     */
    protected function getInitializedSearchResultSet(SearchRequest $searchRequest): SearchResultSet
    {
        /* @var SearchResultSet $resultSet */
        $resultSetClass = $this->getResultSetClassName();
        $resultSet = GeneralUtility::makeInstance($resultSetClass);

        $resultSet->setUsedSearchRequest($searchRequest);
        $resultSet->setUsedPage((int)$searchRequest->getPage());
        $resultSet->setUsedResultsPerPage($searchRequest->getResultsPerPage());
        $resultSet->setUsedSearch($this->search);
        return $resultSet;
    }

    /**
     * Executes the search and builds a fake response for a current bug in Apache Solr 6.3
     *
     * @throws SolrIncompleteResponseException
     */
    protected function doASearch(Query $query, SearchRequest $searchRequest): ResponseAdapter
    {
        // the offset multiplier is page - 1 but not less than zero
        $offsetMultiplier = max(0, $searchRequest->getPage() - 1);
        $offSet = $offsetMultiplier * $searchRequest->getResultsPerPage();

        $response = $this->search->search($query, $offSet);
        if ($response === null) {
            throw new SolrIncompleteResponseException('The response retrieved from solr was incomplete', 1505989678);
        }

        return $response;
    }

    /**
     * Performs autocorrection if wanted
     *
     * @throws Facets\InvalidFacetPackageException
     */
    protected function getAutoCorrection(SearchResultSet $searchResultSet): SearchResultSet
    {
        // no secondary search configured
        if (!$this->typoScriptConfiguration->getSearchSpellcheckingSearchUsingSpellCheckerSuggestion()) {
            return $searchResultSet;
        }

        // if more as zero results
        if ($searchResultSet->getAllResultCount() > 0) {
            return $searchResultSet;
        }

        // no corrections present
        if (!$searchResultSet->getHasSpellCheckingSuggestions()) {
            return $searchResultSet;
        }

        return $this->performAutoCorrection($searchResultSet);
    }

    /**
     * Performs autocorrection
     *
     * @throws Facets\InvalidFacetPackageException
     */
    protected function performAutoCorrection(SearchResultSet $searchResultSet): SearchResultSet
    {
        $searchRequest = $searchResultSet->getUsedSearchRequest();
        $suggestions = $searchResultSet->getSpellCheckingSuggestions();

        $maximumRuns = $this->typoScriptConfiguration->getSearchSpellcheckingNumberOfSuggestionsToTry();
        $runs = 0;

        foreach ($suggestions as $suggestion) {
            $runs++;

            $correction = $suggestion->getSuggestion();
            $initialQuery = $searchRequest->getRawUserQuery();

            $searchRequest->setRawQueryString($correction);
            $searchResultSet = $this->search($searchRequest);
            if ($searchResultSet->getAllResultCount() > 0) {
                $searchResultSet->setIsAutoCorrected(true);
                $searchResultSet->setCorrectedQueryString($correction);
                $searchResultSet->setInitialQueryString($initialQuery);
                break;
            }

            if ($runs > $maximumRuns) {
                break;
            }
        }
        return $searchResultSet;
    }

    /**
     * Allows to modify a query before eventually handing it over to Solr.
     *
     * @param Query $query The current query before it's being handed over to Solr.
     * @param SearchRequest $searchRequest The searchRequest, relevant in the current context
     * @param Search $search The search, relevant in the current context
     *
     * @return Query The modified query that is actually going to be given to Solr.
     *
     * @throws UnexpectedValueException
     */
    protected function modifyQuery(Query $query, SearchRequest $searchRequest, Search $search): Query
    {
        // hook to modify the search query
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['modifySearchQuery'] ?? null)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['modifySearchQuery'] as $classReference) {
                $queryModifier = GeneralUtility::makeInstance($classReference);

                if ($queryModifier instanceof Modifier) {
                    if ($queryModifier instanceof SearchAware) {
                        $queryModifier->setSearch($search);
                    }

                    if ($queryModifier instanceof SearchRequestAware) {
                        $queryModifier->setSearchRequest($searchRequest);
                    }

                    $query = $queryModifier->modifyQuery($query);
                } else {
                    throw new UnexpectedValueException(
                        get_class($queryModifier) . ' must implement interface ' . Modifier::class,
                        1310387414
                    );
                }
            }
        }

        return $query;
    }

    /**
     * Retrieves a single document from solr by document id.
     */
    public function getDocumentById(string $documentId): SearchResult
    {
        /* @var SearchQuery $query */
        $query = $this->queryBuilder->newSearchQuery($documentId)->useQueryFields(QueryFields::fromString('id'))->getQuery();
        $response = $this->search->search($query, 0, 1);
        $parsedData = $response->getParsedData();
        // @extensionScannerIgnoreLine
        $resultDocument = $parsedData->response->docs[0] ?? null;

        if (!$resultDocument instanceof Document) {
            throw new UnexpectedValueException('Response did not contain a valid Document object');
        }

        return $this->searchResultBuilder->fromApacheSolrDocument($resultDocument);
    }

    /**
     * This method is used to call the registered hooks during the search execution.
     */
    private function handleSearchHook(string $eventName, SearchResultSet $resultSet): SearchResultSet
    {
        if (!is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr'][$eventName] ?? null)) {
            return $resultSet;
        }

        foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr'][$eventName] as $classReference) {
            $afterSearchProcessor = GeneralUtility::makeInstance($classReference);
            if ($afterSearchProcessor instanceof SearchResultSetProcessor) {
                $afterSearchProcessor->process($resultSet);
            }
        }

        return $resultSet;
    }

    public function getLastResultSet(): ?SearchResultSet
    {
        return $this->lastResultSet;
    }

    /**
     * This method returns true when the last search was executed with an empty query
     * string or whitespaces only. When no search was triggered it will return false.
     */
    public function getLastSearchWasExecutedWithEmptyQueryString(): bool
    {
        $wasEmptyQueryString = false;
        if ($this->lastResultSet != null) {
            $wasEmptyQueryString = $this->lastResultSet->getUsedSearchRequest()->getRawUserQueryIsEmptyString();
        }

        return $wasEmptyQueryString;
    }

    protected function getInitialSearchIsConfigured(): bool
    {
        return $this->typoScriptConfiguration->getSearchInitializeWithEmptyQuery() || $this->typoScriptConfiguration->getSearchShowResultsOfInitialEmptyQuery() || $this->typoScriptConfiguration->getSearchInitializeWithQuery() || $this->typoScriptConfiguration->getSearchShowResultsOfInitialQuery();
    }

    /**
     * Returns registered search components
     */
    protected function getRegisteredSearchComponents(): array
    {
        return GeneralUtility::makeInstance(SearchComponentManager::class)->getSearchComponents();
    }
}
