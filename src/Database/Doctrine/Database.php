<?php
/*
 * This file is part of the Minwork package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Minwork\Database\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Minwork\Database\Interfaces\DatabaseInterface;
use Minwork\Database\Object\AbstractDatabase;

/**
 * Class Database
 * @package Minwork\Database\Doctrine
 */
class Database implements DatabaseInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * Database constructor.
     * @param array $params
     * @param Configuration|null $config
     * @param EventManager|null $eventManager
     * @throws DBALException
     */
    public function __construct(array $params, ?Configuration $config = null, ?EventManager $eventManager = null)
    {
        $this->connection = DriverManager::getConnection($params, $config, $eventManager);
    }

    /**
     * @param string $statement
     * @return int|mixed
     * @throws DBALException
     */
    public function exec($statement)
    {
        return $this->connection->exec($statement);
    }

    /**
     * @param string $statement
     * @return Statement|mixed
     * @throws DBALException
     */
    public function query($statement)
    {
        $args = func_get_args();
        return $this->connection->query(...$args);
    }

    public function escape($value, $type = null): string
    {
        return $this->connection->quote($value, $type);
    }

    public function getHost(): string
    {
        return $this->connection->getHost() ?? '';
    }

    public function getName(): string
    {
        return $this->connection->getDatabase();
    }

    public function getCharset(): string
    {
        return $this->connection->getParams()['charset'] ?? AbstractDatabase::DEFAULT_CHARSET;
    }

    public function getOptions(): array
    {
        return $this->connection->getParams();
    }

    public function getLastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    public function startTransaction()
    {
        $this->beginTransaction();
    }

    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }

    /**
     * @throws ConnectionException
     */
    public function finishTransaction(): void
    {
        $this->commit();
    }

    /**
     * @throws ConnectionException
     */
    public function commit()
    {
        $this->connection->commit();
    }

    /**
     * @throws ConnectionException
     */
    public function abortTransaction(): void
    {
        $this->rollBack();
    }

    /**
     * @throws ConnectionException
     */
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function inTransaction()
    {
        return $this->hasActiveTransaction();
    }

    public function hasActiveTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}