<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use KevStudios\Beacon\Beacon;
use KevStudios\Beacon\Symfony\EventSubscriber\RequestSpanSubscriber;
use SensitiveParameter;

/** Doctrine DBAL 4 middleware that records query/exec/prepared-statement durations as child spans. */
final class DoctrineMiddleware implements Middleware
{
    public function __construct(
        private readonly Beacon $beacon,
        private readonly RequestSpanSubscriber $requestSpan,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new BeaconDriver($driver, $this->beacon, $this->requestSpan);
    }
}

final class BeaconDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly Beacon $beacon,
        private readonly RequestSpanSubscriber $requestSpan,
    ) {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        $connection = parent::connect($params);
        $driverName = isset($params['driver']) && \is_string($params['driver']) ? $params['driver'] : $connection::class;

        return new BeaconConnection(
            $connection,
            new DoctrineSpanRecorder($this->beacon, $this->requestSpan, self::dbSystem($driverName)),
        );
    }

    private static function dbSystem(string $driver): string
    {
        $driver = strtolower($driver);
        foreach (['postgresql' => 'postgresql', 'pgsql' => 'postgresql', 'mysql' => 'mysql', 'mariadb' => 'mariadb', 'sqlite' => 'sqlite', 'sqlsrv' => 'mssql', 'oracle' => 'oracle'] as $needle => $system) {
            if (str_contains($driver, $needle)) {
                return $system;
            }
        }

        return 'other_sql';
    }
}

final class BeaconConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly DoctrineSpanRecorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new BeaconStatement(parent::prepare($sql), $sql, $this->recorder);
    }

    public function query(string $sql): Result
    {
        return $this->recorder->record($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->recorder->record($sql, fn (): int|string => parent::exec($sql));
    }
}

final class BeaconStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly string $sql, private readonly DoctrineSpanRecorder $recorder)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        return $this->recorder->record($this->sql, fn (): Result => parent::execute());
    }
}
