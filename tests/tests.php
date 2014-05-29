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

    public function testUpdatePerformsInsert() {
        $m = new TestModel();
        $m->name = "Foo Bar";
        $m->update();
        $this->assertObjectHasAttribute('_id', $m);
    }

    public function testDelete() {
        $m = new TestModel();
        $this->assertEquals($m->delete(), false);
        $m->update();
        $this->assertEquals($m->delete(), true);
    }

    public function testUpdateIgnoresPrivate() {
        $m = new TestModel();
        $m->setSecret('foo');
        $m->update();
        // After reloading object, _secret should have default value
        $m = new TestModel( $m->_id );
        $this->assertNotEquals($m->getSecret(), 'foo');
    }

    public function testSequenceIDs() {
        for ($i = 1; $i < 5; $i++) {
            $m = new TestIncrementalModel();
            $m->update();
            $this->assertEquals($m->_id, $i);
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
        // On model level added public variable
        $this->assertArrayHasKey('foo', $data);
        // On class level declared public variable
        $this->assertArrayHasKey('name', $data);
    }
}
