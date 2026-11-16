<?php

namespace App\Domain\Signals\Ingestion;

/**
 * Thrown inside SyncDataConnectorJob when its heartbeat finds that its sync row is no longer `running`: the sync was closed
 * under it (DataConnectorSyncReaper presumed this worker lost, or a retry already finished it). The job must stop and write
 * nothing more: its sync is closed and the connector may already belong to a newer sync.
 */
final class SyncNoLongerActiveException extends \RuntimeException
{
}
