<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Test;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Exception;
use Minwork\Database\Doctrine\Column as DocColumn;
use Minwork\Database\Doctrine\Database as DoctrineDatabase;
use Minwork\Database\Doctrine\Table as DoctrineTable;
use Minwork\Database\Interfaces\TableInterface;
use Minwork\Database\Utility\Condition;
use Minwork\Helper\DateHelper;
use Minwork\Helper\Formatter;
use Minwork\Helper\Random;
use PDO;
use PHPUnit\Framework\TestCase;
use Test\Utils\DatabaseProvider;
use Throwable;

class DoctrineDatabaseTest extends TestCase
{

    const TABLE_NAME = 'char';

    /**
     * @var DoctrineDatabase
     */
    private $database;
    /**
     * @var DoctrineTable
     */
    private $table;
    /**
     * @var DocColumn
     */
    private $secondPkColumn;
    /**
     * @var DocColumn
     */
    private $newDataColumn;

    public function testDoctrine()
    {
        $config = DatabaseProvider::getConfig();

        try {
            $config['driverOptions '] = $config['options'];
            unset($config['options']);
            $this->database = new DoctrineDatabase($config);

            $this->table = new DoctrineTable($this->database, self::TABLE_NAME, [
                new DocColumn(TableInterface::DEFAULT_PK_FIELD, Type::INTEGER, null, false, true),
                new DocColumn('data', Type::STRING, 'test'),
                new DocColumn('date', Type::DATETIME, null, true)
            ]);
            $this->secondPkColumn = new DocColumn(TableInterface::DEFAULT_PK_FIELD . '2', Type::STRING, null, false, true);
            $this->secondPkColumn->getDoctrineColumn()->setLength(10);

            $this->newDataColumn = new DocColumn('data2', Type::STRING);
            $this->newDataColumn->getDoctrineColumn()->setLength(511);
        } catch (DBALException $e) {
            echo <<<EOT
Database test: Cannot connect to Doctrine database platform using default params.
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

        }

        $this->databaseTest();
        $this->transactionsTest();
    }

    private function databaseTest()
    {
        // Test data
        $data = [
            TableInterface::DEFAULT_PK_FIELD => PHP_INT_MIN,
            'date' => null
        ];
        $data2 = [
            TableInterface::DEFAULT_PK_FIELD => PHP_INT_MAX,
            TableInterface::DEFAULT_PK_FIELD . '2' => Random::string(10),
            'data2' => Random::string(511)
        ];
        $data3 = [
            TableInterface::DEFAULT_PK_FIELD => 0,
            'data' => '',
            'date' => null
        ];
        $updateData = [
            TableInterface::DEFAULT_PK_FIELD => rand(0, PHP_INT_MAX),
            'data' => Random::string(255),
            'date' => DateHelper::addDays(2)
        ];

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->assertTrue($this->table->create(true));

        // Basic check on empty table
        $this->assertEquals(false, $this->table->exists($data));
        $this->assertEquals(0, $this->table->countRows());
        $this->assertEquals(0, $this->table->countRows($data));
        $this->assertEquals(Formatter::removeQuotes(self::TABLE_NAME), $this->table->getName(false));
        $this->assertEquals(TableInterface::DEFAULT_PK_FIELD, $this->table->getPrimaryKey());

        // Insert
        $this->table->insert($data);
        $this->assertEquals(true, $this->table->exists([
            TableInterface::DEFAULT_PK_FIELD => $data[TableInterface::DEFAULT_PK_FIELD]
        ]));
        $this->assertEquals(1, $this->table->countRows());
        $this->assertEquals(1, $this->table->countRows($data));

        $this->assertEquals($data, $this->table->select($data, array_keys($data))
            ->fetch(PDO::FETCH_ASSOC));

        // Check if data column has its default value
        $this->assertEquals([
            'data' => $this->table->getColumns()['data']->getDefaultValue()
        ], $this->table->select([
            TableInterface::DEFAULT_PK_FIELD => $data[TableInterface::DEFAULT_PK_FIELD]
        ], [
            'data'
        ])
            ->fetch(PDO::FETCH_ASSOC));
        // Check if insert data with default value from schema is same as table data
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->assertSame($this->table->format($data, true), $this->table->format($this->table->select([
            TableInterface::DEFAULT_PK_FIELD => $data[TableInterface::DEFAULT_PK_FIELD]
        ], [
            TableInterface::DEFAULT_PK_FIELD,
            'date',
            'data'
        ])
            ->fetch(PDO::FETCH_ASSOC)));

        // Update
        $this->table->update($updateData, [
            TableInterface::DEFAULT_PK_FIELD => $data[TableInterface::DEFAULT_PK_FIELD]
        ]);
        $this->assertEquals($updateData, $this->table->select([
            TableInterface::DEFAULT_PK_FIELD => $updateData[TableInterface::DEFAULT_PK_FIELD]
        ], array_keys($updateData), [
            'date' => -1
        ], 1, TableInterface::DEFAULT_PK_FIELD)
            ->fetch(PDO::FETCH_ASSOC));

        // Insert another row and check condition building
        $this->table->insert($data3);

        $conditions = (new Condition())->column(TableInterface::DEFAULT_PK_FIELD)
            ->in([
                $updateData[TableInterface::DEFAULT_PK_FIELD],
                0
            ])
            ->and()
            ->column(TableInterface::DEFAULT_PK_FIELD)
            ->gte(0)
            ->and()
            ->condition((new Condition())->column('date')
                ->isNull()
                ->or()
                ->column('date')
                ->between(DateHelper::now(), DateHelper::addDays(20)))
            ->and()
            ->column('data')
            ->isNotNull();

        $result = $this->table->select($conditions, TableInterface::COLUMNS_ALL, [
            'date' => -1,
            TableInterface::DEFAULT_PK_FIELD => 1,
            'data' => 'DESC'
        ], 2, TableInterface::DEFAULT_PK_FIELD)->fetchAll(PDO::FETCH_ASSOC);

        $this->assertEquals(2, count($result));
        $this->assertEquals($updateData[TableInterface::DEFAULT_PK_FIELD], $result[0][TableInterface::DEFAULT_PK_FIELD]);
        $this->assertEquals($data3[TableInterface::DEFAULT_PK_FIELD], $result[1][TableInterface::DEFAULT_PK_FIELD]);

        // Clean up table
        $this->table->clear();
        $this->assertEquals(0, $this->table->countRows());

        // Change table schema
        $columns = $this->table->getColumns();
        $columns = array_merge(array_slice($columns, 0, 1, true), [
            strval($this->secondPkColumn) => $this->secondPkColumn
        ], array_slice($columns, 1, count($columns) - 1, true));
        unset($columns['date']);
        $columns['data'] = $this->newDataColumn;
        $this->table->setColumns($columns);
        $this->assertTrue($this->table->synchronize());

        $this->assertEquals(array_keys($data2), $this->table->getColumns(TableInterface::COLUMN_NAMES));
        $this->assertEquals(0, $this->table->countRows());
        $this->assertEquals([
            TableInterface::DEFAULT_PK_FIELD,
            TableInterface::DEFAULT_PK_FIELD . '2'
        ], $this->table->getPrimaryKey());

        $this->table->insert($data2);

        $currentData = $this->table->select([
            TableInterface::DEFAULT_PK_FIELD => $data2[TableInterface::DEFAULT_PK_FIELD],
            TableInterface::DEFAULT_PK_FIELD . '2' => $data2[TableInterface::DEFAULT_PK_FIELD . '2']
        ])->fetch(PDO::FETCH_ASSOC);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->assertSame($data2, $this->table->format($currentData));
        $this->assertTrue($this->table->remove());
    }

    private function transactionsTest()
    {
        $db = $this->database;

        $db->startTransaction(); // 1
        $this->assertTrue($db->hasActiveTransaction());

        $db->startTransaction(); // 2
        $db->startTransaction(); // 3
        $db->startTransaction(); // 4

        $db->abortTransaction(); // 4
        $this->assertTrue($db->hasActiveTransaction());

        $db->abortTransaction(); // 3
        $this->assertTrue($db->hasActiveTransaction());

        $db->abortTransaction(); // 2
        $this->assertTrue($db->hasActiveTransaction());

        $db->abortTransaction(); // 1
        $this->assertFalse($db->hasActiveTransaction());

        $db->startTransaction(); // 1
        $this->assertTrue($db->hasActiveTransaction());

        $db->startTransaction(); // 2
        $this->assertTrue($db->hasActiveTransaction());

        $db->startTransaction(); // 3
        $this->assertTrue($db->hasActiveTransaction());

        $db->finishTransaction(); // 3
        $this->assertTrue($db->hasActiveTransaction());

        $db->finishTransaction(); // 2
        $this->assertTrue($db->hasActiveTransaction());

        $db->finishTransaction(); // 1
        $this->assertFalse($db->hasActiveTransaction());

        $db->startTransaction(); // 1
        $db->startTransaction(); // 2
        $db->startTransaction(); // 3
        $this->assertTrue($db->hasActiveTransaction());

        $db->finishTransaction(); // 3
        $this->assertTrue($db->hasActiveTransaction());

        $db->abortTransaction(); // 2
        $this->assertTrue($db->hasActiveTransaction());

        try {
            $db->finishTransaction(); // 1
        } catch (Throwable $e) {
            $db->abortTransaction();
        }
        $this->assertFalse($db->hasActiveTransaction());

        $this->expectException(Exception::class);
        $db->abortTransaction();
    }
}