# MongoDB PHP ORM

This is a simple class mapping PHP models to MongoDB documents. This way, you can directly save PHP objects into your database, making development even faster.

Requirements: PHP 5.3+

## Motivation

You cannot directly insert arbitrary PHP objects into MongoDB because the driver cannot handle private or protected attributes. So, before saving, this class filters all public attributes via the Reflection extension available since PHP 5.

It also abstracts some other tasks which you might encounter while working with the database, for example auto-incrementing integer IDs.

## Usage

	require_once("mongo_connect.php");
	require_once('class.dbobject.php');
	
	class MyModel extends DBObject {
		public $name;
		public $description;
		private $dont_save_this;
	}
	
	$obj = new MyModel();
	$obj->name = "Foo";
	$obj->description = "Bar";
	$obj->update();
	
	$obj->some_other_public_var = "Baz";
	$obj->update();

	$objects = MyModel::search(array('name'=>'Foo'));
	foreach($objects as $instance) {
		echo is_a($instance, 'MyModel'); // true :)
	}

You do not need to create any collections, everything is generated on the fly. Rapid development at its best.

## Documentation

### Constants

Your model can define a couple of constants.

* `const collectionName = 'aCollectionName';`
Defines the MongoDB collection used for this model. Required.

* `const use_sequence_id = true/false;`
Defines if auto-incrementing, sequential IDs should be used instead of the default MongoID. Optional, defaults to false.

* `const use_random_id = true/false;`
Defines if a random ID should be used. This is an experimental feature as it is not concurrency proof. Optional, defaults to false.

* `const random_id_length = (int);`
If random IDs are used, this defines the length of the generated IDs. 5 means IDs range from 10000 to 99999. Optional, defaults to 6.

### MyModel::__construct( [$id] )

The constructor retrieves any saved document if an ID is passed. It essentially does a find with the condition being `array('_id' => $id);` You should pass MongoID objects if you use them, but the class will also automatically convert strings with a matching length to MongoIDs.

Of course, if you have your own constructor, it should look like this:

	function __construct($id=null) {
		parent::__construct($id);
		...
	}
	
Throws a `NoDocumentException` if an ID was passed but no document was found. Suggested usage:

	try {
		$obj = new MyModel($id);
	} catch(NoDocumentException $e) {
		// handle error
	}

### MyModel::count( [$query] )

Counts the number of objects in the collection matching the query array.

### MyModel::search( [$query, [$sort, [$opts]]] )

Returns an iterator over MyModel objects matching the query array. Instances are only retrieved when accessed.

For details on the `$query`, check [MongoCollection::find](http://php.net/manual/en/mongocollection.find.php)

`$sort` is an array just like the [default sort function](http://php.net/manual/en/mongocursor.sort.php) uses.

Options can be

* `(int) $opts['skip']`: Skip n entries from the result
* `(int) $opts['limit']`: Limit result to n entries

If you want all instances at once, use something like `iterator_to_array($iterator);`.
Obviously the iterator approach is preferred for memory reasons.

### MyModel::searchOne( $query )

Returns a single model instance matching the query. Behind the scenes this does the same as `new MyModel($id)`,
simply using a custom quert rather than `('_id'=>$id)`.
Be aware that this does not catch the case of more than one document matching the query. The driver will
simply return the first one.

Throws a `NoDocumentException` if no document was found. Suggested usage:

	try {
		$obj = MyModel::searchOne($my_query);
	} catch(NoDocumentException $e) {
		// handle error
	}

### $obj->update( [$options] )

Updates the document if already existing, otherwise performs an insert. Updates are handled asynchronously.

After inserting, the `_id` field is added to the object.
All public attributes of the object are converted to the document.

Optional parameters:

* `(boolean) $options['force_insert']` (default: false) 
If true, performs an insert even though the object contained an `_id`. This way you can use your own IDs instead of the default MongoID.
* `(boolean) $options['preserve_id']` (default: false)
Set this to true of you want to skip the added functionality of sequential or random IDs.

### $obj->delete()

Removes the object from the collection. Returns true on success, false if the object has no ID or something else went wrong.

### MyModel::ensureIndex($array)

A wrapper for [ensureIndex()](http://php.net/manual/en/mongocollection.ensureindex.php) on the collection.

### MyModel::getCollection()

Returns the MongoDB collection object.

### MyModel::getNextId()

Returns the next usable sequential ID and handles auto increment.
Uses the collection name as key in a seperate 'counter' collection.

This is concurrency proof due to MongoDB's `findandmodify`.

You don't need to call this function manually, please refer to the Constants section.

### MyModel::getRandomId($min, $max)

Returns a random ID in the range of $min and $max. The function makes sure that the generated ID is not already taken, but is still not concurrency proof, so handle with care.

You don't need to call this function manually, please refer to the Constants section.

### $obj->getCreated()

If MongoIDs are used as _id, this returns the timestamp of the creation date extracted form the ID.

### $obj->toJSON()

Returns a JSON representation of the object. MongoIDs are converted to Strings.

The iterator returned by `search()` implements this as well, so you can do something like `MyModel::search()->toJSON()`.

## Development

I have been using this class in a real application for about a year and never had any problems. I will of course update it whenever possible. In the meantime, I'm also happy to receive comments or pull requests to improve the class, especially since this is my first publicly available code.

## Caveat

One more thing. Make sure your data is properly UTF-8 formatted as MongoDB cannot handle anything else.

## License

[MIT License](http://opensource.org/licenses/MIT)

## Author

Paul Grau

Twitter: @graup
