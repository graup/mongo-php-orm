<?php
/**
 * A base class for database abstraction objects.
 *
 * Objects can be directly saved into a MongoDB collection.
 * Cleans up properties before inserting;
 * all public properties are written to DB, others are excluded.
 *
 * Copyright (c) 2013 Paul Grau
 * Licensed under the MIT (http://www.opensource.org/licenses/mit-license.php) license.
 */
class DBObject {
	private $collection;
	
	/* The following constants need to be defined in the subclass */

	/**
	 * Name of the MongoDB Collection (required)
	 * @interface const collectionName = 'aCollectionName';
	 */

	/**
	 * Use sequential IDs (optional, defaults to false)
	 * @interface const use_sequence_id = true/false;
	 */

	/**
	 * Use random IDs (optional, defaults to false)
	 * @interface const use_random_id = true/false;
	 */

	/**
	 * Length of random IDs (optional, defaults to 6)
	 * @interface const random_id_length = (int);
	 */
		
	/**
	 * Constructor
	 *
	 * Tests MongoDB connectivity.
	 * Opens specified collection.
	 * Retrieves data if $id is set.
	 */
	function __construct($id=NULL) {
		global $db;
		
		if (!is_a($db,'MongoDB'))
			trigger_error("DBObjects rely on MongoDB connection",E_USER_ERROR);
		
		if (!defined(get_class($this).'::collectionName'))
			trigger_error("Constant collectionName undefined in ".get_class($this),E_USER_ERROR);
		
		$this->collection = $db->selectCollection(constant(get_class($this).'::collectionName'));
		
		// if $id given, read data from DB
		if ($id) {
			if (!$this->get($id)) throw new NoDocumentException();
			
			// $this->_id is added if query was successful
		}
	}
	
	
	/**
	 * Reads data from db and extracts keys to object properties
	 */
	public function get($id) {
		
		if (strlen($id) == 24) // Convert strings of right length to MongoID
			$id = new MongoId($id);

		$where = array("_id"=>$id);

		$result = $this->collection->findOne($where);

		if (is_array($result)) {
			$this->_id = $id;
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
	function find($where,$fields) {
		return $this->collection->find($where,$fields);
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
		
		/* if has no _id yet or it is forced, insert */
		if (!$this->_id || $options['force_insert']) {
			/* If forced, ids will still be overwritten if not explicitly preserved */
			if (!$options['preserve_id']) {
				/* If subclass has use_sequence_id defined */
				if (defined(get_class($this).'::use_sequence_id') &&
					true===constant(get_class($this).'::use_sequence_id')) {
					$data['_id'] = self::getNextId();
				}
				/* If subclass has use_random_id defined */
				if (defined(get_class($this).'::use_random_id') &&
					true===constant(get_class($this).'::use_random_id')) {
					
						if (defined(get_class($this).'::random_id_length'))
							$length = constant(get_class($this).'::random_id_length');
						else
							$length = 6;
							
						$min = pow(10,$length-1);
						$max = pow(10,$length) - 1;
						$data['_id'] = self::getRandomId($min,$max);
				}
			}
						
			$this->collection->insert($data);
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
	public function getNextId() {
		global $db;
		$next_id_key = constant(get_class($this).'::collectionName');
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
	public function getRandomId($min,$max) {
		do {
			$id = rand($min,$max);
			$res = $this->collection->findOne(array('_id'=>$id),array('_id'=>1));
		} while ($res==true);
		
		return (string)$id;
	}
	
	/**
	 * Updates object in db.
	 * This is a direct wrapper for $collection->update(...),
	 * so atomic operations are usable here.
	 */
	public function updateDocument($data,$opts=array()) {
		try {
			$where = array("_id" => $this->_id);
			$this->collection->update($where, $data, $opts);
		} catch(MongoException $e) {
			// You really do not want errors to happen here.
			error_log($e);
		}
	}
	
	/**
	 * Deletes object from db
	 */
	public function delete() {
		if (!$this->_id) return false;
		$where = array("_id"=>$this->_id);
		return $this->collection->remove($where);
	}
	
	/**
	 * Wrapper for ensureIndex
	 */
	public function ensureIndex($array) {
		if ($this->collection)
			$this->collection->ensureIndex($array);
	}
	
	/**
	 * Get number of objects on collection
	 */
	static function count($query=array()) {
		global $db;
		$classname = get_called_class();
		$coll = $db->selectCollection(constant($classname.'::collectionName'));
		if (!$query) $query = array();
		return $coll->count($query);
	}
	
	/**
	 * Cleans up object properties.
	 * Excludes all properties which are private or protected, as the mongo driver cannot handle them.
	 *
	 * @return array of public object properties
	 */
	private function toDB() {
		$reflect = new ReflectionObject($this);
    	foreach ($reflect->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
      		$return[$prop->getName()] = $prop->getValue($this);
    	}
		return $return;
	}
	
	/**
	 * Cleans up array for JSON representation
	 * e.g. stripping MongoID stuff
	 */
	private function cleanUpJSON($array) {
		$prepared = array();
		foreach ($array as $k => $v) {
			if ($k === '_id' || $k === '$id') {
				$v = (string) $v;
			}
			if (is_array($v)) {
				$prepared[$k] = $this->cleanUpJSON($v);
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
		$json = json_encode($this->cleanUpJSON($data));
		if ($json) {
			return $json;
		} else return NULL;
	}
	
	/**
	 * Search for objects in collection
	 *
	 * First searches for IDs of all objects matching the query,
	 * then generates a (real) object for them.
	 * Could be overwritten by subclasses.
	 * @todo a nice implementation to simply define keys in subclass by which to search here.
	 * @return array of objects
	 */
	static function search($query=NULL,$sort=NULL,$opts=NULL) {
		global $db;
		
		$classname = get_called_class();

		if (!$query) $query = array();
		
		$cursor = $db->selectCollection( constant($classname.'::collectionName') );
		$res = $cursor->find($query, array('_id'=>1));

		if ($sort) $res = $res->sort($sort);
		if ($opts['skip'])  $res->skip($opts['skip']);
		if ($opts['limit']) $res->limit($opts['limit']);

		$set = array();
		if (is_object($res)):
			foreach($res as $entry) {
				try {
					$obj = new $classname( $entry['_id'] );
					$set[] = $obj;
				} catch (NoDocumentException $e) {
					// Cannot really happen since only objects for valid IDs are retrieved.
				}
			}
		endif;
		
		if (!$set) $set = false;
		return $set;
	}
}

/**
 * Exception thrown when trying to access a non existing object
 */
class NoDocumentException extends Exception {
	function __construct($message = null, $code = 0) {
		$message = "There is no document with this id.";
		$code = 101;
		parent::__construct($message,$code);
	}
}