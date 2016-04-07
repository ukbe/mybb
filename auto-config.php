<?php

@set_time_limit(0);
date_default_timezone_set ("UTC");

define('MYBB_ROOT', dirname(__FILE__)."/");
define("INSTALL_ROOT", dirname(__FILE__)."/install/");
define("TIME_NOW", time());
define("IN_MYBB", 1);
define("IN_INSTALL", 1);

require_once MYBB_ROOT.'inc/class_error.php';
$error_handler = new errorHandler();

// Include the files necessary for installation
require_once MYBB_ROOT.'inc/class_timers.php';
require_once MYBB_ROOT.'inc/functions.php';

require MYBB_ROOT."/inc/config.php";

$admin_dir = $config['admindir'];

require_once MYBB_ROOT.'inc/class_xml.php';
require_once MYBB_ROOT.'inc/functions_user.php';
require_once MYBB_ROOT.'inc/class_language.php';

// Load DB interface
require_once MYBB_ROOT."inc/db_base.php";

// Include the necessary constants for installation
$grouppermignore = array('gid', 'type', 'title', 'description', 'namestyle', 'usertitle', 'stars', 'starimage', 'image');
$groupzerogreater = array('pmquota', 'maxpmrecipients', 'maxreputationsday', 'attachquota', 'maxemails', 'maxwarningsday', 'maxposts', 'edittimelimit', 'canusesigxposts', 'maxreputationsperthread');
$displaygroupfields = array('title', 'description', 'namestyle', 'usertitle', 'stars', 'starimage', 'image');
$fpermfields = array('canview', 'canviewthreads', 'candlattachments', 'canpostthreads', 'canpostreplys', 'canpostattachments', 'canratethreads', 'caneditposts', 'candeleteposts', 'candeletethreads', 'caneditattachments', 'canpostpolls', 'canvotepolls', 'cansearch', 'modposts', 'modthreads', 'modattachments', 'mod_edit_posts');

require_once(MYBB_ROOT.'inc/db_mysql.php');

if(function_exists('mysql_connect'))
{
	$dboptions['mysql'] = array(
		'class' => 'DB_MySQL',
		'title' => 'MySQL',
		'short_title' => 'MySQL',
		'structure_file' => 'mysql_db_tables.php',
		'population_file' => 'mysql_db_inserts.php'
	);
}

# Create Tables

$db = db_connection($config);
$db->select_db($config['database']['database']);

		$structure_file = 'mysql_db_tables.php';

	require_once INSTALL_ROOT."resources/{$structure_file}";
	foreach($tables as $val)
	{
		$val = preg_replace('#mybb_(\S+?)([\s\.,\(]|$)#', $config['database']['table_prefix'].'\\1\\2', $val);
		$val = preg_replace('#;$#', $db->build_create_table_collation().";", $val);
		preg_match('#CREATE TABLE (\S+)(\s?|\(?)\(#i', $val, $match);
		if($match[1])
		{
			$db->drop_table($match[1], false, false);
		}
		$db->query($val);
	}



# Populate Tables

		$population_file = 'mysql_db_inserts.php';

	require_once INSTALL_ROOT."resources/{$population_file}";
	foreach($inserts as $val)
	{
		$val = preg_replace('#mybb_(\S+?)([\s\.,]|$)#', $config['database']['table_prefix'].'\\1\\2', $val);
		$db->query($val);
	}


# Insert Templates

	require_once MYBB_ROOT.'inc/class_datacache.php';
	$cache = new datacache;

	$db->delete_query("themes");
	$db->delete_query("templates");
	$db->delete_query("themestylesheets");
	my_rmdir_recursive(MYBB_ROOT."cache/themes", array(MYBB_ROOT."cache/themes/index.html"));

	$insert_array = array(
		'title' => 'Default Templates'
	);
	$templateset = $db->insert_query("templatesets", $insert_array);

	$contents = @file_get_contents(INSTALL_ROOT.'resources/mybb_theme.xml');
	if(!empty($mybb->config['admin_dir']) && file_exists(MYBB_ROOT.$mybb->config['admin_dir']."/inc/functions_themes.php"))
	{
		require_once MYBB_ROOT.$mybb->config['admin_dir']."/inc/functions.php";
		require_once MYBB_ROOT.$mybb->config['admin_dir']."/inc/functions_themes.php";
	}
	elseif(file_exists(MYBB_ROOT."admin/inc/functions_themes.php"))
	{
		require_once MYBB_ROOT."admin/inc/functions.php";
		require_once MYBB_ROOT."admin/inc/functions_themes.php";
	}
	else
	{
		$output->print_error("Please make sure your admin directory is uploaded correctly.");
	}
	$theme_id = import_theme_xml($contents, array("templateset" => -2, "version_compat" => 1));
	$tid = build_new_theme("Default", null, $theme_id);

	// Update our properties template set to the correct one
	$query = $db->simple_select("themes", "stylesheets, properties", "tid='{$tid}'", array('limit' => 1));

	$theme = $db->fetch_array($query);
	$properties = my_unserialize($theme['properties']);
	$stylesheets = my_unserialize($theme['stylesheets']);

	$properties['templateset'] = $templateset;
	unset($properties['inherited']['templateset']);

	// 1.8: Stylesheet Colors
	$contents = @file_get_contents(INSTALL_ROOT.'resources/mybb_theme_colors.xml');

	require_once MYBB_ROOT."inc/class_xml.php";
	$parser = new XMLParser($contents);
	$tree = $parser->get_tree();

	if(is_array($tree) && is_array($tree['colors']))
	{
		if(is_array($tree['colors']['scheme']))
		{
			foreach($tree['colors']['scheme'] as $tag => $value)
			{
				$exp = explode("=", $value['value']);

				$properties['colors'][$exp[0]] = $exp[1];
			}
		}

		if(is_array($tree['colors']['stylesheets']))
		{
			$count = count($properties['disporder']) + 1;
			foreach($tree['colors']['stylesheets']['stylesheet'] as $stylesheet)
			{
				$new_stylesheet = array(
					"name" => $db->escape_string($stylesheet['attributes']['name']),
					"tid" => $tid,
					"attachedto" => $db->escape_string($stylesheet['attributes']['attachedto']),
					"stylesheet" => $db->escape_string($stylesheet['value']),
					"lastmodified" => TIME_NOW,
					"cachefile" => $db->escape_string($stylesheet['attributes']['name'])
				);

				$sid = $db->insert_query("themestylesheets", $new_stylesheet);
				$css_url = "css.php?stylesheet={$sid}";

				$cached = cache_stylesheet($tid, $stylesheet['attributes']['name'], $stylesheet['value']);

				if($cached)
				{
					$css_url = $cached;
				}

				// Add to display and stylesheet list
				$properties['disporder'][$stylesheet['attributes']['name']] = $count;
				$stylesheets[$stylesheet['attributes']['attachedto']]['global'][] = $css_url;

				++$count;
			}
		}
	}

	$db->update_query("themes", array("def" => 1, "properties" => $db->escape_string(my_serialize($properties)), "stylesheets" => $db->escape_string(my_serialize($stylesheets))), "tid = '{$tid}'");



# Configure

		$bbname = 'Forums';
		$cookiedomain = '';
		$websitename = 'Your Website';

		$protocol = "http://";
		if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != "off"))
		{
			$protocol = "https://";
		}

		// Attempt auto-detection
		if(!empty($_SERVER['HTTP_HOST']))
		{
			$hostname = $protocol.$_SERVER['HTTP_HOST'];
			$cookiedomain = $_SERVER['HTTP_HOST'];
		}
		elseif(!empty($_SERVER['SERVER_NAME']))
		{
			$hostname = $protocol.$_SERVER['SERVER_NAME'];
			$cookiedomain = $_SERVER['SERVER_NAME'];
		}

		if(my_substr($cookiedomain, 0, 4) == "www.")
		{
			$cookiedomain = substr($cookiedomain, 4);
		}

		// IP addresses and hostnames are not valid
		if(my_inet_pton($cookiedomain) !== false || strpos($cookiedomain, '.') === false)
		{
			$cookiedomain = '';
		}
		else
		{
			$cookiedomain = ".{$cookiedomain}";
		}

		if(!empty($_SERVER['SERVER_PORT']))
		{
			$port = ":{$_SERVER['SERVER_PORT']}";
			$pos = strrpos($cookiedomain, $port);

			if($pos !== false)
			{
				$cookiedomain = substr($cookiedomain, 0, $pos);
			}

			if($_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443 && !preg_match("#:[0-9]#i", $hostname))
			{
				$hostname .= $port;
			}
		}

		$currentlocation = get_current_location('', '', true);
		$noinstall = substr($currentlocation, 0, strrpos($currentlocation, '/install/'));

		$cookiepath = $noinstall.'/';
		$bburl = $hostname.$noinstall;
		$websiteurl = $hostname.'/';

		if(isset($_SERVER['SERVER_ADMIN']) && filter_var($_SERVER['SERVER_ADMIN'], FILTER_VALIDATE_EMAIL))
		{
			$contactemail = $_SERVER['SERVER_ADMIN'];
		}


		$settings = file_get_contents(INSTALL_ROOT.'resources/settings.xml');
		$parser = new XMLParser($settings);
		$parser->collapse_dups = 0;
		$tree = $parser->get_tree();
		$groupcount = $settingcount = 0;

		// Insert all the settings
		foreach($tree['settings'][0]['settinggroup'] as $settinggroup)
		{
			$groupdata = array(
				'name' => $db->escape_string($settinggroup['attributes']['name']),
				'title' => $db->escape_string($settinggroup['attributes']['title']),
				'description' => $db->escape_string($settinggroup['attributes']['description']),
				'disporder' => (int)$settinggroup['attributes']['disporder'],
				'isdefault' => $settinggroup['attributes']['isdefault'],
			);
			$gid = $db->insert_query('settinggroups', $groupdata);
			++$groupcount;
			foreach($settinggroup['setting'] as $setting)
			{
				$settingdata = array(
					'name' => $db->escape_string($setting['attributes']['name']),
					'title' => $db->escape_string($setting['title'][0]['value']),
					'description' => $db->escape_string($setting['description'][0]['value']),
					'optionscode' => $db->escape_string($setting['optionscode'][0]['value']),
					'value' => $db->escape_string($setting['settingvalue'][0]['value']),
					'disporder' => (int)$setting['disporder'][0]['value'],
					'gid' => $gid,
					'isdefault' => 1
				);

				$db->insert_query('settings', $settingdata);
				$settingcount++;
			}
		}

		$db->update_query("settings", array('value' => $db->escape_string($bbname)), "name='bbname'");
		$db->update_query("settings", array('value' => $db->escape_string($bburl)), "name='bburl'");
		$db->update_query("settings", array('value' => $db->escape_string($websitename)), "name='homename'");
		$db->update_query("settings", array('value' => $db->escape_string($websiteurl)), "name='homeurl'");
		$db->update_query("settings", array('value' => $db->escape_string($cookiedomain)), "name='cookiedomain'");
		$db->update_query("settings", array('value' => $db->escape_string($cookiepath)), "name='cookiepath'");
		$db->update_query("settings", array('value' => $db->escape_string($contactemail)), "name='adminemail'");
		$db->update_query("settings", array('value' => 'contact.php'), "name='contactlink'");

		write_settings();

		include_once MYBB_ROOT."inc/functions_task.php";
		$tasks = file_get_contents(INSTALL_ROOT.'resources/tasks.xml');
		$parser = new XMLParser($tasks);
		$parser->collapse_dups = 0;
		$tree = $parser->get_tree();
		$taskcount = 0;

		// Insert scheduled tasks
		foreach($tree['tasks'][0]['task'] as $task)
		{
			$new_task = array(
				'title' => $db->escape_string($task['title'][0]['value']),
				'description' => $db->escape_string($task['description'][0]['value']),
				'file' => $db->escape_string($task['file'][0]['value']),
				'minute' => $db->escape_string($task['minute'][0]['value']),
				'hour' => $db->escape_string($task['hour'][0]['value']),
				'day' => $db->escape_string($task['day'][0]['value']),
				'weekday' => $db->escape_string($task['weekday'][0]['value']),
				'month' => $db->escape_string($task['month'][0]['value']),
				'enabled' => $db->escape_string($task['enabled'][0]['value']),
				'logging' => $db->escape_string($task['logging'][0]['value'])
			);

			$new_task['nextrun'] = fetch_next_run($new_task);

			$db->insert_query("tasks", $new_task);
			$taskcount++;
		}

		// For the version check task, set a random date and hour (so all MyBB installs don't query mybb.com all at the same time)
		$update_array = array(
			'hour' => rand(0, 23),
			'weekday' => rand(0, 6)
		);

		$db->update_query("tasks", $update_array, "file = 'versioncheck'");


		$views = file_get_contents(INSTALL_ROOT.'resources/adminviews.xml');
		$parser = new XMLParser($views);
		$parser->collapse_dups = 0;
		$tree = $parser->get_tree();
		$view_count = 0;

		// Insert admin views
		foreach($tree['adminviews'][0]['view'] as $view)
		{
			$fields = array();
			foreach($view['fields'][0]['field'] as $field)
			{
				$fields[] = $field['attributes']['name'];
			}

			$conditions = array();
			if(isset($view['conditions'][0]['condition']) && is_array($view['conditions'][0]['condition']))
			{
				foreach($view['conditions'][0]['condition'] as $condition)
				{
					if(!$condition['value']) continue;
					if($condition['attributes']['is_serialized'] == 1)
					{
						$condition['value'] = my_unserialize($condition['value']);
					}
					$conditions[$condition['attributes']['name']] = $condition['value'];
				}
			}

			$custom_profile_fields = array();
			if(isset($view['custom_profile_fields'][0]['field']) && is_array($view['custom_profile_fields'][0]['field']))
			{
				foreach($view['custom_profile_fields'][0]['field'] as $field)
				{
					$custom_profile_fields[] = $field['attributes']['name'];
				}
			}

			$new_view = array(
				"uid" => 0,
				"type" => $db->escape_string($view['attributes']['type']),
				"visibility" => (int)$view['attributes']['visibility'],
				"title" => $db->escape_string($view['title'][0]['value']),
				"fields" => $db->escape_string(my_serialize($fields)),
				"conditions" => $db->escape_string(my_serialize($conditions)),
				"custom_profile_fields" => $db->escape_string(my_serialize($custom_profile_fields)),
				"sortby" => $db->escape_string($view['sortby'][0]['value']),
				"sortorder" => $db->escape_string($view['sortorder'][0]['value']),
				"perpage" => (int)$view['perpage'][0]['value'],
				"view_type" => $db->escape_string($view['view_type'][0]['value'])
			);
			$db->insert_query("adminviews", $new_view);
			$view_count++;
		}

# Install Done

	require MYBB_ROOT.'inc/settings.php';
	$mybb->settings = &$settings;

	// Insert all of our user groups from the XML file
	$usergroup_settings = file_get_contents(INSTALL_ROOT.'resources/usergroups.xml');
	$parser = new XMLParser($usergroup_settings);
	$parser->collapse_dups = 0;
	$tree = $parser->get_tree();

	$admin_gid = '';
	$group_count = 0;
	foreach($tree['usergroups'][0]['usergroup'] as $usergroup)
	{
		// usergroup[cancp][0][value]
		$new_group = array();
		foreach($usergroup as $key => $value)
		{
			if(!is_array($value))
			{
				continue;
			}

			$new_group[$key] = $db->escape_string($value[0]['value']);
		}
		$db->insert_query("usergroups", $new_group, false);

		// If this group can access the admin CP and we haven't established the admin group - set it (just in case we ever change IDs)
		if($new_group['cancp'] == 1 && !$admin_gid)
		{
			$admin_gid = $usergroup['gid'][0]['value'];
		}
		$group_count++;
	}



	$now = TIME_NOW;
	$salt = random_str();
	$loginkey = generate_loginkey();
	$saltedpw = md5(md5($salt).md5($config['adminpass']));

	$newuser = array(
		'username' => 'admin',
		'password' => $saltedpw,
		'salt' => $salt,
		'loginkey' => $loginkey,
		'email' => $db->escape_string($config['adminemail']),
		'usergroup' => $admin_gid, // assigned above
		'regdate' => $now,
		'lastactive' => $now,
		'lastvisit' => $now,
		'website' => '',
		'icq' => '',
		'aim' => '',
		'yahoo' => '',
		'skype' =>'',
		'google' =>'',
		'birthday' => '',
		'signature' => '',
		'allownotices' => 1,
		'hideemail' => 0,
		'subscriptionmethod' => '0',
		'receivepms' => 1,
		'pmnotice' => 1,
		'pmnotify' => 1,
		'buddyrequestspm' => 1,
		'buddyrequestsauto' => 0,
		'showimages' => 1,
		'showvideos' => 1,
		'showsigs' => 1,
		'showavatars' => 1,
		'showquickreply' => 1,
		'invisible' => 0,
		'style' => '0',
		'timezone' => 0,
		'dst' => 0,
		'threadmode' => '',
		'daysprune' => 0,
		'regip' => $db->escape_binary(my_inet_pton(get_ip())),
		'language' => '',
		'showcodebuttons' => 1,
		'tpp' => 0,
		'ppp' => 0,
		'referrer' => 0,
		'buddylist' => '',
		'ignorelist' => '',
		'pmfolders' => '',
		'notepad' => '',
		'showredirect' => 1,
		'usernotes' => ''
	);
	$db->insert_query('users', $newuser);



	$adminoptions = file_get_contents(INSTALL_ROOT.'resources/adminoptions.xml');
	$parser = new XMLParser($adminoptions);
	$parser->collapse_dups = 0;
	$tree = $parser->get_tree();
	$insertmodule = array();

	$db->delete_query("adminoptions");

	// Insert all the admin permissions
	foreach($tree['adminoptions'][0]['user'] as $users)
	{
		$uid = $users['attributes']['uid'];

		foreach($users['permissions'][0]['module'] as $module)
		{
			foreach($module['permission'] as $permission)
			{
				$insertmodule[$module['attributes']['name']][$permission['attributes']['name']] = $permission['value'];
			}
		}

		$defaultviews = array();
		foreach($users['defaultviews'][0]['view'] as $view)
		{
			$defaultviews[$view['attributes']['type']] = $view['value'];
		}

		$adminoptiondata = array(
			'uid' => (int)$uid,
			'cpstyle' => '',
			'notes' => '',
			'permissions' => $db->escape_string(my_serialize($insertmodule)),
			'defaultviews' => $db->escape_string(my_serialize($defaultviews))
		);

		$insertmodule = array();

		$db->insert_query('adminoptions', $adminoptiondata);
	}



	// Make fulltext columns if supported
	if($db->supports_fulltext('threads'))
	{
		$db->create_fulltext_index('threads', 'subject');
	}
	if($db->supports_fulltext_boolean('posts'))
	{
		$db->create_fulltext_index('posts', 'message');
	}


	require_once MYBB_ROOT.'inc/class_datacache.php';
	$cache = new datacache;
	$cache->update_version();
	$cache->update_attachtypes();
	$cache->update_smilies();
	$cache->update_badwords();
	$cache->update_usergroups();
	$cache->update_forumpermissions();
	$cache->update_stats();
	$cache->update_statistics();
	$cache->update_forums();
	$cache->update_moderators();
	$cache->update_usertitles();
	$cache->update_reportedcontent();
	$cache->update_awaitingactivation();
	$cache->update_mycode();
	$cache->update_profilefields();
	$cache->update_posticons();
	$cache->update_spiders();
	$cache->update_bannedips();
	$cache->update_banned();
	$cache->update_bannedemails();
	$cache->update_birthdays();
	$cache->update_groupleaders();
	$cache->update_threadprefixes();
	$cache->update_forumsdisplay();
	$cache->update("plugins", array());
	$cache->update("internal_settings", array('encryption_key' => random_str(32)));
	$cache->update_default_theme();

	$version_history = array();
	$dh = opendir(INSTALL_ROOT."resources");
	while(($file = readdir($dh)) !== false)
	{
		if(preg_match("#upgrade([0-9]+).php$#i", $file, $match))
		{
			$version_history[$match[1]] = $match[1];
		}
	}
	sort($version_history, SORT_NUMERIC);
	$cache->update("version_history", $version_history);

	// Schedule an update check so it occurs an hour ago.  Gotta stay up to date!
	$update['nextrun'] = TIME_NOW - 3600;
	$db->update_query("tasks", $update, "tid='12'");

	$cache->update_update_check();
	$cache->update_tasks();



function db_connection($config)
{
	require_once MYBB_ROOT."inc/db_{$config['database']['type']}.php";
	switch($config['database']['type'])
	{
		case "sqlite":
			$db = new DB_SQLite;
			break;
		case "pgsql":
			$db = new DB_PgSQL;
			break;
		case "mysqli":
			$db = new DB_MySQLi;
			break;
		default:
			$db = new DB_MySQL;
	}

	// Connect to Database
	define('TABLE_PREFIX', $config['database']['table_prefix']);

	$db->connect($config['database']);
	$db->set_table_prefix(TABLE_PREFIX);
	$db->type = $config['database']['type'];

	return $db;
}

function write_settings()
{
	global $db;

	$settings = '';
	$query = $db->simple_select('settings', '*', '', array('order_by' => 'title'));
	while($setting = $db->fetch_array($query))
	{
		$setting['value'] = str_replace("\"", "\\\"", $setting['value']);
		$settings .= "\$settings['{$setting['name']}'] = \"{$setting['value']}\";\n";
	}
	if(!empty($settings))
	{
		$settings = "<?php\n/*********************************\ \n  DO NOT EDIT THIS FILE, PLEASE USE\n  THE SETTINGS EDITOR\n\*********************************/\n\n{$settings}\n";
		$file = fopen(MYBB_ROOT."inc/settings.php", "w");
		fwrite($file, $settings);
		fclose($file);
	}
}


?>
