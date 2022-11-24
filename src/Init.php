<?php

namespace MageStack\Queue;

class Init {
	public function __construct() {
		$this->config = include realpath(__DIR__) . '/../../../../../env-config/magestack-config.php';

		if ( $this->shouldInit( $this->config['whitelist'] ) && $this->config['enabled'] ) {

			$this->queue = new Queue( $this->config );

			if ( $this->isCli() ) {
				$this->configure();

				return;
			}

			if ( isset( $_COOKIE[ $this->config['cookie_name'] ] ) ) {
				$uid = $_COOKIE[ $this->config['cookie_name'] ];

				// Whitelist contents of data coming from user's side

				if (strlen($uid) !== 36 || !preg_match("/^[A-Z0-9-]+$/", $uid)) {
					$uid = $this->GUID();
				}

			} else {
				$uid = $this->GUID();
			}

			setcookie( $this->config['cookie_name'], $uid, $this->config['cookie_options'] );

			$this->startQueue( $_SERVER['REMOTE_ADDR'], $uid );

			return;
		}
	}

	private function GUID() {
		if ( function_exists( 'com_create_guid' ) === true ) {
			return trim( com_create_guid(), '{}' );
		}

		return sprintf( '%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 16384, 20479 ), mt_rand( 32768, 49151 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ) );
	}

	private function usage() {

		return <<<USAGE
Usage:  php -f queue.php -- [options]

    --install          Create SQL Lite database for tracking queue entries
    --cron             Update the queue metrics
    --flush            Delete entire queue (both users in and out of the queue)
    --status           Show queue statistics
    --simulate [0-9]+  Insert defined number of users into the queue

USAGE;
	}

	private function configure() {
		$shortopts = "";
		$longopts  = [
			'install',
			'cron',
			'flush',
			'status',
			'simulate:'
		];
		$options   = getopt( $shortopts, $longopts );

		if ( ! count( $options ) ) {
			echo $this->usage();
			exit( 1 );
		}

		if ( isset( $options['install'] ) ) {
			try {
				$this->queue->createTable();
			} catch ( Exception $exception ) {
				exit( $exception->getMessage() );
			}
			printf( "Database created sucessfully\n" );
			exit();

		} else if ( isset( $options['cron'] ) ) {
			$this->queue->updateQueueEntries();
			printf( "Metrics updated sucessfully\n" );
			exit();
		} else if ( isset( $options['flush'] ) ) {
			$this->queue->flushQueue();
			printf( "Queue flushed sucessfully\n" );
			exit();
		} else if ( isset( $options['simulate'] ) ) {
			$this->queue->simulateQueue( $options['simulate'] );
			printf( "Inserted %s simulated users\n", $options['simulate'] );
			exit();
		} else if ( isset( $options['status'] ) ) {
			$this->getStatus();
			exit();
		}

	}

	private function getStatus() {
		$results = $this->queue->getStatus();
		$enabled = ( $this->config['enabled'] ) ? 'Enabled' : 'Disabled';
		echo <<<STATUS
Status:          {$enabled}

Threshold:       {$this->config['threshold']} users
Time on site:    {$this->config['timer']} seconds

Users in queue:  {$results['visitors_in_queue']}
Users on site:   {$results['visitors_on_site']}
Total users:     {$results['total_visitors']}
Average wait:    {$this->queue->getAverageWaitTime()}

Visitors
========

STATUS;

		$mask = "|%9.9s | %-15.15s  | %-36.36s| %-8.8s |\n";
		printf( $mask, 'Position', 'IP', 'uid', 'Status' );
		$position = 1;
		foreach ( $results['visitors'] as $visitor ) {
			printf(
				$mask,
				$visitor['position'],
				long2ip( $visitor['ip'] ),
				$visitor['uid'],
				( $visitor['is_queueing'] ) ? 'Queuing' : 'Browsing'
			);
			if ( $visitor['is_queueing'] == 1 ) {
				$position ++;
			}
		}

	}

	private function startQueue( $ip, $uid ) {

		$data = $this->queue->getDataByIp( $ip, $uid );

		// This IP+cookie is already accessing the site, so update information
		if ( $data ) {

			if ( $this->queue->isQueueing( $ip, $uid ) ) {
				if ( $this->queue->checkAccess( $ip, $uid ) ) {
					return;
				}

				$this->queue->showQueueAndDie( $ip, $uid ); // To queuing page
				exit;

			} else {
				if ( is_null( $data['entered_at'] ) ) {
					$this->queue->insertOrUpdateVisitor( $ip, $uid, 0 );
				}

				$this->queue->updateVisitorActivity( $ip, $uid );

				return; // Abort and let the user continue his journey
			}
		} else { // The IP isn't yet in the queue table
			if ( $this->queue->checkAccess( $ip, $uid ) ) {
				return;
			}

			$this->queue->showQueueAndDie( $ip, $uid ); //To queuing page
			exit;
		}
	}

	private function shouldInit( $whitelist ) {
		if ( $this->isCli() ) {
			return true;
		}

		if ( isset( $whitelist['enabled'] ) && $whitelist['enabled'] != true ) {
			return false;
		}

		if ( isset( $whitelist['ip'] ) && is_array( $whitelist['ip'] ) ) {
			foreach ( $whitelist['ip'] as $ip ) {
				$regex = sprintf( '#%s#', $ip );
				if ( preg_match( $regex, $_SERVER['REMOTE_ADDR'] ) ) {
					return false;
				}
			}
		}

		if ( isset( $whitelist['uri'] ) && is_array( $whitelist['uri'] ) ) {
			foreach ( $whitelist['uri'] as $uri ) {
				$regex = sprintf( '#%s#', $uri );
				if ( preg_match( $regex, $_SERVER['REQUEST_URI'] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private function isCli() {
		return ( php_sapi_name() === 'cli' );
	}

}
