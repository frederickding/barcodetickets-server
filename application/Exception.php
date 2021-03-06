<?php

/**
 * An exception type used by the BTS application.
 *
 * @author	Frederick Ding
 * @version	$Id$
 * @see		Zend_Exception
 */
class Bts_Exception extends Zend_Exception
{

	const ATTENDEES_PARAMS_BAD = - 350;

	const AUTH_SESSION_FAILURE = - 301;

	const AUTH_SYSNAME_MISSING = - 302;

	const AUTH_SYSNAME_BAD = - 303;

	const BARCODES_PARAMS_BAD = - 330;

	const BARCODES_EVENT_BAD = - 331;

	const BARCODES_DATA_BAD = - 332;

	const TICKETS_PARAMS_BAD = - 340;

	const TICKETS_UNAUTHORIZED = - 341;

	const EVENTS_PARAMS_BAD = - 360;

	const INSTALLER_HASH_BAD = - 400;
	
	const INSTALLER_CONFIG_EXISTS = -401;
	
	const INSTALLER_CONFIG_WRITE_FAILURE = -402;
}