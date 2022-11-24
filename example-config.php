<?php

return [
	// Perl regular expression format is used for pattern matching
	'whitelist' => [
		'ip' => [
			//'172.16.0..+'
		],
		'uri' => [
			//'paypal',
			//'api/v2_soap',
			//'sgps',
			//'admin(_[a-z0-9]+)?'
		],
	],

	// Whether the queue system is enabled or not
	'enabled' => false,

	// Maximim number of users on site at any time
	// Set value to -1 to force everyone into the queue
	'threshold' => 100,

	// Period of time a user can be idle in queue before being
	// kicked out (in seconds)
	'timer' => 600,

	// Google analytics tracking code
	'ga_code' => '',

	// Cookie
	'cookie_name' => '__Host-qid',
	'cookie_options' => [
		'expires' => time() + 60 * 60 * 24 * 30, // 30 days
		'path' => '/',
		'domain' => '',
		'secure' => true,
		'httponly' => true,
		'samesite' => 'Strict',
	],

	// Database backend
	// sqlite: For single-server/low volume deployments
	// mysql:  For multi-server/high volume deployments
	'database' => [
		'driver' => 'mysql',
		'name' => 'queue',
		'user' => '',
		'password' => '',
		'host' => 'localhost',
		'queue_table' => 'queue',
	],

	'templatePath' => realpath(dirname(__FILE__)).'/example-queue-landing.phtml',
];
