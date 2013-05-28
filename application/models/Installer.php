<?php

/**
 * Installer model.
 *
 * Contains methods for setting up BTS server for the first time.
 *
 * @author	Frederick Ding
 * @package	Bts
 * @since 0.2.0
 */
class Bts_Model_Installer
{

	/**
	 *
	 * @var Zend_Config_Ini
	 */
	protected $BtsConfig = null;

	public $tests = array(
			'php-version' => array(
					'PHP version',
					PHP_VERSION,
					PHP_VERSION
			),
			'php-safe' => array(
					'PHP safe mode',
					'off',
					'on'
			),
			'php-pear' => array(
					'PEAR',
					'installed',
					'not installed'
			),
			'ext-hash' => array(
					'Hash extension',
					'supported',
					'not supported'
			),
			'ext-json' => array(
					'JSON extension',
					'supported',
					'not supported'
			),
			'ext-mcrypt' => array(
					'mcrypt extension',
					'supported',
					'not supported'
			),
			'ext-pdo' => array(
					'PHP Data Objects',
					'supported',
					'not supported'
			),
			'ext-pdomysql' => array(
					'PDO MySQL',
					'supported',
					'not supported'
			),
			'ext-mysqli' => array(
					'MySQLi extension',
					'supported',
					'not supported'
			),
			'files-btsdist' => array(
					'bts.ini.dist file',
					'exists',
					'not found'
			),
			'files-dbdist' => array(
					'database.ini.dist file',
					'exists',
					'not found'
			),
			'files-writable' => array(
					'Configuration files',
					'writable',
					'not writable'
			)
	);

	/**
	 * Generates a pseudorandom 256-bit installation hash.
	 *
	 * By default, it will try to use mcrypt to make random bytes (since mcrypt
	 * is supposed to be a system requirement), but may fall back to OpenSSL. If
	 * OpenSSL is unavailable, it falls back to a random-number-seeded hash of
	 * installation details. (mcrypt/OpenSSL-generated bytes are used as-is;
	 * they are not further hashed.)
	 *
	 * In previous versions, installation instructions recommended using a
	 * 32-character (256-bit) human-readable ASCII string. This installer will
	 * instead produce a hex representation of a true 256-bit hash.
	 *
	 * @return string
	 */
	public function generateHash ()
	{
		$hash = '';
		if (function_exists('mcrypt_create_iv')) {
			$hash = bin2hex(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
			// in theory we should never need to fall back to OpenSSL because
			// all BTS server installations depend on mcrypt
		} elseif (function_exists('openssl_random_pseudo_bytes') &&
				 version_compare(PHP_VERSION, '5.3.0', '>=')) {
			$hash = bin2hex(openssl_random_pseudo_bytes(32));
		} else {
			// fallback to sha256 of installation details + 32-char salt
			$plaintext = $_SERVER['SERVER_ADDR'] . $_SERVER['HTTP_HOST'] .
					 $_SERVER['SCRIPT_FILENAME'];
			// produce a 32-char salt using random numbers as bytes
			for ($i = 0; $i < 32; $i ++) {
				$plaintext .= chr(mt_rand(0, 255));
			}
			$hash = hash('sha256', $plaintext);
		}
		return $hash;
	}

	/**
	 * Writes a new bts.ini configuration file with the supplied hash.
	 *
	 * Use saveHash($hash, true) to overwrite the existing file.
	 * Use saveHash($hash, false, true) to return the object instead of
	 * attempting any kind of write.
	 *
	 * @param string $hash
	 *        	a 64-char hex string, such as from `generateHash()`
	 * @param boolean $overwrite
	 *        	set to true to overwrite an existing bts.ini
	 * @param boolean $return
	 *        	set to true to return config instead of writing it
	 * @throws Bts_Exception if supplied parameter is not a 64-char hex string
	 * @throws Bts_Exception if config already exists and $overwrite is false
	 * @throws Bts_Exception if writing the new config file fails
	 * @return Zend_Config_Writer
	 */
	public function saveHash ($hash, $overwrite = false, $return = false)
	{
		// we only like hashes with enough length and in hex
		if (! preg_match('/^[A-Fa-f0-9]{64}$/', $hash)) {
			throw new Bts_Exception(
					'Provided installation hash is not in valid 64-char hex', 
					Bts_Exception::INSTALLER_HASH_BAD);
		}
		if (file_exists(APPLICATION_PATH . '/configs/bts.ini') && ! $overwrite) {
			if (! $return) {
				// we can't overwrite
				throw new Bts_Exception('bts.ini already exists', 
						Bts_Exception::INSTALLER_CONFIG_EXISTS);
			}
		}
		
		$this->BtsConfig = Zend_Registry::get('bts-config');
		$this->BtsConfig->secureHash = $hash;
		
		// this can throw a Zend_Config_Exception
		try {
			$config = new Zend_Config_Writer_Ini(
					array(
							'config' => $this->BtsConfig,
							'filename' => APPLICATION_PATH . '/configs/bts.ini'
					));
			$config->write();
		} catch (Zend_Config_Exception $e) {
			if ($return) {
				// handle the exception here and return a config object
				return $config;
			} else {
				throw new Bts_Exception('bts.ini could not be written', 
						Bts_Exception::INSTALLER_CONFIG_WRITE_FAILURE);
			}
		}
		
		// or, if the write worked, we'll still get to here
		return $config;
	}

	/**
	 * Runs a series of boolean tests that are required for installation.
	 *
	 * @return array
	 */
	public function testEnvironment ()
	{
		@include_once ('System.php');
		// for each test, TRUE = pass, FALSE = fail
		$tests = array(
				'php-version' => version_compare(PHP_VERSION, '5.3.0', '>='),
				'php-safe' => ! ((bool) ini_get('safe_mode')),
				'php-pear' => class_exists('System'),
				'ext-hash' => extension_loaded('hash'),
				'ext-json' => extension_loaded('json'),
				'ext-mcrypt' => extension_loaded('mcrypt'),
				'ext-pdo' => extension_loaded('pdo'),
				'ext-pdomysql' => extension_loaded('pdo_mysql'),
				'ext-mysqli' => extension_loaded('mysqli'),
				'files-btsdist' => @file_exists(
						APPLICATION_PATH . '/configs/bts.ini.dist'),
				'files-dbdist' => @file_exists(
						APPLICATION_PATH . '/configs/database.ini.dist'),
				'files-writable' => is_writable(APPLICATION_PATH . '/configs')
		);
		return $tests;
	}

	/**
	 * Looks up the readable text representation for a given test.
	 *
	 * @param string $test        	
	 * @param bool $success        	
	 * @return array
	 */
	public function getTestReadable ($test, $success)
	{
		if (array_key_exists($test, $this->tests)) {
			$name = $this->tests[$test][0];
			$text = ($success ? $this->tests[$test][1] : $this->tests[$test][2]);
			return array(
					$name,
					$text
			);
		} else
			return array(
					'',
					''
			);
	}
}