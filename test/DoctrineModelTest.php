<?php
namespace Test;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Exception;
use Minwork\Basic\Model\Model;
use Minwork\Basic\Model\ModelBinder;
use Minwork\Basic\Model\ModelsList;
use Minwork\Database\Doctrine\Database as DoctrineDatabase;
use Minwork\Database\Interfaces\ColumnInterface;
use Minwork\Database\Interfaces\DatabaseInterface;
use Minwork\Database\Interfaces\TableInterface;
use Minwork\Database\Utility\Query;
use Minwork\Helper\DateHelper;
use Minwork\Helper\Random;
use Minwork\Operation\Basic\Create;
use Minwork\Operation\Basic\Delete;
use Minwork\Operation\Basic\Update;
use Minwork\Storage\Interfaces\DatabaseStorageInterface;
use Minwork\Validation\Object\Validator;
use Minwork\Validation\Utility\Field;
use Minwork\Validation\Utility\Rule;
use PHPUnit\Framework\TestCase;
use Test\Utils\DatabaseProvider;
use Test\Utils\Timer;
use Throwable;

class DoctrineModelTest extends TestCase
{
    /**
     * @var DatabaseInterface
     */
    protected static $database;
    /**
     * @var TableInterface
     */
    protected static $table;
    /**
     * @var ColumnInterface
     */
    protected static $column;

    /**
     * @var array
     */
    protected static $columnTypes;
    /**
     * @param array $config
     * @throws DBALException
     */
    private static function setupDoctrine(array $config) {
        self::$database = new DoctrineDatabase($config);
        self::$table = 'Minwork\Database\Doctrine\Table';
        self::$column = 'Minwork\Database\Doctrine\Column';
        self::$columnTypes = [
            'int' => Type::INTEGER,
            'string' => Type::STRING,
            'datetime' => Type::STRING,
            'bool' => Type::BOOLEAN,
            'text' => Type::TEXT,
        ];
    }

    public static function setUpBeforeClass()
    {
        $config = DatabaseProvider::getConfig();
        try {
            self::setupDoctrine($config);
        } catch (Throwable $e) {
            echo <<<EOT
Database test: Cannot connect to database server ({$config['driver']}), used sqlite instead.
Error({$e->getCode()}): {$e->getMessage()}

Try specifying connection parameters via environment variables.

Currently used:
DB_HOST = '{$config['host']}' (default: 'localhost')
DB_NAME = '{$config['dbname']}' (default: 'test')
DB_USER = '{$config['user']}' (default: 'root')
DB_PASS = '{$config['password']}' (default: '')
DB_CHARSET = '{$config['charset']}' (default: '{$config['defaultCharset']}')
DB_DRIVER = '{$config['driver']}' (default: '{$config['defaultDriver']}')
EOT;
            $config['driver'] = $config['defaultDriver'];
            /** @noinspection PhpUnhandledExceptionInspection */
            self::setupDoctrine($config);
        }
    }

    public function testModelFlow()
    {
        $validatorFunction = function ($value, $arg1, $arg2) {
            return ! empty($value) && $arg1 && ! $arg2;
        };
        $data = [
            'name' => 'test',
            'email' => 'abc@def.com',
            'change_date' => DateHelper::now()
        ];
        $newData = [
            'name' => 'test2',
            'change_date' => DateHelper::addHours(2, DateHelper::now())
        ];
        $newId = 'unexisting';

        Timer::start("#1 Table creation");
        /** @var $table TableInterface|DatabaseStorageInterface */
        $table = new self::$table(self::$database, 'test', [
            new self::$column('id', self::$columnTypes['int'], null, false, true, true),
            new self::$column('name', self::$columnTypes['string']),
            new self::$column('email', self::$columnTypes['string']),
            new self::$column('change_date', self::$columnTypes['datetime'])
        ]);
        $table->create(true);
        Timer::start("#1 Model operations");

        $model = new Model($table);
        $validator = new Validator(
            new Field('name', [
                new Rule('Minwork\Helper\Validation::isNotEmpty'),
                new Rule('Minwork\Helper\Validation::isAlphabeticOnly'),
                //new Rule('Minwork\Helper\Validation::isInt')
            ]),
            new Field('email', [
                new Rule('Minwork\Helper\Validation::isEmail', null, null, true, false),
                new Rule($validatorFunction, null, null, true,true, false)
            ])
        );
        $this->assertSame($data, $model->setData($data)
            ->getData());
        $this->assertTrue($model->validateThenExecute(new Create(), $validator, $data));
        $this->assertTrue($model->exists());
        $this->assertNotNull($model->getId());
        $this->assertIsInt($model->getId());
        $this->assertEquals([
            'id' => 1
        ], $model->getNormalizedId());
        $this->assertEquals(1, $model->getId());
        $this->assertEquals($data, $model->getData());
        $this->assertEquals($data['name'], $model->getData('name'));
        $this->assertEquals(array_intersect_key($data, array_flip([
            'name',
            'email'
        ])), $model->getData([
            'name',
            'email'
        ]));

        // Test getting data on model with set id
        $testModel = new Model(new self::$table(self::$database, 'test'), $model->getId());
        $this->assertSame('id', $testModel->getStorage()->getPrimaryKey());
        $this->assertSame($data, $testModel->getData());
        $this->assertSame($model->getId(), $testModel->getId());
        $this->assertTrue($testModel->exists());
        unset($testModel);

        $model->execute(new Update(), $newData);
        $this->assertTrue($model->exists());
        $this->assertEquals(array_merge($data, $newData), $model->getData());
        $model->synchronize();
        $id = $model->getId();

        $model->setId($newId);
        $this->assertFalse($model->exists());

        $model->setId($id);
        $this->assertTrue($model->exists());


        $model->execute(new Delete());
        $this->assertFalse($model->exists());
        $this->assertEmpty($model->getData());
        $this->assertNull($model->getId());

        // Clean up table
        $table->remove();

        // Test multiple columns id
        Timer::start("#2 Table creation");
        /** @var $table TableInterface */
        $table = new self::$table(self::$database, 'test', [
            new self::$column('id_1', self::$columnTypes['int'], null, false, true),
            new self::$column('id_2', self::$columnTypes['string'], null, false, true),
            new self::$column('id_3', self::$columnTypes['bool'], null, false, true),
            new self::$column('data', self::$columnTypes['text'])
        ]);
        $table->create(true);
        Timer::start("#2 Model operations");

        $model = new Model($table, null, false);
        $data = [
            'id_1' => Random::int(),
            'id_2' => Random::string(255),
            'id_3' => boolval(Random::int(0, 1)),
            'data' => Random::string(2000)
        ];

        $this->assertTrue($model->execute(new Create(), $data));
        $this->assertTrue($model->exists());
        $this->assertNotNull($model->getId());
        $this->assertIsInt($model->getId()['id_1']);
        $this->assertIsString($model->getId()['id_2']);
        $this->assertIsBool($model->getId()['id_3']);
        $ids = array_diff_key($data, array_flip([
            'data'
        ]));
        $this->assertSame($ids, $model->getNormalizedId());
        $this->assertSame($ids, $model->getId());

        $data['data'] = Random::string(2000);
        $this->assertTrue($model->execute(new Update(), $data));
        $this->assertSame($data['data'], $model->getData('data'));

        $model = new Model($table, $ids);
        $this->assertSame(array_keys($ids), $model->getStorage()->getPrimaryKey());
        $this->assertEquals($data['data'], $model->getData('data'));

        Timer::finish();
        unset($model);
        // Clean up table
        $table->remove();
    }

    public function testModelsList()
    {
        $dataList = [
            [
                'name' => 'test',
                'key' => 1
            ],
            [
                'name' => 'test2',
                'key' => 1
            ],
            [
                'name' => 'test3',
                'key' => 2
            ]
        ];

        Timer::start("#1 Models List - table creation");
        /** @var TableInterface $table */
        $table = new self::$table(self::$database, 'test', [
            new self::$column('id', self::$columnTypes['int'], null, false, true, true),
            new self::$column('name', self::$columnTypes['string']),
            new self::$column('key', self::$columnTypes['int'])
        ]);
        $table->create(true);

        Timer::start("#1 Models List - operations");

        foreach ($dataList as $data) {
            $table->insert($data);
        }

        $model = new Model($table);

        $modelsList = new ModelsList($model, new Query([
            'key' => 1
        ]));
        $list = $modelsList->getData(1)->getElements();
        $this->assertSame(1, $modelsList->getPage());
        $this->assertNull($modelsList->getOnPage());
        $this->assertEquals(2, $modelsList->getTotal());
        $this->assertEquals(2, count($list));
        $this->assertEquals('1', $list[0]->getId());
        $this->assertEquals('test2', $list[1]->getData('name'));
        $this->assertEquals($dataList[0], $list[0]->getData());
        $list = $modelsList->reset()
            ->setQuery(new Query())
            ->getData(1, 2)
            ->getElements();
        $this->assertEquals(1, $modelsList->getPage());
        $this->assertEquals(2, $modelsList->getOnPage());
        $this->assertEquals(3, $modelsList->getTotal());
        $this->assertEquals(2, count($list));

        // Model list page edge case
        $list = $modelsList->reset()
            ->setQuery(new Query())
            ->getData(1000000, 2)
            ->getElements();
        $this->assertSame(2, $modelsList->getPage());
        $this->assertEquals(2, $modelsList->getOnPage());
        $this->assertEquals(3, $modelsList->getTotal());
        $this->assertEquals(1, count($list));

        $list = $modelsList->reset()
            ->setQuery(new Query())
            ->getData(-1000000, 100000000)
            ->getElements();
        $this->assertSame(1, $modelsList->getPage());
        $this->assertEquals(100000000, $modelsList->getOnPage());
        $this->assertEquals(3, $modelsList->getTotal());
        $this->assertEquals(3, count($list));

        $list = $modelsList->reset()
            ->setQuery(new Query())
            ->getData(0, -100000000)
            ->getElements();
        $this->assertSame(1, $modelsList->getPage());
        $this->assertEquals(1, $modelsList->getOnPage());
        $this->assertEquals(3, $modelsList->getTotal());
        $this->assertEquals(1, count($list));

        $list = $modelsList->reset()
            ->setQuery(new Query(['key' => 3]))
            ->getData(1, 50)
            ->getElements();
        $this->assertSame(1, $modelsList->getPage());
        $this->assertEquals(50, $modelsList->getOnPage());
        $this->assertEquals(0, $modelsList->getTotal());
        $this->assertEquals(0, count($list));

        // Clean up
        foreach ($list as $model) {
            $model->synchronize();
        }
        Timer::finish();

        $table->remove();
    }

    /**
     * @throws Exception
     */
    public function testModelsBinder()
    {
        Timer::start("#1 Models binder - table creation");
        /** @var TableInterface $table */
        $table = new self::$table(self::$database, 'test', [
            new self::$column('id', self::$columnTypes['int'], null, false, true, true),
            new self::$column('name', self::$columnTypes['string'])
        ]);
        $table->create(true);

        /** @var TableInterface|DatabaseStorageInterface $table2 */
        $table2 = new self::$table(self::$database, 'test3', [
            new self::$column('test_id_1', self::$columnTypes['int'], null, false, true),
            new self::$column('test_id_2', self::$columnTypes['int'], null, false, true),
            new self::$column('data', self::$columnTypes['string'])
        ]);
        $table2->create(true);

        Timer::start("#1 Models binder - operations");

        $model1 = new Model($table);
        $model2 = new Model($table);

        $model1->execute(new Create(), [
            'name' => 'Test 1'
        ]);
        $model2->execute(new Create(), [
            'name' => 'Test 2'
        ]);

        $data = [
            'data' => 'Test 3'
        ];
        $newData = [
            'data' => 'Test 4'
        ];
        $modelBinder = new ModelBinder($table2, [
            $model1,
            $model2
        ]);

        $this->assertTrue($modelBinder->execute(new Create(), $data));
        $this->assertTrue($modelBinder->exists());
        $this->assertEquals([
            $model1->getBindingFieldName() . '_1' => $model1->getId(),
            $model2->getBindingFieldName() . '_2' => $model2->getId()
        ], $modelBinder->getId());
        $this->assertEquals($data, $modelBinder->getData());
        $modelBinder->execute(new Update(), $newData);
        $this->assertTrue($modelBinder->exists());
        $this->assertEquals(array_merge($data, $newData), $modelBinder->getData());
        $modelBinder->execute(new Delete());
        $this->assertFalse($modelBinder->exists());
        $this->assertEmpty($modelBinder->getData());
        $this->assertNull($modelBinder->getId());

        Timer::finish();

        // Clean up tables
        $table->remove();
        $table2->remove();
    }
}