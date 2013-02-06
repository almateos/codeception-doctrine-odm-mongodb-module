<?php
/**
 * Allows integration and testing for projects with Doctrine ODM MongoDB.
 *
 * Doctrine ODM uses DocumentManager to perform all database operations.
 * As the module uses active connection and active document manager, instance of this object should be passed to this module.
 *
 * It can be done in bootstrap file, by setting static $dm property:
 *
 * ``` php
 * <?php
 *
 * \Codeception\Module\DoctrineODMMongoDB::$dm = $dm
 *
 * ```
 *
 */

namespace Codeception\Module;

use Doctrine\ODM\MongoDB\DocumentManager,
    Doctrine\ODM\MongoDB\Query\Builder;


class DoctrineODMMongoDB extends \Codeception\Module{

    /***
     * @var \Doctrine\ODM\MongoDB\DocumentManager
     */
    public static $dm;

    protected $requiredFields = array();

    public function _before(\Codeception\TestCase $test){
        if (!self::$dm) throw new \Codeception\Exception\ModuleConfig(__CLASS__,
            "DoctrineMongoDbODM module requires DocumentManager explictly set.\n" .
                "You can use your bootstrap file to assign the DocumentManager:\n\n" .
                '\Codeception\Module\DoctrineMongoDbODM::$dm = $dm');

        if (!self::$dm instanceof \Doctrine\ODM\MongoDB\DocumentManager) throw new \Codeception\Exception\ModuleConfig(__CLASS__,
            "Document Manager was not properly set.\n" .
                "You can use your bootstrap file to assign the DocumentManager:\n\n" .
                '\Codeception\Module\DoctrineMongoDbODM::$dm = $dm');

        self::$dm->getConnection()->connect();
    }

    public function _after(\Codeception\TestCase $test){
        if (!self::$dm) throw new \Codeception\Exception\ModuleConfig(__CLASS__,
            "DoctrineMongoDbODM module requires DocumentManager explictly set.\n" .
                "You can use your bootstrap file to assign the DocumentManager:\n\n" .
                '\Codeception\Module\DoctrineMongoDbODM::$dm = $dm');
        $this->clean();
    }

    protected function clean(){
        self::$dm->clear();
    }

    /**
     * Performs $dm->flush();
     */
    public function flushToDatabase()
    {
        self::$dm->flush();
    }

    /**
     * Adds document to repository and flushes. You can redefine it's properties with the second parameter.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->persistDocument($user, array('name' => 'Miles'));
     * ```
     *
     * @param $obj
     * @param array $values
     */
    public function persistDocument($obj, $values = array()) {

        if ($values) {
            $reflectedObj = new \ReflectionClass($obj);
            foreach ($values as $key => $val) {
                $property = $reflectedObj->getProperty($key);
                $property->setAccessible(true);
                $property->setValue($obj, $val);
            }
        }

        self::$dm->persist($obj);
        self::$dm->flush();
    }

    /**
     * Mocks the repository.
     *
     * With this action you can redefine any method of any repository.
     * Please, note: this fake repositories will be accessible through document manager till the end of test.
     *
     * Example:
     *
     * ``` php
     * <?php
     *
     * $I->haveFakeRepository('Document\User', array('findByUsername' => function($username) {  return null; }));
     *
     * ```
     *
     * This creates a stub class for Entity\User repository with redefined method findByUsername, which will always return the NULL value.
     *
     * @param $className
     * @param array $methods
     */
    public function haveFakeDocumentRepository($className, $methods = array())
    {
        $dm = self::$dm;

        $metadata = $dm->getMetadataFactory()->getMetadataFor($className);
        $customRepositoryClassName = $metadata->customRepositoryClassName;

        if (!$customRepositoryClassName) $customRepositoryClassName = '\Doctrine\ODM\MongoBD\DocumentRepository';

        $mock = \Codeception\Util\Stub::make($customRepositoryClassName, array_merge(array('documentName' => $metadata->name,
            'dm'         => $dm,
            'class'      => $metadata), $methods));
        $dm->clear();
        $reflectedEm = new \ReflectionClass($dm);
        if ($reflectedEm->hasProperty('repositories')) {
            $property = $reflectedEm->getProperty('repositories');
            $property->setAccessible(true);
            $property->setValue($dm, array_merge($property->getValue($dm), array($className => $mock)));
        } else {
            $this->debugSection('Warning','Repository can\'t be mocked, the DocumentManager class doesn\'t have "repositories" property');
        }
    }

    /**
     * Flushes changes to database executes a query defined by array.
     * It builds query based on array of parameters.
     * You can use document associations to build complex queries.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->seeInDocumentRepository('User', array('name' => 'davert'));
     * $I->seeInDocumentRepository('User', array('name' => 'davert', 'Company' => array('name' => 'Codegyre')));
     * $I->seeInDocumentRepository('Client', array('User' => array('Company' => array('name' => 'Codegyre')));
     * ?>
     * ```
     *
     * Fails if record for given criteria can\'t be found,
     *
     * @param $document
     * @param array $params
     */
    public function seeInDocumentRepository($document, array $params = array()) {
        $res = $this->proceedSeeInRepository($document, $params);
        $this->assert($res);
    }

    /**
     * Flushes changes to database and performs ->findOneBy() call for current repository.
     *
     * @param $document
     * @param array $params
     */
    public function dontSeeInDocumentRepository($document, array $params = array()) {
        $res = $this->proceedSeeInRepository($document, $params);
        $this->assertNot($res);
    }

    protected function proceedSeeInRepository($document, array $params = array())
    {
        // we need to store to database...
        self::$dm->flush();
        $qb = self::$dm->getRepository($document)->createQueryBuilder();
        $this->buildSelectParams($qb, $params);
        $this->debug($qb->debug());
        $res = $qb->getQuery()->toArray();

        return array('True', (count($res) > 0), "$document with " . json_encode($params));
    }


    /**
     * @param \Doctrine\ODM\MongoDB\Query\Builder $qb
     * @param array $params
     * @return $this
     */
    protected function buildSelectParams(Builder $qb, array $params){
        foreach($params as $key=>$value){
            $qb->field($key)->equals($value);
        }
        return $this;
    }
}