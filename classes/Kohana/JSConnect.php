<?php
/**
 * This file contains the client code for Vanilla jsConnect single sign on.
 * @author Todd Burry <todd@vanillaforums.com>
 * @author Ryder Ross <ryross@gmail.com>
 * @version 1.3b-kohana
 * @copyright Copyright 2008, 2009 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */


class Kohana_JSConnect
{
	const JS_TIMEOUT = 1440; //24 * 60;

	public function __construct($config = array())
	{
		$this->config = $config;
	}

	public static function factory(array $config = array())
	{
		if ( ! $config)
		{
			$config = Kohana::$config->load('jsconnect')->as_array();
		}
		return new JSConnect($config);
	}

	/**
	 * Write the jsConnect string for single sign on.
	 * @param array $User An array containing information about the currently signed on user. If no user is signed in then this should be an empty array.
	 * @param array $Request An array of the $_GET request.
	 * @param string|bool $Secure Whether or not to check for security. This is one of these values.
	 *  - true: Check for security and sign the response with an md5 hash.
	 *  - false: Don't check for security, but sign the response with an md5 hash.
	 *  - string: Check for security and sign the response with the given hash algorithm. See hash_algos() for what your server can support.
	 *  - null: Don't check for security and don't sign the response.
	 * @since 1.1b Added the ability to provide a hash algorithm to $Secure.
	 */
	public function Write($User, $Request, $Secure = TRUE)
	{
		$User = array_change_key_case($User);

		// Error checking.
		if ($Secure)
		{
			// Check the client.
			if ( ! isset($Request['client_id']))
			{
				$Error = array('error' => 'invalid_request', 'message' => 'The client_id parameter is missing.');
			}
			elseif ($Request['client_id'] != $this->config['client_id'])
			{
				$Error = array('error' => 'invalid_client', 'message' => "Unknown client {$Request['client_id']}.");
			}
			elseif (!isset($Request['timestamp']) && !isset($Request['signature']))
			{
				if (is_array($User) && count($User) > 0)
				{
					// This isn't really an error, but we are just going to return public information when no signature is sent.
					$Error = array('name' => $User['name'], 'photourl' => @$User['photourl']);
				}
				else
				{
					$Error = array('name' => '', 'photourl' => '');
				}
			}
			elseif ( ! isset($Request['timestamp']) || ! is_numeric($Request['timestamp']))
			{
				$Error = array('error' => 'invalid_request', 'message' => 'The timestamp parameter is missing or invalid.');
			}
			elseif (!isset($Request['signature']))
			{
				$Error = array('error' => 'invalid_request', 'message' => 'Missing  signature parameter.');
			}
			elseif (($Diff = abs($Request['timestamp'] - self::Timestamp())) > self::JS_TIMEOUT)
			{
				$Error = array('error' => 'invalid_request', 'message' => 'The timestamp is invalid.');
			}
			else
			{
				// Make sure the timestamp hasn't timed out.
				$Signature = $this->Hash($Request['timestamp'].$this->config['secret'], $Secure);
				if ($Signature != $Request['signature'])
				{
					$Error = array('error' => 'access_denied', 'message' => 'Signature invalid.');
				}
			}
		}

		if (isset($Error))
		{
			$Result = $Error;
		}
		elseif (is_array($User) && count($User) > 0)
		{
			if ($Secure === NULL)
			{
				$Result = $User;
			}
			else
			{
				$Result = $this->Sign($User, $Secure, TRUE);
			}
		}
		else
		{
			$Result = array('name' => '', 'photourl' => '');
		}

		$Json = json_encode($Result);

		if (isset($Request['callback']))
		{
			return "{$Request['callback']}($Json)";
		}
		else
		{
			return $Json;
		}
	}

	public function Sign($Data, $HashType, $ReturnData = FALSE)
	{
		$Data = array_change_key_case($Data);
		ksort($Data);

		foreach ($Data as $Key => $Value)
		{
			if ($Value === NULL)
			{
				$Data[$Key] = '';
			}
		}

		$String = http_build_query($Data, NULL, '&');
		//   echo "$String\n";
		$Signature = self::Hash($String.$this->config['secret'], $HashType);
		if ($ReturnData)
		{
			$Data['client_id'] = $this->config['client_id'];
			$Data['signature'] = $Signature;
			//      $Data['string'] = $String;
			return $Data;
		}
		else
		{
			return $Signature;
		}
	}

	/**
	 * Return the hash of a string.
	 * @param string $String The string to hash.
	 * @param string|bool $Secure The hash algorithm to use. TRUE means md5.
	 * @return string 
	 * @since 1.1b
	 */
	public static function Hash($String, $Secure = TRUE)
	{
		if ($Secure === TRUE)
		{
			$Secure = 'md5';
		}

		switch ($Secure)
		{
		case 'sha1':
			return sha1($String);
			break;
		case 'md5':
		case FALSE:
			return md5($String);
		default:
			return hash($Secure, $String);
		}
	}

	public static function Timestamp()
	{
		return time();
	}

	/**
	 * Generate an SSO string suitible for passing in the url for embedded SSO.
	 * 
	 * @param array $User The user to sso.
	 * @param string $ClientID Your client ID.
	 * @param string $Secret Your secret.
	 * @return string
	 */
	public function SSOString($User)
	{
		if ( ! isset($User['client_id']))
		{
			$User['client_id'] = $this->config['client_id'];
		}

		$String = base64_encode(json_encode($User));
		$Timestamp = time();
		$Hash = hash_hmac('sha1', "$String $Timestamp", $this->config['secret']);

		$Result = "$String $Hash $Timestamp hmacsha1";
		return $Result;
	}
}

