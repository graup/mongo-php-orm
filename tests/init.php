<?php

require_once('../class.dbobject.php');

class MongoDBTest extends PHPUnit_Framework_TestCase {
    protected function setUp() {
        global $db;
        $m = new Mongo();
        $db = new MongoDB($m, "phpunit");
    }

    protected function tearDown() {
    	global $db;
    	$db->drop();
    }
}