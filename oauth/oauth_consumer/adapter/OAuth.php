<?php
/**
 * Lithium OAuth Plugin
 *
 * @copyright     Copyright 2012, PixelCog Inc. (http://pixelcog.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_oauth2\oauth\oauth_consumer\adapter;

use lithium\util\Inflector;

/**
 * A remote authorization adapter for the OAuth 1.0a Spec.
 *
 * This adapter provides support for the full request-response procedure of token exchanges
 * and request signing for remote access to OAuth provisioned resources.
 *
 */
class OAuth extends \lithium\core\Object {

	/**
	 * Default settings for this adapter.  To be used also when initializing the OAuth service layer
	 *
	 * @see li3_oauth2\extensions\net\http\OAuthService
	 * @var array Default settings for paths and OAuth credentials goes here
	 */
	protected $_defaults = array(
		'consumer_app_id' => 'app',
		'consumer_key' => 'key',
		'consumer_secret' => 'secret',
		'request_token' => '/oauth/get_request_token',
		'access_token' => '/oauth/get_token',
		'authorize' => '/oauth/request_auth'
	);

	/**
	 * Holds an instance of the oauth service class
	 *
	 * @see \li3_oauth2\extensions\services\Oauth
	 */
	protected $_service = null;

	/**
	 * Holds the location of our OAuth service class.
	 *
	 * @var array
	 */
	protected $_classes = array(
		'service' => '\li3_oauth2\extensions\net\http\OAuthService'
	);

	/**
	 * Class constructor.
	 *
	 * Initialize and configure the OAuth service layer.  See OAuth service layer for additional
	 * configuration options (socket, request params, base url, etc).
	 *
	 * @see lithium\net\http\Service\OAuthService
	 * @param array $config
	 *              - consumer_app_id: application id from oauth service provider
	 *              - consumer_key: key from oauth service provider
	 *              - consumer_secret: secret from oauth service provider
	 *              - request_token: path to request token url
	 *              - access_token: path to access token url
	 *              - authorize: path to authorize  url
	 *
	 * @return void
	 */
	public function __construct(array $config = array()) {
		parent::__construct($config + $this->_defaults);
		$this->_service = new $this->_classes['service']($this->_config);
	}
	
	/**
	 * Check whether token has remote access conforming to requested authroization parameters.
	 *
	 * OAuth 1.0 spec does not specify a uniform way to test an access token for validity so all
	 * this function does is verify that expected parameters exist and the token is not expired.
	 *
	 * OAuth 1.0 also does not have any way to request specific permissions from the authorization
	 * provider, so we cannot verify them here either.
	 *
	 * @param array $token Reference to access token data to be updated as necessary.
	 * @param array $request Optional specification of access parameters to test against.
	 *        Formatted the same as the input to the `request` method.
	 * @return boolean Returns `true` if a the the token is valid, `false` otherwise.
	 */
	public function hasAccess(array $token, array $request = array()) {
		$defaults = array(
			'oauth_token' => '',
			'oauth_token_secret' => '',
			'auth_expires' => ''
		);
		$token += $defaults;
		
		if (!$token['oauth_token'] || !$token['oauth_token']) {
			return false;
		}
		
		if ($token['auth_expires'] && time() > $token['auth_expires']) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Request access from a remote authorization server.
	 *
	 * @param array $token Reference to access token data to update with our pending or complete
	 *        token information.
	 * @param array $request Optional specification of the following request parameters:
	 *              - callback: url to return following authentication (should call `verify`)
	 *              - nonce: a unique string to identify this request
	 *              - lang: preferred language of authentication prompt (i.e. 'en-us')
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return mixed Returns `true` if a the we have obtained an access token, or a url string if
	 *         the user is needed to interact with the remote authorization server, or false if
	 *         there an error in the request process.
	 */
	public function request(array &$token, array $request = array(), &$error = null) {
		$defaults = array('nonce' => '', 'callback' => '', 'lang' => '');
		$request += $defaults;
		$error = null;
		
		$oauth = array(
			'oauth_callback' => $request['callback'],
			'oauth_consumer_key' => $this->_config['consumer_key'],
			'oauth_nonce' => $request['nonce'] ?: sha1(time() . mt_rand()),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_version' => '1.0',
			'xoauth_lang_pref' => $request['lang']
		);
		$oauth = array_filter($oauth);
		
		$sign = rawurlencode($this->_config['consumer_secret']) . '&';
		$return = 'token';
		
		$response = $this->_service->post('request_token', array(), compact('oauth', 'sign', 'return'));
		
		if (empty($response['data']['oauth_token'])) {
			$error = 'Unknown Error';
			if ($response['code'] != 200) {
				$error = 'Error '.$response['code'];
			}
			if (!empty($response['data']['oauth_problem'])) {
				$error .= ': '.Inflector::humanize($response['data']['oauth_problem']);
			}
			return false;
		}
		
		$token = $response['data'];
		
		if (!empty($token['oauth_expires_in'])) {
			$token['expires'] = time() + (integer) $token['oauth_expires_in'];
		}
		
		if (!empty($token['xoauth_request_auth_url'])) {
			return $token['xoauth_request_auth_url'];
		}
		
		return $this->_service->url('authorize', array('oauth_token' => $token['oauth_token']));
	}
	
	/**
	 * Exchange request token data for an access token.
	 *
	 * @param array $token Reference to access token data to be updated as necessary.
	 * @param array $response Data returned from the remote authentication server via the user
	 *              agent. This should include an `oauth_verifier` parameter if using OAuth 1.0a.
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return boolean Returns `true` if a the processes was successful, `false` otherwise.
	 */
	public function verify(array &$token, array $response = array(), &$error = null) {
		$defaults = array('oauth_token' => '', 'oauth_verifier' => '', 'oauth_token_secret' => '');
		$response += $defaults;
		$request = $token + $defaults;
		$error = null;
		
		if ($response['oauth_token'] && $response['oauth_token'] != $token['oauth_token']) {
			$error = "Mismatching request token.";
			return false;
		}
		
		$oauth = array(
			'oauth_consumer_key' => $this->_config['consumer_key'],
			'oauth_nonce' => sha1(time() . mt_rand()),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_token' => $request['oauth_token'],
			'oauth_verifier' => $response['oauth_verifier'],
			'oauth_version' => '1.0'
		);
		$oauth = array_filter($oauth);
		
		$sign =
			rawurlencode($this->_config['consumer_secret']) . '&' .
			rawurlencode($request['oauth_token_secret']);
		$return = 'token';
		
		$response = $this->_service->post('access_token', array(), compact('oauth', 'sign', 'return'));
		
		if (empty($response['data']['oauth_token'])) {
			$error = 'Unknown Error';
			if ($response['code'] != 200) {
				$error = 'Error '.$response['code'];
			}
			if (!empty($response['data']['oauth_problem'])) {
				$error .= ': '.Inflector::humanize($response['data']['oauth_problem']);
			}
			return false;
		}
		
		$token = $response['data'];
		
		if (!empty($token['oauth_expires_in'])) {
			$token['expires'] = time() + (integer) $token['oauth_expires_in'];
		}
		if (!empty($token['oauth_authorization_expires_in'])) {
			$token['auth_expires'] = time() + (integer) $token['oauth_authorization_expires_in'];
		}
		
		return true;
	}

	/**
	 * Check the time at which our access credentials must be renewed.
	 *
	 * @param array $token Access token data to check for expiration.
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return integer Returns a Unix epoch timestamp at which the token must be renewed, or
	 *         `null` if the token doesn't expire, or `false` on error.
	 */
	public function expires(array $token, &$error = null) {
		if (!empty($token['expires'])) {
			return $token['expires'];
		}
		return null;
	}
	
	/**
	 * Refresh an access token which is past or near expiration.  Assumes the appropriate measures
	 * have been taken to block other access the token resource if race conditions are a concern.
	 *
	 * @param array $token Reference to access token data to be updated as necessary.
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return boolean Returns `true` if a the processes was successful, `false` otherwise.
	 */
	public function refresh(array &$token, &$error = null) {
		$defaults = array('oauth_token' => '', 'oauth_session_handle' => '', 'oauth_token_secret' => '');
		$request = $token + $defaults;
		$error = null;
		
		$oauth = array(
			'oauth_consumer_key' => $this->_config['consumer_key'],
			'oauth_nonce' => sha1(time() . mt_rand()),
			'oauth_session_handle' => $request['oauth_session_handle'],
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_token' => $request['oauth_token'],
			'oauth_version' => '1.0'
		);
		$oauth = array_filter($oauth);
		
		$sign =
			rawurlencode($this->_config['consumer_secret']) . '&' .
			rawurlencode($request['oauth_token_secret']);
		$return = 'token';
		
		$response = $this->_service->post('access_token', array(), compact('oauth', 'sign', 'return'));
		
		if (empty($response['data']['oauth_token'])) {
			$error = 'Unknown Error';
			if ($response['code'] != 200) {
				$error = 'Error '.$response['code'];
			}
			if (!empty($response['data']['oauth_problem'])) {
				$error .= ': '.Inflector::humanize($response['data']['oauth_problem']);
			}
			return false;
		}
		
		$token = $response['data'];
		
		if (!empty($token['oauth_expires_in'])) {
			$token['expires'] = time() + (integer) $token['oauth_expires_in'];
		}
		if (!empty($token['oauth_authorization_expires_in'])) {
			$token['auth_expires'] = time() + (integer) $token['oauth_authorization_expires_in'];
		}
		
		return true;
	}
	
	/**
	 * No method for releasing authorization on a given resource exists in the OAuth 1.0 spec.
	 * Simply return true.
	 *
	 * @param array $token Reference to access token data to be updated as necessary.
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return boolean Returns `true` if a the processes was successful, `false` otherwise.
	 */
	public function release(array &$token, &$error = null) {
		return true;
	}
	
	/**
	 * Refresh an access token which is past or near expiration.  Assumes the appropriate measures
	 * have been taken to block other access the token resource if race conditions are a concern.
	 *
	 * @param array $token Reference to access token data to be updated as necessary.
	 * @param string $error Optional reference to variable in which to place error information.
	 * @return boolean Returns `true` if a the processes was successful, `false` otherwise.
	 */
	public function access($method, array $token, $path = null, array $data = array(), &$error = null) {
		$defaults = array('oauth_token' => '', 'oauth_token_secret' => '');
		$token += $defaults;
		$error = null;
		
		$oauth = array(
			'oauth_consumer_key' => $this->_config['consumer_key'],
			'oauth_nonce' => sha1(time() . mt_rand()),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_token' => $token['oauth_token'],
			'oauth_version' => '1.0'
		);
		$oauth = array_filter($oauth);
		
		$sign =
			rawurlencode($this->_config['consumer_secret']) . '&' .
			rawurlencode($token['oauth_token_secret']);
		$return = 'response';
		
		$response = $this->_service->send($method, $path, $data, compact('oauth', 'sign', 'return'));
		
		if ($response->status['code'] != 200) {
			$error = 'Error '.$response->status['code'];
			
			if (!empty($response->headers['WWW-Authenticate'])) {
				$error .= ' ('.$response->headers['WWW-Authenticate'].')';
			}
		}
		return $response->body();
	}
}

?>