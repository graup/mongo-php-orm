<?php
/**
 * A base class for database abstraction objects.
 *
 * Objects can be directly saved into a MongoDB collection.
 * Cleans up properties before inserting;
 * all public properties are written to DB, others are excluded.
 *
 * Copyright (c) 2014 Paul Grau
 * Licensed under the MIT (http://www.opensource.org/licenses/mit-license.php) license.
 */
class DBObject {
	private static $_collection;
	
	/* The following constants need to be defined in the subclass */

	/**
	 * Name of the MongoDB Collection (required)
	 * @abstract const collectionName = 'aCollectionName';
	 */

	/**
	 * Use sequential IDs (optional, defaults to false)
	 * @abstract const use_sequence_id = true/false;
	 */

	/**
	 * Use random IDs (optional, defaults to false)
	 * @abstract const use_random_id = true/false;
	 */

	/**
	 * Length of random IDs (optional, defaults to 6)
	 * @abstract const random_id_length = (int);
	 */
		
	/**
	 * Tests connection and retrieves data if $id is set.
	 */
	public function __construct($id=NULL) {
		// Test connection
		self::getCollection();

		// if $id given, read data from DB
		if ($id) {
			if (strlen($id) == 24) // Convert strings of right length to MongoID
				$id = new MongoId($id);
			if (!$this->retrieveDocument(array('_id'=>$id))) {
				throw new NoDocumentException("There is no document with this id.");
			}
		}
	}

	/**
	 * Returns collection object and assigns statically if not yet set.
	 */
	public static function getCollection() {
		global $db;
		if (!self::$_collection) {
			if (!is_a($db, 'MongoDB')) {
				trigger_error("DBObjects rely on MongoDB connection", E_USER_ERROR);
			}
			$classname = get_called_class();
			if (!defined($classname.'::collectionName')) {
				throw new IncompleteImplementationException("Constant collectionName undefined in $classname");
			}
			self::$_collection = $db->selectCollection(constant($classname.'::collectionName'));
		}
		return self::$_collection;
	}
	
	
	/**
	 * Reads data from db and extracts keys to object properties
	 */
	public function retrieveDocument($query) {
		$result = self::getCollection()->findOne($query);

		if (is_array($result)) {
			foreach($result AS $key=>$value)
				$this->$key = $value;
			return true;
		} else {
			// No result = No document with this id!
			return false;
		}
	}
	
	/**
	 * @return creation timestamp of object
	 */
	function getCreated() {
		$id = $this->_id;
		if (is_a($id,'MongoId'))
			return $id->getTimestamp();
	}
	
	/**
	 * Wrapper for find in collection
	 */
	function find($where, $fields) {
		return self::getCollection()->find($where,$fields);
	}

	/**
	 * Updates this object in db.
	 *
	 * Replaces complete document [asynchronously!].
	 * Inserts data if no _id set.
	 * set $options['force_insert']=true if _id was specified but document needs to be inserted nevertheless.
	 *
	 * Subclasses can change the ID creating behaviour by defining constants:
	 * - const use_sequence_id: will use an incrementing ID. Uses a helper collection 'counter' to keep track of taken ids
	 * - const use_random_id: will use a random ID. Makes sure not to use a already taken ID.
	 * - const random_id_length: define length of random ID. (5 means ID ranges from 10000 to 99999)
	 */
	public function update($options=array()) {
		$data = $this->toDB();
		
		$classname = get_class($this);
		/* if has no _id yet or it is forced, insert */
		if (!isset($this->_id) || isset($options['force_insert']) && $options['force_insert']) {
			/* If forced, ids will still be overwritten if not explicitly preserved */
			if (!isset($options['preserve_id']) || !$options['preserve_id']) {
				/* If subclass has use_sequence_id defined */
				if (defined($classname.'::use_sequence_id') &&
					true===constant($classname.'::use_sequence_id')) {
					$data['_id'] = self::getNextId();
				}
				/* If subclass has use_random_id defined */
				if (defined($classname.'::use_random_id') &&
					true===constant($classname.'::use_random_id')) {
					
						if (defined($classname.'::random_id_length'))
							$length = constant($classname.'::random_id_length');
						else
							$length = 6;
							
						$min = pow(10,$length-1);
						$max = pow(10,$length) - 1;
						$data['_id'] = self::getRandomId($min,$max);
				}
			}
						
			self::getCollection()->insert($data);
			$this->_id = $data['_id'];
		} else {	
			$this->updateDocument($data);
		}
	}
	
	/**
	 * Gets next id for key and increments it.
	 *
	 * Uses collection name as key in 'counter' collection
	 * This is concurrency proof due to mongodb's findandmodify.
	 * @return (int) $id
	 */
	public static function getNextId() {
		global $db;
		$next_id_key = constant(get_called_class().'::collectionName');
		$res = $db->command(
			array("findandmodify" => "counter",
				"query" => array('_id'=>$next_id_key),
				"update" => array('$inc'=>array('seq'=>1)),
				'new'=> true,
				'upsert'=>true
			)); 
		$id = $res['value']['seq'];
		
		return (int)$id;
	}
	
	/**
	 * Generates a random ID between min and max.
	 *
	 * If ID is already taken, tries again.
	 * This is actually NOT concurrency proof, so use a high $max value
	 * @return (string) $id
	 * @example getRandomId(100000,999999);
	 */
	public static function getRandomId($min,$max) {
		do {
			$id = rand($min,$max);
			$res = self::getCollection()->findOne(array('_id'=>$id),array('_id'=>1));
		} while ($res==true);
		
		return (string)$id;
	}
	
	/**
	 * Updates object in db.
	 * This is a direct wrapper for $collection->update(...),
	 * so atomic operations are usable here.
	 */
	public function updateDocument($data, $opts=array()) {
		try {
			$where = array("_id" => $this->_id);
			self::getCollection()->update($where, $data, $opts);
		} catch(MongoException $e) {
			// You really do not want errors to happen down here.
			error_log($e);
		}
	}
	
	/**
	 * Deletes object from db
	 */
	public function delete() {
		if (!isset($this->_id)) return false;
		$where = array("_id"=>$this->_id);
		return self::getCollection()->remove($where);
	}
	
	/**
	 * Wrapper for ensureIndex
	 */
	public static function ensureIndex($array) {
		if (self::$_collection) {
			self::getCollection()->ensureIndex($array);
		}
	}
	
	/**
	 * Get number of objects on collection
	 */
	public static function count($query=array()) {
		if (!$query) $query = array();
		return self::getCollection()->count($query);
	}
	
	/**
	 * Returns array of cleaned up model attributes.
	 * Excludes all properties which are private or protected,
	 * as the mongo driver cannot handle them.
	 *
	 * @return array of public object properties
	 */
	public function toDB() {
		$reflect = new ReflectionObject($this);
		$return = array();
		foreach ($reflect->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
			$return[$prop->getName()] = $prop->getValue($this);
		}
		return $return;
	}
	
	/**
	 * Cleans up array for JSON representation
	 * e.g. stripping MongoID stuff
	 */
	public static function cleanUpJSON($array) {
		$prepared = array();
		foreach ($array as $k => $v) {
			if ($k === '_id' || $k === '$id') {
				$v = (string) $v;
			}
			if (is_array($v)) {
				$prepared[$k] = self::cleanUpJSON($v);
			} else {
				$prepared[$k] = $v;
			}
		}
		return $prepared;
	}
	
	/**
	 * @return JSON representation
	 */
	public function toJSON() {
		$data = $this->toDB();
		ksort($data);
		$json = json_encode(self::cleanUpJSON($data));
		if ($json) {
			return $json;
		} else return NULL;
	}
	
	/**
	 * Search for objects in collection
	 *
	 * First searches for IDs of all objects matching the query,
	 * then returns an iterator over these IDs
	 * @return DBObjectIterator over model instances
	 */
	public static function search($query=NULL, $sort=NULL, $opts=NULL) {
		global $db;
		
		$classname = get_called_class();

		if (!$query) $query = array();
		
		$cursor = $db->selectCollection( constant($classname.'::collectionName') );
		$res = $cursor->find($query, array('_id'=>1));

		if ($sort) $res = $res->sort($sort);
		if ($opts['skip'])  $res->skip($opts['skip']);
		if ($opts['limit']) $res->limit($opts['limit']);

		$object_ids = array();
		if (is_object($res)) {
			foreach($res as $entry) {
				$object_ids[] = $entry['_id'];
			}
		}
		
		return new DBObjectIterator($classname, $object_ids);
	}

	/**
	 * Search for one object in collection
	 * Attention! If the query matches more than one document, the MongoDB
	 * simply returns the first one. There's no way to catch that.
	 *
	 * @return model instance matching query
	 */
	public static function searchOne($query) {
		$classname = get_called_class();
		$instance = new $classname();
		if (!$instance->retrieveDocument($query) || !isset($instance->_id)) {
			throw new NoDocumentException('There is no document matching this query.');
		}
		return $instance;
	}
}


/**
 * Iterator over set of object ids
 * Creates model instances on the fly, saving memory
 * If you want the whole set as directly accessible objects, use something
 * like $array = iterator_to_array($iterator);
 */
class DBObjectIterator implements Iterator {
	private $position = 0;
	private $object_ids = array();
	private $classname = '';

	public function __construct($classname='DBObject', $object_ids=array()) {
		$this->object_ids = $object_ids;
		$this->classname = $classname;
	}

	/**
	 * Returns all objects in set as JSON
	 */
	function toJSON() {
		$objects = array();
		foreach($this AS $object) {
			$objects[] = $object->toDB();
		}
		return json_encode(DBObject::cleanUpJSON($objects));
	}

	function rewind() {
		$this->position = 0;
	}

	function current() {
		$id = $this->object_ids[$this->position];
		try {
			return new $this->classname($id);
		} catch (NoDocumentException $e) {
			// The object was removed in the meantime. Try next.
			$this->next();
			if ($this->valid())
				return $this->current();
			else
				return null;
		}

	}

	function key() {
		return $this->object_ids[$this->position];
	}

	function next() {
		++$this->position;
	}

	function valid() {
		return isset($this->object_ids[$this->position]);
	}
}


class NoDocumentException extends Exception {
}

class IncompleteImplementationException extends Exception {
}
