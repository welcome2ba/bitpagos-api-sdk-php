<?php

namespace BitPagos\Core;

use BitPagos\Exception\BitPagosConfigurationException;
use BitPagos\Exception\BitPagosConnectionException;

/**
 * A wrapper class based on the curl extension.
 * Requires the PHP curl module to be enabled.
 * See for full requirements the PHP manual: http://php.net/curl
 */
class HttpConnection
{
	public $httpStatus = 0;
	public $requestHeaders = null;
	public $responseHeaders = null;
	public $responseBody = null;

	/**
	 *
	 * @var HttpConfig
	 */
	private $httpConfig;

	/**
	 * HTTP status codes for which a retry must be attempted
	 * retry is currently attempted for Request timeout, Bad Gateway,
	 * Service Unavailable and Gateway timeout errors.
	 */
	private static $retryCodes = array('408', '502', '503', '504');

	/**
	 * LoggingManager
	 *
	 * @var LoggingManager
	 */
	private $logger;

	/**
	 * Default Constructor
	 *
	 * @param HttpConfig $httpConfig
	 * @param array $config
	 * @throws BitPagosConfigurationException
	 */
	public function __construct(HttpConfig $httpConfig, array $config)
	{
		if (! function_exists( "curl_init" ))
		{
			throw new BitPagosConfigurationException( "Curl module is not available on this system" );
		}
		$this->httpConfig = $httpConfig;
		$this->logger = LoggingManager::getInstance( __CLASS__ );
	}

	/**
	 * Gets all Http Headers
	 *
	 * @return array
	 */
	private function getHttpHeaders()
	{
		$ret = array();
		foreach ( $this->httpConfig->getHeaders() as $k => $v )
		{
			$ret[] = "$k: $v";
		}
		return $ret;
	}

	/**
	 * Executes an HTTP request
	 *
	 * @param string $data
	 *        	query string OR POST content as a string
	 * @return mixed
	 * @throws BitPagosConnectionException
	 */
	public function execute($data)
	{
		//Initialize the logger
		$this->logger->info( $this->httpConfig->getMethod() . ' ' . $this->httpConfig->getUrl() );

		//Initialize Curl Options
		$ch = curl_init( $this->httpConfig->getUrl() );
		curl_setopt_array( $ch, $this->httpConfig->getCurlOptions() );
		curl_setopt( $ch, CURLOPT_URL, $this->httpConfig->getUrl() );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLINFO_HEADER_OUT, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->getHttpHeaders() );

		//Determine Curl Options based on Method
		switch ( $this->httpConfig->getMethod() )
		{
			case 'POST' :
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
				break;
			case 'PUT' :
			case 'PATCH' :
			case 'DELETE' :
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
				break;
		}

		//Default Option if Method not of given types in switch case
		if ($this->httpConfig->getMethod() != NULL)
		{
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $this->httpConfig->getMethod() );
		}

		//Logging Each Headers for debugging purposes
		foreach ( $this->getHttpHeaders() as $header )
		{
			//TODO: Strip out credentials and other secure info when logging.
			// $this->logger->debug($header);
		}

		//Execute Curl Request
		$result = curl_exec( $ch );

		//Retrieve Response Status
		$this->httpStatus = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		//Retry if Certificate Exception
		if (curl_errno( $ch ) == 60)
		{
			$this->logger->info( "Invalid or no certificate authority found - Retrying using bundled CA certs file" );
			curl_setopt( $ch, CURLOPT_CAINFO, dirname( __FILE__ ) . '/cacert.pem' );
			$result = curl_exec( $ch );
			//Retrieve Response Status
			$this->httpStatus = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		}

		//Retry if Failing
		$retries = 0;
		if (in_array( $this->httpStatus, self::$retryCodes ) && $this->httpConfig->getHttpRetryCount() != null)
		{
			$this->logger->info( "Got {$this->httpStatus} response from server. Retrying" );
			do
			{
				$result = curl_exec( $ch );
				//Retrieve Response Status
				$this->httpStatus = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			}
			while ( in_array( $this->httpStatus, self::$retryCodes ) && ( ++ $retries < $this->httpConfig->getHttpRetryCount() ) );
		}

		//Throw Exception if Retries and Certificates doenst work
		if (curl_errno( $ch ))
		{
			$ex = new BitPagosConnectionException( $this->httpConfig->getUrl(), curl_error( $ch ), curl_errno( $ch ) );
			curl_close( $ch );
			throw $ex;
		}

		// Get Request and Response Headers
		$this->requestHeaders = curl_getinfo( $ch, CURLINFO_HEADER_OUT );
		//Using alternative solution to CURLINFO_HEADER_SIZE as it throws invalid number when called using PROXY.
		$responseHeaderSize = strlen( $result ) - curl_getinfo( $ch, CURLINFO_SIZE_DOWNLOAD );
		$this->responseHeaders = substr( $result, 0, $responseHeaderSize );
		$result = substr( $result, $responseHeaderSize );

		$this->logger->debug( "Request Headers \t: " . str_replace( "\r\n", ", ", $this->requestHeaders ) );
		$this->logger->debug( ( $data && $data != '' ? "Request Data\t\t: " . $data : "No Request Payload" ) . "\n" . str_repeat( '-', 128 ) . "\n" );
		$this->logger->info( "Response Status \t: " . $this->httpStatus );
		$this->logger->debug( "Response Headers\t: " . str_replace( "\r\n", ", ", $this->responseHeaders ) );

		//Close the curl request
		curl_close( $ch );

		//More Exceptions based on HttpStatus Code
		if (in_array( $this->httpStatus, self::$retryCodes ))
		{
			$ex = new BitPagosConnectionException( $this->httpConfig->getUrl(), "Got Http response code {$this->httpStatus} when accessing {$this->httpConfig->getUrl()}. " . "Retried $retries times." );
			$ex->setData( $result );
			$this->logger->error( "Got Http response code {$this->httpStatus} when accessing {$this->httpConfig->getUrl()}. " . "Retried $retries times." . $result );
			$this->logger->debug( "\n\n" . str_repeat( '=', 128 ) . "\n" );
			throw $ex;
		}
		else if ($this->httpStatus < 200 || $this->httpStatus >= 300)
		{
			$ex = new BitPagosConnectionException( $this->httpConfig->getUrl(), "Got Http response code {$this->httpStatus} when accessing {$this->httpConfig->getUrl()}.", $this->httpStatus );
			$ex->setData( $result );
			$this->logger->error( "Got Http response code {$this->httpStatus} when accessing {$this->httpConfig->getUrl()}. " . $result );
			$this->logger->debug( "\n\n" . str_repeat( '=', 128 ) . "\n" );
			throw $ex;
		}

		$this->logger->debug( ( $result && $result != '' ? "Response Data \t: " . $result : "No Response Body" ) . "\n\n" . str_repeat( '=', 128 ) . "\n" );

		//Return result object
		$this->responseBody = &$result;
		return $result;
	}
}
