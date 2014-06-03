<?php
/**
 * Connects to MongoDB server.
 * Stops code execution on connection error.
 *
 * You do not need to use this file, just any place to globally assign a MongoDB instance to $db.
 *
 * Define constants in your config or here
 */

// define('MDB_USERNAME', '');
// define('MDB_PASSWORD', '');
// define('MDB_HOST', 'localhost');
// define('MDB_NAME', '');

if (!class_exists('Mongo')) {
	die("Mongo class not existing. Did you install the PHP MongoDB extension?");
}

try {
	$conn = new Mongo("mongodb://".MDB_USERNAME.":".MDB_PASSWORD."@".MDB_HOST."/".MDB_NAME);
	$db = $conn->selectDB(MDB_NAME);
} catch (MongoConnectionException $e) {
	die($e->getMessage()); // In production you might want to turn this off.
}
