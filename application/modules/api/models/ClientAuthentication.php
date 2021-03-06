<?php

/**
 * Authenticates clients that make requests to the BTS API. Almost all requests
 * contain the system name of the client and an HMAC signature of the request
 * (using the private API key as a key) which are verified in this class.
 *
 * @author	Frederick Ding
 * @version	$Id$
 * @package	Bts
 */
class Api_Model_ClientAuthentication
{

	/**
	 * An instance of the Clients model.
	 * 
	 * @var Bts_Model_Clients
	 */
	private $Clients = null;

	/**
	 * An instance of the Users model (lazy initialization).
	 * 
	 * @var Bts_Model_Users
	 */
	private $Users = null;

	/**
	 * Performs a few initialization actions: loads the database adapter from
	 * the resource stored in Zend_Registry.
	 */
	public function __construct ()
	{
		$this->Clients = new Bts_Model_Clients();
	}

	/**
	 * Retrieves a session ID if an active session exists for the given client
	 * and user.
	 * 
	 * @param int $client_id        	
	 * @param int $user_id        	
	 * @return string false ID if found or false
	 */
	private function _getSessionId ($client_id, $user_id)
	{
		$client_id = (int) $client_id;
		$user_id = (int) $user_id;
		$db = $this->Clients->getDb();
		$query = $db->select()
			->from('bts_sessions', 
				array(
						new Zend_Db_Expr('HEX(session_id)'),
						new Zend_Db_Expr('expire_time > UTC_TIMESTAMP()')
				))
			->where('client_id = ?', $client_id)
			->where('user_id = ?', $user_id) // ->where('expire_time >
			                                 // UTC_TIMESTAMP()')
			->limit(1)
			->query()
			->fetchObject();
		if ($query === false)
			return false;
			// if the session is already expired, delete it as a matter of
		// cleanup
		if ($query->{"expire_time > UTC_TIMESTAMP()"} == 0) {
			$db->delete('bts_sessions', 
					"client_id = $client_id AND user_id = $user_id");
			return false;
		} else {
			// otherwise just pass along the valid, existing session
			return $query->{"HEX(session_id)"}; // session ID
		}
	}

	/**
	 * Calls {@link Bts_Model_Clients::getClientStatus()}.
	 * 
	 * @param string|int $client        	
	 * @return int false code if found (1 is active, 0 is inactive) or false
	 * @see Bts_Model_Clients::getClientStatus()
	 */
	public function clientStatus ($client)
	{
		return $this->Clients->getClientStatus($client);
	}

	/**
	 * Terminates a given user authentication session given an existing session
	 * ID.
	 * 
	 * @param string $sessionId        	
	 * @param string $sysName        	
	 * @return int Number of affected rows
	 */
	public function destroySession ($sessionId, $sysName)
	{
		$db = $this->Clients->getDb();
		$delete = $db->query(
				'DELETE bts_sessions FROM bts_sessions NATURAL JOIN bts_clients' .
						 ' WHERE bts_sessions.session_id = UNHEX(?) AND bts_clients.sys_name = ?', 
						array(
								$sessionId,
								$sysName
						));
		return $delete->rowCount();
	}

	/**
	 * Generates a valid API request signature using the HMAC methodology using
	 * the specified request details.
	 *
	 * This can be considered a reference implementation for the BTS API
	 * signature
	 * specifications.
	 * 
	 * @param string $httpVerb        	
	 * @param string $hostname        	
	 * @param string $uri        	
	 * @param array $params        	
	 * @param string|null $apiKey
	 *        	(optional)
	 * @param boolean $returnMessage
	 *        	(optional)
	 * @return string
	 * @throws Bts_Exception
	 */
	public function generateSignature ($httpVerb, $hostname, $uri, array $params, 
			$apiKey = null, $returnMessage = false)
	{
		// validate the HTTP verb
		$httpVerb = trim(strtoupper($httpVerb));
		if (! in_array($httpVerb, array(
				'GET',
				'POST'
		)))
			throw new Bts_Exception('Invalid HTTP verb in generateSignature()');
			// validate the sysName
		if (! isset($params['sysName'])) {
			throw new Bts_Exception('No sysName provided', 
					Bts_Exception::AUTH_SYSNAME_MISSING);
		}
		// create a message to sign with HMAC
		$stringToSign = $httpVerb . ' ';
		$stringToSign .= $hostname . $uri . "\n";
		// parameters in key => value format must be in alpha order
		unset($params['signature']);
		ksort($params);
		// use the sysName in the params to get the API key if it is not
		// specified
		if (is_null($apiKey)) {
			$apiKey = $this->Clients->getApiKey($params['sysName']);
			if ($apiKey === false) {
				throw new Bts_Exception('Invalid sysName', 
						Bts_Exception::AUTH_SYSNAME_BAD);
			}
		}
		// concatenate the parameters to the HMAC message
		reset($params);
		$stringToSign .= http_build_query($params);
		$stringToSign = str_replace('+', '%20', $stringToSign);
		if ($returnMessage) {
			return $stringToSign;
		}
		// generate a HMAC hash using the API key as key, encoded using base64
		$digest = base64_encode(hash_hmac('sha1', $stringToSign, $apiKey, true));
		return $digest;
	}

	/**
	 * Retrieves the user associated with an extant session based on the
	 * session's
	 * ID and the client name.
	 * 
	 * @param string $sessionId        	
	 * @param string $sysName        	
	 * @param boolean $userId
	 *        	(optional) True to retrieve the user ID or false for the
	 *        	username
	 * @return mixed
	 */
	public function getSessionUser ($sessionId, $sysName, $userId = true)
	{
		if (empty($sessionId) || empty($sysName))
			return false;
		$database = $this->Clients->getDb();
		$select = $database->select()
			->from('bts_sessions', 
				array(
						'HEX(session_id)',
						'client_id',
						'user_id'
				))
			->joinNatural('bts_clients', 'sys_name')
			->where('session_id = UNHEX(?)', $sessionId)
			->where('sys_name = ?', $sysName)
			->limit(1);
		if ($userId == false) {
			$select->joinNatural('bts_users', 'username');
		}
		$row = $select->query(Zend_Db::FETCH_ASSOC)->fetch();
		if ($row === false)
			return false;
		if ($userId) {
			return $row['user_id'];
		} else {
			return $row['username'];
		}
	}

	/**
	 * Starts a session for the given user and sysName and returns the session
	 * ID
	 * of the new session; uses a few extra queries to get existing valid
	 * sessions
	 * before creating a new session.
	 * 
	 * @param string $username        	
	 * @param string $password        	
	 * @param string $sysName        	
	 * @param Bts_Model_Users $Users
	 *        	(optional)
	 * @throws Bts_Exception
	 */
	public function startSession ($username, $password, $sysName, 
			Bts_Model_Users $Users = null)
	{
		// a simple mechanism to use the given Users model if provided;
		// caches a model in $this->Users
		if (is_null($this->Users) && ! is_null($Users)) {
			$this->Users = $Users;
		} else 
			if (is_null($this->Users) && is_null($Users)) {
				$this->Users = new Bts_Model_Users();
				$Users = $this->Users;
			} else 
				if (! is_null($this->Users) && is_null($Users)) {
					$Users = $this->Users;
				}
		// return with an empty string if the password is invalid
		if (! $Users->checkPassword($username, $password)) {
			return '';
		}
		$client_id = $this->Clients->getClientId($sysName);
		$user_id = $Users->getUserId($username);
		$db = $this->Clients->getDb();
		// try to fetch an existing session ID
		$id = $this->_getSessionId($client_id, $user_id);
		if ($id !== FALSE)
			return $id;
			// if that didn't work out, let's create a new session
		$query = $db->insert('bts_sessions', 
				array(
						'session_id' => new Zend_Db_Expr(
								'UNHEX(SHA1(CONCAT_WS("-", ' . $client_id . ', ' .
										 $user_id . ', UTC_TIMESTAMP())))'),
						'client_id' => $client_id,
						'user_id' => $user_id,
						'expire_time' => new Zend_Db_Expr(
								'UTC_TIMESTAMP() + INTERVAL 2 HOUR')
				));
		$id = $db->select()
			->from('bts_sessions', new Zend_Db_Expr('HEX(session_id)'))
			->where('client_id = ?', $client_id)
			->where('user_id = ?', $user_id)
			->limit(1)
			->query()
			->fetchColumn();
		if ($id === FALSE) {
			// something went massively wrong if we can't get the session ID
			// that was JUST generated.
			throw new Bts_Exception('Cannot create session', 
					Bts_Exception::AUTH_SESSION_FAILURE);
		}
		return $id;
	}

	/**
	 * Checks whether a session, identified by its session ID and the sysName
	 * of the client making the request, is a valid session.
	 * 
	 * @param string $sessionId        	
	 * @param string $sysName        	
	 * @return boolean
	 */
	public function validateSession ($sessionId, $sysName)
	{
		$db = $this->Clients->getDb();
		$select = $db->select()
			->from('bts_sessions', 'COUNT(*)')
			->where('expire_time > UTC_TIMESTAMP()')
			->where('session_id = UNHEX(?)', $sessionId)
			->joinNatural('bts_clients', array(
				'sys_name'
		))
			->where('sys_name = ?', $sysName);
		return (bool) $select->query()->fetchColumn();
	}

	/**
	 * Validates a given signature by comparing it with a signature generated by
	 * our reference implementation.
	 * 
	 * @param string $httpVerb        	
	 * @param string $hostname        	
	 * @param string $uri        	
	 * @param array $params        	
	 * @return boolean
	 * @see generateSignature()
	 */
	public function validateSignature ($httpVerb, $hostname, $uri, array $params)
	{
		try {
			$generated = $this->generateSignature($httpVerb, $hostname, $uri, 
					$params);
		} catch (Bts_Exception $e) {
			return false;
		}
		return ($generated == $params['signature']);
	}

	/**
	 * Checks the timestamp supplied with a request to see whether it is within
	 * 15 minutes to prevent replay attacks.
	 *
	 * On PHP 5.3.0 and higher this method will use the native DateTime classes,
	 * but older versions of PHP do not have the {@link DateTime::diff()}
	 * method,
	 * so we fall back to using MySQL for older versions of PHP.
	 * 
	 * @param string|long $timestamp        	
	 * @return boolean
	 * @uses DateTime
	 */
	public function validateTimestamp ($timestamp = 0)
	{
		if (! is_numeric($timestamp) || $timestamp == 0)
			return false;
			// valid timestamps are less than 15 minutes from current GMT time
			// with PHP 5.3+, we can use native classes to calculate date/time.
		if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
			// benchmarking shows this to be 4x faster than executing a SQL
			// query for the same calculation despite the heavy OOP
			$utc = new DateTimeZone('UTC');
			$now = new DateTime(null, $utc);
			$given = DateTime::createFromFormat('YmdHis', $timestamp, $utc);
			$interval = $now->diff($given, true);
			return ($interval->days == 0 && $interval->h == 0 &&
					 $interval->i < 15);
		} else {
			// if we're stuck with older versions of PHP5, we can still use
			// MySQL
			// ABS(TIMESTAMPDIFF(MINUTE, ********* , UTC_TIMESTAMP())) < 15
			$query = $this->Clients->getDb()
				->select()
				->from('', 
					new Zend_Db_Expr(
							'ABS(TIMESTAMPDIFF(MINUTE, ' . $timestamp .
									 ' , UTC_TIMESTAMP())) < 15'))
				->query()
				->fetchColumn();
			return ($query == 1);
		}
	}
}