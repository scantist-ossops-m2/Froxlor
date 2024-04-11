<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     Froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

namespace Froxlor;

use Exception;
use PDO;
use RecursiveCallbackFilterIterator;
use Froxlor\Customer\Customer;
use Froxlor\Database\Database;

class FileDir
{
	/**
	 * Creates a directory below a users homedir and sets all directories,
	 * which had to be created below with correct Owner/Group
	 * (Copied from cron_tasks.php:rev1189 as we'll need this more often in future)
	 *
	 * @param string $homeDir
	 *            The homedir of the user
	 * @param string $dirToCreate
	 *            The dir which should be created
	 * @param int $uid
	 *            The uid of the user
	 * @param int $gid
	 *            The gid of the user
	 * @param bool $placeindex
	 *            Place standard-index.html into the new folder
	 * @param bool $allow_notwithinhomedir
	 *            Allow creating a directory out of the customers docroot
	 *
	 * @return bool true if everything went okay, false if something went wrong
	 * @throws Exception
	 */
	public static function mkDirWithCorrectOwnership(
		$homeDir,
		$dirToCreate,
		$uid,
		$gid,
		$placeindex = false,
		$allow_notwithinhomedir = false
	) {
		if ($homeDir != '' && $dirToCreate != '') {
			$homeDir = self::makeCorrectDir($homeDir);
			$dirToCreate = self::makeCorrectDir($dirToCreate);

			if (substr($dirToCreate, 0, strlen($homeDir)) == $homeDir) {
				$subdir = substr($dirToCreate, strlen($homeDir) - 1);
				$within_homedir = true;
			} else {
				$subdir = $dirToCreate;
				$within_homedir = false;
			}

			$subdir = self::makeCorrectDir($subdir);
			$subdirs = [];

			if ($within_homedir || !$allow_notwithinhomedir) {
				$subdirlen = strlen($subdir);
				$offset = 0;

				while ($offset < $subdirlen) {
					$offset = strpos($subdir, '/', $offset);
					$subdirelem = substr($subdir, 0, $offset);
					$offset++;
					array_push($subdirs, self::makeCorrectDir($homeDir . $subdirelem));
				}
			} else {
				array_push($subdirs, $dirToCreate);
			}

			$subdirs = array_unique($subdirs);
			sort($subdirs);
			foreach ($subdirs as $sdir) {
				if (!is_dir($sdir)) {
					$sdir = self::makeCorrectDir($sdir);
					self::safe_exec('mkdir -p ' . escapeshellarg($sdir));
					// place index
					if ($placeindex) {
						$loginname = Customer::getLoginNameByUid($uid);
						if ($loginname !== false) {
							self::storeDefaultIndex($loginname, $sdir, null);
						}
					}
					self::safe_exec('chown -R ' . (int)$uid . ':' . (int)$gid . ' ' . escapeshellarg($sdir));
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Function which returns a correct dirname, means to add slashes at the beginning and at the end if there weren't
	 * some
	 *
	 * @param string $path
	 *            the path to correct
	 *
	 * @return string the corrected path
	 * @throws Exception
	 */
	public static function makeCorrectDir($dir)
	{
		if (is_string($dir) && strlen($dir) > 0) {
			$dir = trim($dir);
			if (substr($dir, -1, 1) != '/') {
				$dir .= '/';
			}
			if (substr($dir, 0, 1) != '/') {
				$dir = '/' . $dir;
			}
			return self::makeSecurePath($dir);
		}
		throw new Exception("Cannot validate directory in " . __FUNCTION__ . " which is very dangerous.");
	}

	/**
	 * Function which returns a secure path, means to remove all multiple dots and slashes
	 *
	 * @param string $path
	 *            the path to secure
	 *
	 * @return string the corrected path
	 */
	public static function makeSecurePath($path)
	{
		// check for bad characters, some are allowed with escaping,
		// but we generally don't want them in our directory-names,
		// thx to aaronmueller for this snippet
		$badchars = [
			':',
			';',
			'|',
			'&',
			'>',
			'<',
			'`',
			'$',
			'~',
			'?',
			"\0",
			"\n",
			"\r",
			"\t",
			"\f"
		];
		foreach ($badchars as $bc) {
			$path = str_replace($bc, "", $path);
		}

		$search = [
			'#/+#',
			'#\.+#'
		];
		$replace = [
			'/',
			'.'
		];
		$path = preg_replace($search, $replace, $path);
		// don't just replace a space with an escaped space
		// it might be escaped already
		$path = str_replace("\ ", " ", $path);
		$path = str_replace(" ", "\ ", $path);

		return $path;
	}

	/**
	 * Wrapper around the exec command.
	 *
	 * @param string $exec_string
	 *            command to be executed
	 * @param string $return_value
	 *            referenced variable where the output is stored
	 * @param array $allowedChars
	 *            optional array of allowed characters in path/command
	 *
	 * @return array result of exec()
	 */
	public static function safe_exec($exec_string, &$return_value = false, $allowedChars = null)
	{
		$disallowed = [
			';',
			'|',
			'&',
			'>',
			'<',
			'`',
			'$',
			'~',
			'?'
		];

		$acheck = false;
		if ($allowedChars != null && is_array($allowedChars) && count($allowedChars) > 0) {
			$acheck = true;
		}

		foreach ($disallowed as $dc) {
			if ($acheck && in_array($dc, $allowedChars)) {
				continue;
			}
			// check for bad signs in execute command
			if (stristr($exec_string, $dc)) {
				die("SECURITY CHECK FAILED!\nThe execute string '" . $exec_string . "' is a possible security risk!\nPlease check your whole server for security problems by hand!\n");
			}
		}

		// execute the command and return output
		$return = '';

		// -------------------------------------------------------------------------------
		if ($return_value == false) {
			exec($exec_string, $return);
		} else {
			exec($exec_string, $return, $return_value);
		}

		return $return;
	}

	/**
	 * store the default index-file in a given destination folder
	 *
	 * @param string $loginname
	 *            customers loginname
	 * @param string $destination
	 *            path where to create the file
	 * @param object $logger
	 *            FroxlorLogger object
	 * @param boolean $force
	 *            force creation whatever the settings say (needed for task #2, create new user)
	 *
	 * @return null
	 */
	public static function storeDefaultIndex($loginname = null, $destination = null, $logger = null, $force = false)
	{
		if ($force || (int)Settings::Get('system.store_index_file_subs') == 1) {
			$result_stmt = Database::prepare("
			SELECT `t`.`value`, `c`.`email` AS `customer_email`, `a`.`email` AS `admin_email`, `c`.`loginname` AS `customer_login`, `a`.`loginname` AS `admin_login`
			FROM `" . TABLE_PANEL_CUSTOMERS . "` AS `c` INNER JOIN `" . TABLE_PANEL_ADMINS . "` AS `a`
			ON `c`.`adminid` = `a`.`adminid`
			INNER JOIN `" . TABLE_PANEL_TEMPLATES . "` AS `t`
			ON `a`.`adminid` = `t`.`adminid`
			WHERE `varname` = 'index_html' AND `c`.`loginname` = :loginname");
			Database::pexecute($result_stmt, [
				'loginname' => $loginname
			]);

			if (Database::num_rows() > 0) {
				$template = $result_stmt->fetch(PDO::FETCH_ASSOC);

				$replace_arr = [
					'SERVERNAME' => Settings::Get('system.hostname'),
					'CUSTOMER' => $template['customer_login'],
					'ADMIN' => $template['admin_login'],
					'CUSTOMER_EMAIL' => $template['customer_email'],
					'ADMIN_EMAIL' => $template['admin_email']
				];

				// replaceVariables
				$htmlcontent = PhpHelper::replaceVariables($template['value'], $replace_arr);
				$indexhtmlpath = self::makeCorrectFile($destination . '/index.' . Settings::Get('system.index_file_extension'));
				$index_html_handler = fopen($indexhtmlpath, 'w');
				fwrite($index_html_handler, $htmlcontent);
				fclose($index_html_handler);
				if ($logger !== null) {
					$logger->logAction(
						FroxlorLogger::CRON_ACTION,
						LOG_NOTICE,
						'Creating \'index.' . Settings::Get('system.index_file_extension') . '\' for Customer \'' . $template['customer_login'] . '\' based on template in directory ' . escapeshellarg($indexhtmlpath)
					);
				}
			} else {
				$destination = self::makeCorrectDir($destination);
				if ($logger !== null) {
					$logger->logAction(
						FroxlorLogger::CRON_ACTION,
						LOG_NOTICE,
						'Running: cp -a ' . Froxlor::getInstallDir() . '/templates/misc/standardcustomer/* ' . escapeshellarg($destination)
					);
				}
				self::safe_exec('cp -a ' . Froxlor::getInstallDir() . '/templates/misc/standardcustomer/* ' . escapeshellarg($destination));
			}
		}
		return;
	}

	/**
	 * Function which returns a correct filename, means to add a slash at the beginning if there wasn't one
	 *
	 * @param string $filename
	 *            the filename
	 *
	 * @return string the corrected filename
	 */
	public static function makeCorrectFile(string $filename)
	{
		if (trim($filename) == '') {
			$error = 'Given filename for function ' . __FUNCTION__ . ' is empty.' . "\n";
			$error .= 'This is very dangerous and should not happen.' . "\n";
			$error .= 'Please inform the Froxlor team about this issue so they can fix it.';
			echo $error;
			// so we can see WHERE this happened
			debug_print_backtrace();
			die();
		}

		if (substr($filename, 0, 1) != '/') {
			$filename = '/' . $filename;
		}

		$filename = self::makeSecurePath($filename);
		return $filename;
	}

	/**
	 * checks a directory against disallowed paths which could
	 * lead to a damaged system if you use them
	 *
	 * @param string $fieldname
	 * @param array $fielddata
	 * @param mixed $newfieldvalue
	 *
	 * @return boolean|array
	 */
	public static function checkDisallowedPaths($path = null)
	{
		/*
		 * disallow base-directories and /
		 */
		$disallowed_values = [
			"/",
			"/bin/",
			"/boot/",
			"/dev/",
			"/etc/",
			"/home/",
			"/lib/",
			"/lib32/",
			"/lib64/",
			"/opt/",
			"/proc/",
			"/root/",
			"/run/",
			"/sbin/",
			"/sys/",
			"/tmp/",
			"/usr/",
			"/var/"
		];

		$path = self::makeCorrectDir($path);

		// check if it's a disallowed path
		if (in_array($path, $disallowed_values)) {
			return false;
		}
		return true;
	}

	/**
	 * Function which returns a correct destination for Postfix Virtual Table
	 *
	 * @param
	 *            string The destinations
	 * @return string the corrected destinations
	 * @author Florian Lippert <flo@syscp.org> (2003-2009)
	 */
	public static function makeCorrectDestination($destination)
	{
		$search = '/ +/';
		$replace = ' ';
		$destination = preg_replace($search, $replace, $destination);

		if (substr($destination, 0, 1) == ' ') {
			$destination = substr($destination, 1);
		}

		if (substr($destination, -1, 1) == ' ') {
			$destination = substr($destination, 0, strlen($destination) - 1);
		}

		return $destination;
	}

	/**
	 * Returns a valid html tag for the chosen $fieldType for paths
	 *
	 * @param
	 *            string path The path to start searching in
	 * @param
	 *            integer uid The uid which must match the found directories
	 * @param
	 *            integer gid The gid which must match the found directories
	 * @param
	 *            string value the value for the input-field
	 *
	 * @return string The html tag for the chosen $fieldType
	 *
	 * @author Martin Burchert <martin.burchert@syscp.de>
	 * @author Manuel Bernhardt <manuel.bernhardt@syscp.de>
	 */
	public static function makePathfield($path, $uid, $gid, $value = '', $dom = false)
	{
		$value = str_replace($path, '', $value);
		$field = [];

		// path is given without starting slash
		// but dirList holds the paths with starting slash
		// so we just add one here to get the correct
		// default path selected, #225
		if (substr($value, 0, 1) != '/' && !$dom) {
			$value = '/' . $value;
		}

		$fieldType = strtolower(Settings::Get('panel.pathedit'));

		if ($fieldType == 'manual') {
			$field = [
				'type' => 'text',
				'value' => htmlspecialchars($value)
			];
		} elseif ($fieldType == 'dropdown') {
			$dirList = self::findDirs($path, $uid, $gid);
			natcasesort($dirList);

			if (sizeof($dirList) > 0) {
				$_field = [];
				foreach ($dirList as $dir) {
					if (strpos($dir, $path) === 0) {
						$dir = substr($dir, strlen($path));
						// docroot cut off of current directory == empty -> directory is the docroot
						if (empty($dir)) {
							$dir = '/';
						}
						$dir = self::makeCorrectDir($dir);
					}
					$_field[$dir] = $dir;
				}
				$field = [
					'type' => 'select',
					'select_var' => $_field,
					'selected' => $value
				];
			} else {
				$field = [
					'type' => 'hidden',
					'value' => '/',
					'note' => lng('panel.dirsmissing')
				];
			}
		}

		return $field;
	}

	/**
	 * Returns an array of found directories
	 *
	 * This function checks every found directory if they match either $uid or $gid, if they do
	 * the found directory is valid. It uses recursive-iterators to find subdirectories.
	 *
	 * @param string $path
	 *            the path to start searching in
	 * @param int $uid
	 *            the uid which must match the found directories
	 * @param int $gid
	 *            the gid which must match the found directories
	 *
	 * @return array Array of found valid paths
	 */
	private static function findDirs($path, $uid, $gid)
	{
		$_fileList = [];
		$path = self::makeCorrectDir($path);

		// valid directory?
		if (is_dir($path)) {
			// Will exclude everything under these directories
			$exclude = [
				'awstats',
				'webalizer'
			];

			/**
			 *
			 * @param SplFileInfo $file
			 * @param mixed $key
			 * @param RecursiveCallbackFilterIterator $iterator
			 * @return bool True if you need to recurse or if the item is acceptable
			 */
			$filter = function ($file, $key, $iterator) use ($exclude) {
				if (in_array($file->getFilename(), $exclude)) {
					return false;
				} elseif (substr($file->getFilename(), 0, 1) == '.') {
					// also hide hidden folders
					return false;
				}
				return true;
			};

			// create RecursiveIteratorIterator
			$its = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
					$filter
				),
				\RecursiveIteratorIterator::SELF_FIRST,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			// we can limit the recursion-depth, but will it be helpful or
			// will people start asking "why do I only see 2 subdirectories, i want to use /a/b/c"
			// let's keep this in mind and see whether it will be useful
			$its->setMaxDepth(2);

			// check every file
			foreach ($its as $fullFileName => $it) {
				if ($it->isDir() && (fileowner($fullFileName) == $uid || filegroup($fullFileName) == $gid)) {
					$_fileList[] = self::makeCorrectDir($fullFileName);
				}
			}
			$_fileList[] = $path;
		}

		return array_unique($_fileList);
	}

	/**
	 * set the immutable flag for a file
	 *
	 * @param string $filename the file to set the flag for
	 *
	 * @return void
	 */
	public static function setImmutable(string $filename)
	{
		self::safe_exec(self::getImmutableFunction(false) . escapeshellarg($filename));
	}

	/**
	 * internal function to check whether
	 * to use chattr (Linux) or chflags (FreeBSD)
	 *
	 * @param bool $remove whether to use +i|schg (false) or -i|noschg (true)
	 *
	 * @return string functionname + parameter (not the file)
	 */
	private static function getImmutableFunction(bool $remove = false)
	{
		if (self::isFreeBSD()) {
			// FreeBSD style
			return 'chflags ' . (($remove === true) ? 'noschg ' : 'schg ');
		} else {
			// Linux style
			return 'chattr ' . (($remove === true) ? '-i ' : '+i ');
		}
	}

	/**
	 * check if the system is FreeBSD (if exact)
	 * or BSD-based (NetBSD, OpenBSD, etc.
	 * if exact = false [default])
	 *
	 * @param bool $exact whether to check explicitly for FreeBSD or *BSD
	 *
	 * @return bool
	 */
	public static function isFreeBSD(bool $exact = false)
	{
		if (($exact && PHP_OS == 'FreeBSD') || (!$exact && stristr(PHP_OS, 'BSD'))) {
			return true;
		}
		return false;
	}

	/**
	 * removes the immutable flag for a file
	 *
	 * @param string $filename the file to set the flag for
	 *
	 * @return void
	 */
	public static function removeImmutable(string $filename)
	{
		FileDir::safe_exec(self::getImmutableFunction(true) . escapeshellarg($filename));
	}

	/**
	 *
	 * @return array|false
	 */
	public static function getFilesystemQuota()
	{
		// enabled at all?
		if (Settings::Get('system.diskquota_enabled')) {
			// set linux defaults
			$repquota_params = "-np";
			// $quota_line_regex = "/^#([0-9]+)\s*[+-]{2}\s*(\d+)\s*(\d+)\s*(\d+)\s*(\d+)\s*(\d+)\s*(\d+)\s*(\d+)\s*(\d+)/i";
			$quota_line_regex = "/^#([0-9]+)\s+[+-]{2}\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/i";

			// check for freebsd - which needs other values
			if (self::isFreeBSD()) {
				$repquota_params = "-nu";
				$quota_line_regex = "/^([0-9]+)\s+[+-]{2}\s+(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\S+)/i";
			}

			// Fetch all quota in the desired partition
			$repquota = [];
			exec(
				Settings::Get('system.diskquota_repquota_path') . " " . $repquota_params . " " . escapeshellarg(Settings::Get('system.diskquota_customer_partition')),
				$repquota
			);

			$usedquota = [];
			foreach ($repquota as $tmpquota) {
				$matches = null;
				// Let's see if the line matches a quota - line
				if (preg_match($quota_line_regex, $tmpquota, $matches)) {
					// It matches - put it into an array with userid as key (for easy lookup later)
					$usedquota[$matches[1]] = [
						'block' => [
							'used' => $matches[2],
							'soft' => $matches[3],
							'hard' => $matches[4],
							'grace' => (self::isFreeBSD() ? '0' : $matches[5])
						],
						'file' => [
							'used' => $matches[6],
							'soft' => $matches[7],
							'hard' => $matches[8],
							'grace' => (self::isFreeBSD() ? '0' : $matches[9])
						]
					];
				}
			}

			return $usedquota;
		}
		return false;
	}
}
