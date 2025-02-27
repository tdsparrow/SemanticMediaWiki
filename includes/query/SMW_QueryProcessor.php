<?php

use ParamProcessor\Options;
use ParamProcessor\Param;
use ParamProcessor\ParamDefinition;
use ParamProcessor\Processor;
use SMW\ApplicationFactory;
use SMW\Message;
use SMW\Parser\RecursiveTextProcessor;
use SMW\Query\Deferred;
use SMW\Query\PrintRequest;
use SMW\Query\Processor\ParamListProcessor;
use SMW\Query\Processor\DefaultParamDefinition;
use SMW\Query\QueryContext;
use SMW\Query\Exception\ResultFormatNotFoundException;
use SMW\Query\ResultFormat;

/**
 * This file contains a static class for accessing functions to generate and execute
 * semantic queries and to serialise their results.
 *
 * @ingroup SMWQuery
 * @author Markus Krötzsch
 */

/**
 * Static class for accessing functions to generate and execute semantic queries
 * and to serialise their results.
 * @ingroup SMWQuery
 */
class SMWQueryProcessor implements QueryContext {

	/**
	 * @var RecursiveTextProcessor
	 */
	private static $recursiveTextProcessor;

	/**
	 * @since 3.0
	 *
	 * @param RecursiveTextProcessor|null $recursiveTextProcessor
	 */
	public static function setRecursiveTextProcessor( RecursiveTextProcessor $recursiveTextProcessor = null ) {
		self::$recursiveTextProcessor = $recursiveTextProcessor;
	}

	/**
	 * Takes an array of unprocessed parameters, processes them using
	 * Validator, and returns them.
	 *
	 * Both input and output arrays are
	 * param name (string) => param value (mixed)
	 *
	 * @since 1.6.2
	 * The return value changed in SMW 1.8 from an array with result values
	 * to an array with Param objects.
	 *
	 * @param array $params
	 * @param array $printRequests
	 * @param boolean $unknownInvalid
	 *
	 * @return Param[]
	 */
	public static function getProcessedParams( array $params, array $printRequests = [], $unknownInvalid = true, $context = null, $showMode = false ) {
		$validator = self::getValidatorForParams( $params, $printRequests, $unknownInvalid, $context, $showMode );
		$validator->processParameters();
		$parameters =  $validator->getParameters();

		// Negates some weird behaviour of ParamDefinition::setDefault used in
		// an individual printer.
		// Applying $smwgQMaxLimit, $smwgQMaxInlineLimit will happen at a later
		// stage.
		if ( isset( $params['limit'] ) && isset( $parameters['limit'] ) ) {
			$parameters['limit']->setValue( (int)$params['limit'] );
		}

		return $parameters;
	}

	/**
	 * Parse a query string given in SMW's query language to create
	 * an SMWQuery. Parameters are given as key-value-pairs in the
	 * given array. The parameter $context defines in what context the
	 * query is used, which affects ceretain general settings.
	 * An object of type SMWQuery is returned.
	 *
	 * The format string is used to specify the output format if already
	 * known. Otherwise it will be determined from the parameters when
	 * needed. This parameter is just for optimisation in a common case.
	 *
	 * @param string $queryString
	 * @param array $params These need to be the result of a list fed to getProcessedParams
	 * @param $context
	 * @param string $format
	 * @param array $extraPrintouts
	 *
	 * @return SMWQuery
	 */
	static public function createQuery( $queryString, array $params, $context = self::INLINE_QUERY, $format = '', array $extraPrintouts = [], $contextPage = null ) {

		if ( $format === '' || is_null( $format ) ) {
			$format = $params['format']->getValue();
		}

		$defaultSort = 'ASC';

		if ( $format == 'count' ) {
			$queryMode = SMWQuery::MODE_COUNT;
		} elseif ( $format == 'debug' ) {
			$queryMode = SMWQuery::MODE_DEBUG;
		} else {
			$printer = self::getResultPrinter( $format, $context );
			$queryMode = $printer->getQueryMode( $context );
			$defaultSort = $printer->getDefaultSort();
		}

		// set mode, limit, and offset:
		$offset = 0;
		$limit = $GLOBALS['smwgQDefaultLimit'];

		if ( ( array_key_exists( 'offset', $params ) ) && ( is_int( $params['offset']->getValue() + 0 ) ) ) {
			$offset = $params['offset']->getValue();
		}

		if ( ( array_key_exists( 'limit', $params ) ) && ( is_int( trim( $params['limit']->getValue() ) + 0 ) ) ) {
			$limit = $params['limit']->getValue();

			// limit < 0: always show further results link only
			if ( ( trim( $params['limit']->getValue() ) + 0 ) < 0 ) {
				$queryMode = SMWQuery::MODE_NONE;
			}
		}

		// largest possible limit for "count", even inline
		if ( $queryMode == SMWQuery::MODE_COUNT ) {
			$offset = 0;
			$limit = $GLOBALS['smwgQMaxLimit'];
		}

		$queryCreator = ApplicationFactory::getInstance()->singleton( 'QueryCreator' );

		$params = [
			'extraPrintouts' => $extraPrintouts,
			'queryMode'   => $queryMode,
			'context'     => $context,
			'contextPage' => $contextPage,
			'offset'      => $offset,
			'limit'       => $limit,
			'source'      => $params['source']->getValue(),
			'mainLabel'   => $params['mainlabel']->getValue(),
			'sort'        => $params['sort']->getValue(),
			'order'       => $params['order']->getValue(),
			'defaultSort' => $defaultSort
		];

		return $queryCreator->create( $queryString, $params );
	}

	/**
	 * Add the subject print request, unless mainlabel is set to "-".
	 *
	 * @since 1.7
	 *
	 * @param array $printRequests
	 * @param array $rawParams
	 */
	public static function addThisPrintout( array &$printRequests, array $rawParams ) {

		if ( $printRequests === null ) {
			return;
		}

		// If THIS is already registered, bail-out!
		foreach ( $printRequests as $printRequest ) {
			if ( $printRequest->isMode( PrintRequest::PRINT_THIS ) ) {
				return;
			}
		}

		$hasMainlabel = array_key_exists( 'mainlabel', $rawParams );

		if  ( !$hasMainlabel || trim( $rawParams['mainlabel'] ) !== '-' ) {
			$printRequest = new PrintRequest(
				PrintRequest::PRINT_THIS,
				$hasMainlabel ? $rawParams['mainlabel'] : ''
			);

			// Signal to any post-processing that THIS was added outside of
			// the normal processing chain
			$printRequest->isDisconnected( true );

			array_unshift( $printRequests, $printRequest );
		}
	}

	/**
	 * Preprocess a query as given by an array of parameters as is typically
	 * produced by the #ask parser function. The parsing results in a querystring,
	 * an array of additional parameters, and an array of additional SMWPrintRequest
	 * objects, which are filled into call-by-ref parameters.
	 * $showMode is true if the input should be treated as if given by #show
	 *
	 * @param array $rawParams
	 * @param string $querystring
	 * @param array $params
	 * @param array $printouts array of SMWPrintRequest
	 * @param boolean $showMode
	 * @deprecated Will vanish after SMW 1.8 is released.
	 * Use getComponentsFromFunctionParams which has a cleaner interface.
	 */
	static public function processFunctionParams( array $rawParams, &$querystring, &$params, &$printouts, $showMode = false ) {
		list( $querystring, $params, $printouts ) = self::getComponentsFromFunctionParams( $rawParams, $showMode );
	}


	/**
	 * Preprocess a query as given by an array of parameters as is
	 * typically produced by the #ask parser function or by Special:Ask.
	 * The parsing results in a querystring, an array of additional
	 * parameters, and an array of additional SMWPrintRequest objects,
	 * which are returned in an array with three components. If
	 * $showMode is true then the input will be processed as if for #show.
	 * This uses a slightly different way to get the query, and different
	 * default labels (empty) for additional print requests.
	 *
	 * @param array $rawParams
	 * @param boolean $showMode
	 * @return array( string, array( string => string ), array( SMWPrintRequest ) )
	 */
	static public function getComponentsFromFunctionParams( array $rawParams, $showMode ) {

		$paramListProcessor = ApplicationFactory::getInstance()->singleton( 'ParamListProcessor' );

		return $paramListProcessor->format(
			$paramListProcessor->preprocess( $rawParams, $showMode ),
			ParamListProcessor::FORMAT_LEGACY
		);
	}

	/**
	 * Process and answer a query as given by an array of parameters as is
	 * typically produced by the #ask parser function. The parameter
	 * $context defines in what context the query is used, which affects
	 * certain general settings.
	 *
	 * The main task of this function is to preprocess the raw parameters
	 * to obtain actual parameters, printout requests, and the query string
	 * for further processing.
	 *
	 * @since 1.8
	 * @param array $rawParams user-provided list of unparsed parameters
	 * @param integer $outputMode SMW_OUTPUT_WIKI, SMW_OUTPUT_HTML, ...
	 * @param integer $context INLINE_QUERY, SPECIAL_PAGE, CONCEPT_DESC
	 * @param boolean $showMode process like #show parser function?
	 * @return array( SMWQuery, array of IParam )
	 */
	static public function getQueryAndParamsFromFunctionParams( array $rawParams, $outputMode, $context, $showMode, $contextPage = null ) {
		list( $queryString, $params, $printouts ) = self::getComponentsFromFunctionParams( $rawParams, $showMode );

		if ( !$showMode ) {
			self::addThisPrintout( $printouts, $params );
		}

		$params = self::getProcessedParams( $params, $printouts, true, $context, $showMode );

		$query  = self::createQuery( $queryString, $params, $context, '', $printouts, $contextPage );

		// For convenience keep parameters and options to be available for immediate
		// processing
		if ( $context === self::DEFERRED_QUERY ) {
			$query->setOption( Deferred::QUERY_PARAMETERS, implode( '|', $rawParams ) );
			$query->setOption( Deferred::SHOW_MODE, $showMode );
			$query->setOption( Deferred::CONTROL_ELEMENT, isset( $params['@control'] ) ? $params['@control']->getValue() : '' );
		}

		return [ $query, $params ];
	}

	/**
	 * Process and answer a query as given by an array of parameters as is
	 * typically produced by the #ask parser function. The result is formatted
	 * according to the specified $outputformat. The parameter $context defines
	 * in what context the query is used, which affects ceretain general settings.
	 *
	 * The main task of this function is to preprocess the raw parameters to
	 * obtain actual parameters, printout requests, and the query string for
	 * further processing.
	 *
	 * @note Consider using getQueryAndParamsFromFunctionParams() and
	 * getResultFromQuery() instead.
	 * @deprecated Will vanish after release of SMW 1.8.
	 * See SMW_Ask.php for example code on how to get query results from
	 * #ask function parameters.
	 */
	static public function getResultFromFunctionParams( array $rawParams, $outputMode, $context = self::INLINE_QUERY, $showMode = false ) {
		list( $queryString, $params, $printouts ) = self::getComponentsFromFunctionParams( $rawParams, $showMode );

		if ( !$showMode ) {
			self::addThisPrintout( $printouts, $params );
		}

		$params = self::getProcessedParams( $params, $printouts );

		return self::getResultFromQueryString( $queryString, $params, $printouts, SMW_OUTPUT_WIKI, $context );
	}

	/**
	 * Process a query string in SMW's query language and return a formatted
	 * result set as specified by $outputmode. A parameter array of key-value-pairs
	 * constrains the query and determines the serialisation mode for results. The
	 * parameter $context defines in what context the query is used, which affects
	 * certain general settings. Finally, $extraprintouts supplies additional
	 * printout requests for the query results.
	 *
	 * @param string $queryString
	 * @param array $params These need to be the result of a list fed to getProcessedParams
	 * @param $extraPrintouts
	 * @param $outputMode
	 * @param $context
	 *
	 * @return string
	 * @deprecated Will vanish after release of SMW 1.8.
	 * See SMW_Ask.php for example code on how to get query results from
	 * #ask function parameters.
	 */
	static public function getResultFromQueryString( $queryString, array $params, $extraPrintouts, $outputMode, $context = self::INLINE_QUERY ) {

		$query  = self::createQuery( $queryString, $params, $context, '', $extraPrintouts );
		$result = self::getResultFromQuery( $query, $params, $outputMode, $context );


		return $result;
	}

	/**
	 * Create a fully formatted result string from a query and its
	 * parameters. The method takes care of processing various types of
	 * query result. Most cases are handled by printers, but counting and
	 * debugging uses special code.
	 *
	 * @param SMWQuery $query
	 * @param array $params These need to be the result of a list fed to getProcessedParams
	 * @param integer $outputMode
	 * @param integer $context
	 * @since before 1.7, but public only since 1.8
	 *
	 * @return string
	 */
	public static function getResultFromQuery( SMWQuery $query, array $params, $outputMode, $context ) {

		$printer = self::getResultPrinter(
			$params['format']->getValue(),
			$context
		);

		if ( $printer->isDeferrable() && $context === self::DEFERRED_QUERY && $query->getLimit() > 0 ) {

			// Halt processing that is not `DEFERRED_DATA` as it is expected the
			// process is picked-up by the `deferred.js` loader that will
			// initiate an API request to finalize the query after MW has build
			// the page.
			if ( $printer->isDeferrable() !== $printer::DEFERRED_DATA ) {
				return Deferred::buildHTML( $query );
			}

			// `DEFERRED_DATA` is interpret as "execute the query with limit=0" (i.e.
			// no query execution) but allow the printer to setup the HTML so that
			// the data can be loaded after MW has finished the page build including
			// the pre-rendered query HTML representation. This mode deferrers the
			// actual query execution and data load to after the page build.
			//
			// Each printer that uses this mode has to handle the required parameters
			// and data load accordingly.
			$query->querymode = SMWQuery::MODE_INSTANCES;
			$query->setOption( 'deferred.limit', $query->getLimit() );
			$query->setLimit( 0 );
		}

		$querySourceFactory = ApplicationFactory::getInstance()->getQuerySourceFactory();
		$source = $params['source']->getValue();

		if ( $source === '' && $context === self::CURTAILMENT_MODE ) {
			$querySource = $querySourceFactory->newSingleEntityQueryLookup();
		} else {
			$querySource = $querySourceFactory->get( $source );
		}

		$res = $querySource->getQueryResult( $query );
		$start = microtime( true );

		if ( $res instanceof SMWQueryResult && $query->getOption( 'calc.result_hash' ) ) {
			$query->setOption( 'result_hash', $res->getHash( SMWQueryResult::QUICK_HASH ) );
		}

		if ( ( $query->querymode == SMWQuery::MODE_INSTANCES ) ||
			( $query->querymode == SMWQuery::MODE_NONE ) ) {

			$result = $printer->getResult( $res, $params, $outputMode );

			$query->setOption( SMWQuery::PROC_PRINT_TIME, microtime( true ) - $start );
			return $result;
		} else { // result for counting or debugging is just a string or number

			if ( $res instanceof SMWQueryResult ) {
				$res = $res->getCountValue();
			}

			if ( is_numeric( $res ) ) {
				$res = strval( $res );
			}

			if ( is_string( $res ) ) {
				$result = str_replace( '_', ' ', $params['intro']->getValue() )
					. $res
					. str_replace( '_', ' ', $params['outro']->getValue() )
					. smwfEncodeMessages( $query->getErrors() );
			} else {
				// When no valid result was obtained, $res will be a SMWQueryResult.
				$result = smwfEncodeMessages( $query->getErrors() );
			}

			$query->setOption( SMWQuery::PROC_PRINT_TIME, microtime( true ) - $start );

			return $result;
		}
	}

	/**
	 * Find suitable SMWResultPrinter for the given format. The context in
	 * which the query is to be used determines some basic settings of the
	 * returned printer object. Possible contexts are
	 * SMWQueryProcessor::SPECIAL_PAGE, SMWQueryProcessor::INLINE_QUERY,
	 * SMWQueryProcessor::CONCEPT_DESC.
	 *
	 * @param string $format
	 * @param $context
	 *
	 * @return SMWResultPrinter
	 * @throws MissingResultFormatException
	 */
	static public function getResultPrinter( $format, $context = self::SPECIAL_PAGE ) {
		global $smwgResultFormats;

		ResultFormat::resolveFormatAliases( $format );

		if ( !array_key_exists( $format, $smwgResultFormats ) ) {
			throw new ResultFormatNotFoundException( "There is no result format for '$format'." );
		}

		$formatClass = $smwgResultFormats[$format];

		$printer = new $formatClass( $format, ( $context != self::SPECIAL_PAGE ) );

		if ( self::$recursiveTextProcessor === null ) {
			self::$recursiveTextProcessor = new RecursiveTextProcessor();
		}

		$printer->setRecursiveTextProcessor(
			self::$recursiveTextProcessor
		);

		return $printer;
	}

	/**
	 * Produces a list of default allowed parameters for a result printer. Most
	 * query printers should override this function.
	 *
	 * @since 1.6.2, return element type changed in 1.8
	 *
	 * @param integer|null $context
	 * @param ResultPrinter|null $resultPrinter
	 *
	 * @return IParamDefinition[]
	 */
	public static function getParameters( $context = null, $resultPrinter = null ) {
		return DefaultParamDefinition::getParamDefinitions( $context, $resultPrinter );
	}

	/**
	 * Returns the definitions of all parameters supported by the specified format.
	 *
	 * @since 1.8
	 *
	 * @param string $format
	 *
	 * @return ParamDefinition[]
	 */
	public static function getFormatParameters( $format ) {
		ResultFormat::resolveFormatAliases( $format );

		if ( !array_key_exists( $format, $GLOBALS['smwgResultFormats'] ) ) {
			return [];
		}

		$resultPrinter = self::getResultPrinter( $format );

		if ( $resultPrinter instanceof \SMW\Query\ResultPrinters\NullResultPrinter ) {
			return [];
		}

		return ParamDefinition::getCleanDefinitions(
			$resultPrinter->getParamDefinitions( self::getParameters( null, $resultPrinter ) )
		);
	}

	/**
	 * Takes an array of unprocessed parameters,
	 * and sets them on a new Validator object,
	 * which is returned and ready to process the parameters.
	 *
	 * @since 1.8
	 *
	 * @param array $params
	 * @param array $printRequests
	 * @param boolean $unknownInvalid
	 *
	 * @return Processor
	 */
	private static function getValidatorForParams( array $params, array $printRequests = [], $unknownInvalid = true, $context = null, $showMode = false ) {
		$paramDefinitions = self::getParameters( $context );

		$paramDefinitions['format']->setPrintRequests( $printRequests );
		$paramDefinitions['format']->setShowMode( $showMode );

		$processorOptions = new Options();
		$processorOptions->setUnknownInvalid( $unknownInvalid );

		$validator = Processor::newFromOptions( $processorOptions );

		$validator->setParameters( $params, $paramDefinitions, false );

		return $validator;
	}

}
