<?php

namespace MageStack\Queue;

use MageStack\Queue\Backend\SQLite;
use MageStack\Queue\Backend\MySQL;

class Queue {

	protected $db = null;
	protected $path = null;

	protected $tableName = null;
	protected $threshold = null;
	protected $timer = null;

	protected $userdata = null;

	public function __construct( $config ) {

		switch ( $config['database']['driver'] ) {
			case 'sqlite':
				$pathToDb = $config['path'] . '/' . $config['database']['name'] . '.sqlite';
				$this->db = new SQLite( $pathToDb );
				break;

			case 'mysql':
				$this->db = new MySQL( $config['database'] );
				break;
		}

		$this->queueTable = $config['database']['queue_table'];
		$this->threshold  = $config['threshold'];
		$this->timer      = $config['timer'];
		$this->path       = $config['path'];
		$this->gaCode     = $config['ga_code'];

		return $this;
	}

	public function createTable() {
		$query  = "DROP TABLE {$this->queueTable};";
		$result = $this->db->exec( $query );

		$query  = "
            CREATE TABLE IF NOT EXISTS {$this->queueTable} (
            ip BIGINT(10) NOT NULL ,
            uid varchar(36) NOT NULL UNIQUE,
            eta INT(10) NULL DEFAULT 0,
            position INT(10) NOT NULL DEFAULT 0,
            is_queueing BOOLEAN NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            entered_at TIMESTAMP NULL DEFAULT NULL,
            waiting_time INT(5) NOT NULL DEFAULT " . $this->timer . ");
        ";
		$result = $this->db->exec( $query );

		$query  = "CREATE INDEX queue_index ON {$this->queueTable} (is_queueing, ip, uid, updated_at);";
		$result = $this->db->exec( $query );

		return $result;
	}

	public function flushQueue() {
		$query = "DELETE FROM {$this->queueTable}";

		return ( $this->db->query( $query ) ) ? true : false;
	}

	public function simulateQueue( $queueSize ) {
		for ( $i = 0; $i <= $queueSize; $i ++ ) {
			$ip         = long2ip( rand( 167772160, 184549375 ) );
			$isQueueing = ( $i >= $this->threshold ) ? 1 : 0;
			$this->insertOrUpdateVisitor( $ip, $this->GUID(), $isQueueing );
		}
	}

	private function GUID() {
		if ( function_exists( 'com_create_guid' ) === true ) {
			return trim( com_create_guid(), '{}' );
		}

		return sprintf( '%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 16384, 20479 ), mt_rand( 32768, 49151 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ) );
	}

	public function getStatus() {
		$query  = "
            SELECT *
            FROM {$this->queueTable}
            ORDER BY position";
		$result = $this->db->query( $query );

		$visitors = [];
		while ( $row = $result->fetchArray() ) {
			$visitors[] = $row;
		}

		$result = [
			'visitors'         => $visitors,
			'total_visitors'   => count( $visitors ),
			'visitors_on_site' => $this->getVisitorCount(),
		];

		$result['visitors_in_queue'] = $result['total_visitors'] - $result['visitors_on_site'];

		return $result;
	}

	public function getVisitorCount() {
		$query  = "
            SELECT count(uid) AS counter
            FROM {$this->queueTable}
            WHERE is_queueing = 0";
		$result = $this->db->query( $query );
		$row    = $result->fetchArray();

		return isset( $row['counter'] ) ? (int) $row['counter'] : 0;
	}

	public function insertOrUpdateVisitor( $ip, $uid, $isQueuing = 0 ) {

		$existingData = $this->userdata;

		if ( is_null( $existingData ) ) {
			// MySQL can't do subquery on same table
			$query    = "SELECT (IFNULL(MAX(position), 0) + 1) AS position FROM {$this->queueTable} WHERE is_queueing = 1";
			$result   = $this->db->query( $query );
			$position = $result->fetchArray()['position'];

			$waitingTime = $this->timer;

			$query     = "
                INSERT INTO {$this->queueTable} (ip, uid, is_queueing, created_at, position, eta, waiting_time)
                VALUES (
                    " . ip2long( $ip ) . ",
                    '$uid',
                    {$isQueuing},
                    '" . date( 'Y-m-d H:i:s' ) . "',
                    $position,
                    " . ( time() + $this->getAverageWaitTime() ) . ",
                    {$waitingTime}
                )";
			$createdAt = date( 'Y-m-d H:i:s' );
		} else {
			$query     = "
                UPDATE {$this->queueTable}
                SET is_queueing = {$isQueuing}, updated_at = '" . date( 'Y-m-d H:i:s' ) . "'
                WHERE ip = '" . ip2long( $ip ) . "' and uid = '$uid'";
			$createdAt = $existingData['created_at'];
		}

		$result = $this->db->exec( $query );

		if ( ! $isQueuing ) {
			$waitingTime = time() - strtotime( $createdAt );

			$query  = "
                UPDATE {$this->queueTable}
                SET entered_at = '" . date( 'Y-m-d H:i:s' ) . "', waiting_time = {$waitingTime}
                WHERE ip = '" . ip2long( $ip ) . "' and uid = '$uid'";
			$result = $this->db->exec( $query );

		}


		return $result;
	}

	public function updateVisitorActivity( $ip, $uid ) {
		$query  = "
            UPDATE {$this->queueTable}
            SET updated_at = '" . date( 'Y-m-d H:i:s' ) . "'
            WHERE ip = '" . ip2long( $ip ) . "' and uid = '$uid'";
		$result = $this->db->exec( $query );

		return $result;
	}

	public function getDataByIp( $ip, $uid ) {

		if ( ! is_null( $this->userdata ) ) {
			return $this->userdata;
		}
		$query  = "
            SELECT *
            FROM {$this->queueTable}
            WHERE ip = '" . ip2long( $ip ) . "' and uid = '$uid'";
		$result = $this->db->query( $query );
		$row    = $result->fetchArray();

		$this->userdata = $row;

		return $row;
	}

	public function isQueueing( $ip, $uid ) {
		$data = $this->getDataByIp( $ip, $uid );
		if ( ! $data ) {
			return false;
		}

		return isset( $data['is_queueing'] ) ? (bool) $data['is_queueing'] : 0;
	}

	public function checkAccess( $ip, $uid ) {
		$visitorsCount = $this->getVisitorCount();

		// The current visitor count is lower than the threshold
		// so permit the user access
		if ( $visitorsCount < $this->threshold ) {
			$this->insertOrUpdateVisitor( $ip, $uid );

			return true;
		}

		$this->insertOrUpdateVisitor( $ip, $uid, 1 );

		return false;
	}

	/*
	 * This method is used on each queue user request
	 * It should be as efficient as possible
	 */
	public function getQueueStats( $ip, $uid ) {
		$query  = "
            SELECT eta, position, (
                    SELECT count(uid) AS total
                    FROM {$this->queueTable}
                    WHERE is_queueing = 1
                ) as total
            FROM {$this->queueTable}
            WHERE uid='$uid'";
		$result = $this->db->query( $query );
		$row    = $result->fetchArray();

		if ( $row['eta'] - time() <= 1 ) {
			$row['eta'] = time() + $this->timer;
		}

		return $row;
	}

	public function getAverageWaitTime() {
		// Calculate the average wait time
		$query       = "
            SELECT AVG(waiting_time) as avg_waiting_time
            FROM  {$this->queueTable}
            WHERE is_queueing = 0";
		$result      = $this->db->query( $query );
		$avgWaitTime = $result->fetchArray()['avg_waiting_time'];

		if ( is_null( $avgWaitTime ) || $avgWaitTime < 0 ) {
			$avgWaitTime = $this->timer;
		}

		return round( $avgWaitTime, 0 );
	}

	public function updateQueueEntries() {
		// Kick users out of the site/queue that are inactive
		$query  = "
            DELETE FROM {$this->queueTable}
            WHERE updated_at < '" . date( 'Y-m-d H:i:s', time() - $this->timer ) . "'";
		$result = $this->db->exec( $query );

		$visitorsCount  = $this->getVisitorCount();
		$slotsAvailable = $this->threshold - $visitorsCount;

		// This code normally would be done in a single query
		// but MySQL and SQLite don't support the same methods
		// Ie. Limit in subquery
		if ( $slotsAvailable > 0 ) {

			$query  = "
                UPDATE {$this->queueTable} v
                INNER JOIN
                (
                    SELECT uid
                    FROM  {$this->queueTable}
                    WHERE is_queueing = 1
                    ORDER BY position ASC
                    LIMIT 0, {$slotsAvailable}
                ) t 
                ON 
                v.uid=t.uid
                SET is_queueing = 0, position = 0, updated_at = '" . date( 'Y-m-d H:i:s' ) . "'
               ";
			$result = $this->db->exec( $query );
		}

		// Clean up position for users who directly entered the site
		$query  = "
            UPDATE {$this->queueTable}
            SET position = 0
            WHERE is_queueing  = 0";
		$result = $this->db->exec( $query );

		$query          = "
            SELECT MIN(position) as min_position
            FROM {$this->queueTable}
            WHERE is_queueing  = 1";
		$result         = $this->db->query( $query );
		$positionOffset = $result->fetchArray()['min_position'] - 1;

		$query  = "
            UPDATE {$this->queueTable}
            SET position = position - {$positionOffset}
            WHERE is_queueing  = 1";
		$result = $this->db->exec( $query );

	}

	public function showQueueAndDie( $ip, $uid ) {
		$stats = $this->getQueueStats( $ip, $uid );

		$stats['eta']      = round( ( $stats['eta'] - time() ) / 60, 0 );
		$stats['position'] = ( $stats['position'] == 0 ) ? '~' : $stats['position'];

		$template = file_get_contents( $this->path . '/src/view/queue-landing.phtml' );
		$template = str_ireplace(
			[
				'{{queue.position}}',
				'{{queue.eta}}',
				'{{queue.total}}',
				'{{remote_addr}}',
				'{{server.http_host}}',
				'{{date.year}}',
				'{{ga_code}}',
				'{{uid}}'
			],
			[
				$stats['position'],
				$stats['eta'],
				$stats['total'],
				$ip,
				htmlentities( $_SERVER['HTTP_HOST'] ),
				date( 'Y' ),
				$this->gaCode,
				$uid
			],
			$template );

		header( 'HTTP/1.1 503 Service Temporarily Unavailable' );
		header( 'Status: 503 Service Temporarily Unavailable' );
		header( 'Retry-After: 300' );

		echo $template;
		exit();
	}
}