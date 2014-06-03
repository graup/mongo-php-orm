<?php

require_once('../class.dbobject.php');

class MongoDBTest extends PHPUnit_Framework_TestCase {
	private static $db;

	protected function setUp() {
		global $db;
		if (!self::$db) {
			// This will setup a new database instance, so do only once per run
			$m = new Mongo();
			self::$db = new MongoDB($m, "phpunit");
			self::$db->drop();
		}
		$db = self::$db;
	}

	public static function tearDownAfterClass() {
		self::$db->drop();
	}
}
