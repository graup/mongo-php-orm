<?php
/**
 * Unit tests testing basic functionality and implementation
 * To run tests, start a MongoDB instance and run 
 * `phpunit --bootstrap init.php test.php`
 */

class TestModelIncomplete extends DBObject {

}

class TestModel extends DBObject {
    const collectionName = 'test_models';
    public $name;
    private $_secret;

    public function setSecret($value) {
        $this->_secret = $value;
    }
    public function getSecret() {
        return $this->_secret;
    }
}

class TestIncrementalModel extends DBObject {
    const collectionName = 'test_models_inc';
    const use_sequence_id = true;
}


class ModelTest extends MongoDBTest {
    /**
     * @expectedException IncompleteImplementationException
     */
    public function testModelImplementation() {
        $m = new TestModelIncomplete();
    }

    /**
     * @expectedException NoDocumentException
     */
    public function testNoDocumentException() {
        $m = new TestModel('not_existing_id');
    }

    /** 
     * Test inserting new instance
     */
    public function testUpdatePerformsInsert() {
        $m = new TestModel();
        $m->name = "Foo Bar";
        $m->update();
        $this->assertObjectHasAttribute('_id', $m);
    }

    /** 
     * Test delete method
     */
    public function testDelete() {
        $m = new TestModel();
        $this->assertEquals($m->delete(), false);
        $m->update();
        $this->assertEquals($m->delete(), true);
    }

    /** 
     * Test if update correctly ignores private attributes
     */
    public function testUpdateIgnoresPrivate() {
        $m = new TestModel();
        $m->setSecret('foo');
        $m->update();
        // After reloading object, _secret should be back to default value
        $m = new TestModel( $m->_id );
        $this->assertNotEquals($m->getSecret(), 'foo');
    }

    /** 
     * Test sequence IDs
     */
    public function testSequenceIDs() {
        TestIncrementalModel::getCollection()->drop();
        for ($i = 1; $i <= 3; $i++) {
            $m = new TestIncrementalModel();
            $m->update();
            $this->assertEquals($m->_id, $i);
        }
    }

    /** 
     * Test MyModel::count()
     */
    public function testCount() {
        TestModel::getCollection()->drop();
        for ($i = 1; $i <= 3; $i++) {
            $m = new TestModel();
            $m->update();
        }
        $this->assertEquals(TestModel::count(), 3);
    }

    /** 
     * Test iterator implementation
     */
    public function testIteratorClass() {
        $iterator = new DBObjectIterator();
        $this->assertEquals(iterator_to_array($iterator), array());
    }

    /** 
     * Test iterator's general usage
     */
    public function testIteratorWithModels() {
        TestModel::getCollection()->drop();
        $names = array('A', 'B', 'C');
        foreach($names AS $name) {
            $m = new TestModel();
            $m->name = $name;
            $m->update();
        }

        $objects = TestModel::search(array(), array('name'=>1));
        $i = 0;
        foreach($objects AS $obj) {
            $this->assertEquals($names[$i++], $obj->name);
        }
    }

    /** 
     * Test iterator's toJSON() method
     */
    public function testIteratorJSON() {
        TestModel::getCollection()->drop();
        $names = array('A', 'B', 'C');
        foreach($names AS $name) {
            $m = new TestModel();
            $m->name = $name;
            $m->update();
        }

        $json = TestModel::search(array(), array('name'=>1))->toJSON();
        $data = json_decode($json, true);
        $i=0;
        foreach($data AS $row) {
            $this->assertEquals($names[$i++], $row['name']);
        }
    }

    /** 
     * Test if JSON contains the correct data
     */
    public function testJSON() {
        $m = new TestModel();
        $m->foo = 'bar';
        $json = $m->toJSON();
        $data = json_decode($json, true);
        // On model level added public attribute
        $this->assertArrayHasKey('foo', $data);
        // On class level declared public attribute
        $this->assertArrayHasKey('name', $data);
        // On class level declared private attribute
        $this->assertArrayNotHasKey('_secret', $data);
    }
}
