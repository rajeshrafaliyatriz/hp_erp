<?php

namespace App\Providers;

use App\Database\Schema\LegacyMariaDbSchemaGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->useLegacySafeSchemaGrammar();
    }

    /**
     * Make schema introspection survive MariaDB below 10.2.
     *
     * Laravel asks information_schema for `generation_expression` when it reads
     * a table's columns. That column arrived in MariaDB 10.2, so on an older
     * engine every Schema::hasColumn() / getColumnListing() / getColumns() call
     * - and every Eloquent mass-assignment guard check - dies with
     *
     *   SQLSTATE[42S22]: Unknown column 'generation_expression' in 'field list'
     *
     * One of the two databases this application runs against is MariaDB 10.1.48,
     * and customer deployments run older engines still. The default development
     * database is 10.11, which is why this only ever surfaced as a bug report.
     *
     * The grammar is swapped at connection time rather than per call site:
     * getSchemaBuilder() only installs the default grammar when none is set
     * (MySqlConnection::getSchemaBuilder), so setting ours first wins, and it is
     * inert on any server new enough to have the column.
     *
     * The listener is deliberately narrow - only mysql/mariadb drivers - so a
     * sqlite test connection or a pgsql reporting connection is left alone.
     */
    private function useLegacySafeSchemaGrammar(): void
    {
        Event::listen(function (ConnectionEstablished $event) {
            $connection = $event->connection;

            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return;
            }

            if (! $connection instanceof Connection) {
                return;
            }

            $connection->setSchemaGrammar(new LegacyMariaDbSchemaGrammar($connection));
        });
    }
}
