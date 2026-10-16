<?php
// Tier 2 drain: replay dead letters into the destination.
//
// Only a row-level failure counts as an attempt. A transient failure (the
// destination is down, a deadlock) stops the run without touching attempts;
// otherwise five runs during one outage would quarantine every healthy record.

const RECOVERY_BATCH_LIMIT = 100;

/**
 * @return array{recovered:int, failed:int, quarantined:int, status:string, error:?string}
 */
function run_recovery(PDO $source, PDO $dest): array {
    $stats = ['recovered' => 0, 'failed' => 0, 'quarantined' => 0, 'status' => 'COMPLETED', 'error' => null];

    try {
        $query = $source->prepare(
            "SELECT id, payload, attempts
             FROM sync_dead_letter_queue
             WHERE attempts < :max_attempts
               AND quarantined = 0
             ORDER BY id ASC
             LIMIT :batch_limit"
        );
        $query->bindValue(':max_attempts', MAX_ATTEMPTS, PDO::PARAM_INT);
        $query->bindValue(':batch_limit', RECOVERY_BATCH_LIMIT, PDO::PARAM_INT);
        $query->execute();
        $items = $query->fetchAll(PDO::FETCH_ASSOC);

        $upsert      = prepare_upsert($dest, 'RECOVERED', 'RECOVERED_UPDATED');
        $delete_stmt = $source->prepare("DELETE FROM sync_dead_letter_queue WHERE id = :id");
        $fail_stmt   = $source->prepare(
            "UPDATE sync_dead_letter_queue
             SET attempts = :attempts, quarantined = :quarantined, error_message = :error
             WHERE id = :id"
        );

        foreach ($items as $item) {
            $queue_id = (int)$item['id'];
            $envelope = json_decode((string)$item['payload'], true);
            // Support both payload formats: wrapped {data: {...}} and bare row
            $data = is_array($envelope) ? ($envelope['data'] ?? $envelope) : null;

            $validation_error = is_array($data) ? validate_row($data) : 'payload is not valid JSON';
            if ($validation_error !== null) {
                $fail_stmt->execute([
                    ':attempts' => (int)$item['attempts'], ':quarantined' => 1,
                    ':error' => "Validation: $validation_error", ':id' => $queue_id,
                ]);
                $stats['quarantined']++;
                log_msg('WARN', "DLQ id=$queue_id has malformed payload ($validation_error). Quarantined.");
                continue;
            }

            try {
                upsert_donation($upsert, $data);
            } catch (PDOException $e) {
                if (is_transient_db_error($e)) {
                    throw $e;
                }
                $attempts   = (int)$item['attempts'] + 1;
                $quarantine = $attempts >= MAX_ATTEMPTS;
                $fail_stmt->execute([
                    ':attempts' => $attempts, ':quarantined' => (int)$quarantine,
                    ':error' => $e->getMessage(), ':id' => $queue_id,
                ]);
                $stats['failed']++;
                if ($quarantine) {
                    $stats['quarantined']++;
                    log_msg('ERROR', "DLQ id=$queue_id quarantined after $attempts attempts: " . $e->getMessage());
                } else {
                    log_msg('WARN', "DLQ id=$queue_id still failing (attempt $attempts/" . MAX_ATTEMPTS . "): " . $e->getMessage());
                }
                continue;
            }

            // Upsert and delete live in different databases, so they cannot share
            // a transaction. If the delete fails, the next run replays the row,
            // which the upsert makes harmless.
            $delete_stmt->execute([':id' => $queue_id]);
            $stats['recovered']++;
            log_msg('INFO', "Recovered DLQ id=$queue_id (remote_id={$data['id']})");
        }

    } catch (PDOException $e) {
        $stats['status'] = 'ABORTED';
        $stats['error']  = $e->getMessage();
        log_msg('CRITICAL', "Recovery aborted: " . $e->getMessage());
    }

    return $stats;
}
