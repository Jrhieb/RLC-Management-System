<?php

// Local operations adapter for the existing Laravel worker and existing jobs.
// It never creates accounts, reviews, notifications, or queued mail.
use App\Jobs\SendRegistrationVerification;
use App\Jobs\SkipRegistrationVerification;
use App\Mail\AccountDecisionMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

$root = dirname(__DIR__);
$directory = $root.'/storage/framework/lan-mail-queue';
$stateFile = $directory.'/state.json';
$stopFile = $directory.'/stop';
$eventsFile = $directory.'/events.jsonl';
$work = in_array('--work', $argv, true);
$state = ['status' => 'starting', 'pid' => getmypid(), 'processing_scope' => 'account_decisions_and_registration_verification',
    'processed' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 0, 'smtp_accepted' => 0];

function lanQueueProps(mixed $object): array
{
    $out = [];
    foreach ((array) $object as $key => $value) {
        $parts = explode(chr(0), (string) $key);
        $out[end($parts)] = $value;
    }

    return $out;
}

function lanQueueMail(array $row): ?array
{
    try {
        $p = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (($p['job'] ?? '') !== 'Illuminate\\Queue\\CallQueuedHandler@call') {
            return null;
        }
        $w = lanQueueProps(@unserialize($p['data']['command'] ?? '', ['allowed_classes' => false]));
        if (($p['data']['commandName'] ?? '') === 'App\\Jobs\\SkipRegistrationVerification'
            && ($w['__PHP_Incomplete_Class_Name'] ?? '') === 'App\\Jobs\\SkipRegistrationVerification') {
            if (array_diff(array_keys($w), ['__PHP_Incomplete_Class_Name', 'connection', 'tries'])
                || ($w['connection'] ?? null) !== 'database' || ($w['tries'] ?? null) !== 1) {
                return null;
            }

            return ['kind' => 'registration_no_email'];
        }
        if (($p['data']['commandName'] ?? '') === 'App\\Jobs\\SendRegistrationVerification'
            && ($w['__PHP_Incomplete_Class_Name'] ?? '') === 'App\\Jobs\\SendRegistrationVerification') {
            if (! is_int($w['registrationRequestId'] ?? null) || $w['registrationRequestId'] < 1
                || ! is_string($w['tokenHash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $w['tokenHash']) !== 1
                || ! is_string($w['encryptedToken'] ?? null) || $w['encryptedToken'] === ''
                || ! empty($w['chained']) || ! empty($w['middleware'])) {
                return null;
            }

            return ['kind' => 'registration_verification', 'request_id' => $w['registrationRequestId'], 'token_hash' => $w['tokenHash']];
        }
        if (($p['data']['commandName'] ?? '') !== 'Illuminate\\Mail\\SendQueuedMailable') {
            return null;
        }
        $m = lanQueueProps($w['mailable'] ?? null);
        $to = $m['to'] ?? [];
        if (($w['__PHP_Incomplete_Class_Name'] ?? '') !== 'Illuminate\\Mail\\SendQueuedMailable'
            || ($m['__PHP_Incomplete_Class_Name'] ?? '') !== 'App\\Mail\\AccountDecisionMail'
            || ! in_array($m['decision'] ?? '', ['approved', 'rejected'], true)
            || count($to) !== 1 || ! filter_var($to[0]['address'] ?? '', FILTER_VALIDATE_EMAIL) || ! empty($m['cc']) || ! empty($m['bcc'])
            || ($m['mailer'] ?? 'smtp') !== 'smtp' || ! empty($w['chained']) || ! empty($m['chained'])
            || ! empty($w['middleware']) || ! empty($m['middleware'])) {
            return null;
        }

        return ['kind' => 'decision', 'recipient' => strtolower($to[0]['address']), 'decision' => $m['decision']];
    } catch (Throwable) {
        return null;
    }
}

function lanQueueRecipientEligible(PDO $pdo, array $mail): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=? AND role=? AND approval_status=?');
    $q->execute([$mail['recipient'], 'caseworker', $mail['decision']]);

    return (int) $q->fetchColumn() === 1;
}

function lanQueueExpectedMail(PDO $pdo, array $mail): ?array
{
    if ($mail['kind'] === 'registration_no_email') {
        return null;
    }
    if ($mail['kind'] === 'registration_verification') {
        $q = $pdo->prepare('SELECT email FROM registration_requests WHERE id=? AND token_hash=? AND expires_at>?
            AND verified_at IS NULL AND completed_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM users WHERE LOWER(users.email)=LOWER(registration_requests.email))');
        $q->execute([$mail['request_id'], $mail['token_hash'], gmdate('Y-m-d H:i:s')]);
        $email = $q->fetchColumn();

        return $email ? ['recipient' => strtolower($email), 'subject' => 'Verify your email to register'] : null;
    }

    return lanQueueRecipientEligible($pdo, $mail) ? ['recipient' => $mail['recipient'],
        'subject' => $mail['decision'] === 'approved' ? 'Your account has been approved' : 'Your account request was rejected'] : null;
}

function lanQueueSummary(array $row, PDO $pdo, bool $failed = false): array
{
    $mail = lanQueueMail($row);
    // Obsolete verification jobs must be consumed without SMTP, rather than
    // stopping the queue. The existing service checks their generation/state.
    $kind = $mail['kind'] ?? 'other';
    $registration = in_array($kind, ['registration_verification', 'registration_no_email'], true);
    $eligible = $mail && ($registration || lanQueueRecipientEligible($pdo, $mail));

    return ['job_id' => (int) $row['id'],
        'kind' => $registration ? $kind : ($mail ? $mail['decision'].'_email' : 'other'),
        'classification' => $eligible ? ($registration ? $kind : 'eligible_caseworker_decision') : 'unrelated',
        'status' => $failed ? 'failed' : ($row['reserved_at'] !== null ? 'reserved' : ((int) $row['available_at'] > time() ? 'delayed' : 'pending')),
        'attempts' => $failed ? null : (int) $row['attempts']];
}

function lanQueueBlocked(array $job): bool
{
    return ! in_array($job['classification'], ['eligible_caseworker_decision', 'registration_verification', 'registration_no_email'], true)
        || $job['attempts'] !== 0 || $job['status'] === 'reserved';
}

function lanQueueWriteState(): void
{
    global $state, $stateFile;
    $state['heartbeat_utc'] = gmdate('Y-m-d H:i:s');
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}

function lanQueueEvent(string $event, array $data = []): void
{
    global $eventsFile;
    file_put_contents($eventsFile, json_encode(['time_utc' => gmdate('Y-m-d H:i:s'), 'event' => $event] + $data, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
    if (PHP_VERSION !== '8.4.25' || ! extension_loaded('pdo_sqlite') || ! extension_loaded('openssl') || ! extension_loaded('mbstring')) {
        throw new RuntimeException('Verified PHP runtime is required.');
    }
    if (in_array('--self-test', $argv, true)) {
        require $root.'/vendor/autoload.php';
        // Synthetic fixtures only: no application bootstrap, real database,
        // mail transport, persisted jobs, or recipients are used by this check.
        $fixture = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $fixture->exec('CREATE TABLE users (email TEXT, role TEXT, approval_status TEXT)');
        $insert = $fixture->prepare('INSERT INTO users VALUES (?, ?, ?)');
        foreach ([
            ['approved-one@example.invalid', 'caseworker', 'approved'],
            ['approved-two@example.invalid', 'caseworker', 'approved'],
            ['rejected@example.invalid', 'caseworker', 'rejected'],
            ['pending@example.invalid', 'caseworker', 'pending'],
            ['director@example.invalid', 'project_director', 'approved'],
        ] as $account) {
            $insert->execute($account);
        }
        $encode = fn ($m) => json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [
            'commandName' => 'Illuminate\\Mail\\SendQueuedMailable', 'command' => serialize(new SendQueuedMailable($m)),
        ]], JSON_THROW_ON_ERROR);
        $row = ['id' => 0, 'reserved_at' => null, 'available_at' => time(), 'attempts' => 0];
        $checks = [];
        foreach ([
            'first_approved_caseworker_allowed' => ['approved-one@example.invalid', 'approved', true],
            'second_approved_caseworker_allowed' => ['approved-two@example.invalid', 'approved', true],
            'rejected_caseworker_allowed' => ['rejected@example.invalid', 'rejected', true],
            'pending_account_blocked' => ['pending@example.invalid', 'approved', false],
            'director_recipient_blocked' => ['director@example.invalid', 'approved', false],
            'unregistered_recipient_blocked' => ['unknown@example.invalid', 'approved', false],
            'mismatched_approval_blocked' => ['rejected@example.invalid', 'approved', false],
            'mismatched_rejection_blocked' => ['approved-one@example.invalid', 'rejected', false],
        ] as $name => [$address, $decision, $expected]) {
            $row['payload'] = $encode((new AccountDecisionMail($decision))->to($address));
            $checks[$name] = ! lanQueueBlocked(lanQueueSummary($row, $fixture)) === $expected;
        }
        $mail = (new AccountDecisionMail('approved'))->to('approved-one@example.invalid');
        foreach (['cc', 'bcc', 'chained', 'middleware'] as $property) {
            $foreign = clone $mail;
            $foreign->{$property} = $property === 'cc' || $property === 'bcc'
                ? [['address' => 'unknown@example.invalid', 'name' => null]] : ['synthetic'];
            $row['payload'] = $encode($foreign);
            $checks[$property.'_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        }
        $foreign = clone $mail;
        $foreign->to('approved-two@example.invalid');
        $row['payload'] = $encode($foreign);
        $checks['multiple_recipients_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $foreign = clone $mail;
        $foreign->mailer('log');
        $row['payload'] = $encode($foreign);
        $checks['other_mailer_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['payload'] = json_encode(['job' => 'unrelated', 'data' => []]);
        $checks['unrelated_job_class_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['payload'] = $encode($mail);
        $row['attempts'] = 1;
        $checks['previous_attempt_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['attempts'] = 0;
        $row['reserved_at'] = time();
        $checks['reserved_job_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['reserved_at'] = null;
        $checks['failed_job_not_retried'] = lanQueueBlocked(lanQueueSummary($row, $fixture, true));
        $fixture->exec("UPDATE users SET approval_status='pending' WHERE email='approved-one@example.invalid'");
        $checks['recipient_rechecked_after_status_change'] = ! lanQueueRecipientEligible($fixture, lanQueueMail($row));
        $fixture->exec('CREATE TABLE registration_requests (id INTEGER, email TEXT, token_hash TEXT, expires_at TEXT, verified_at TEXT, completed_at TEXT)');
        $hash = hash('sha256', 'synthetic-generation');
        $insertRequest = $fixture->prepare('INSERT INTO registration_requests VALUES (?, ?, ?, ?, ?, ?)');
        $insertRequest->execute([1, 'register@example.invalid', $hash, gmdate('Y-m-d H:i:s', time() + 1800), null, null]);
        $verification = new SendRegistrationVerification(1, $hash, 'synthetic-ciphertext');
        $encodeVerification = fn ($job) => json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [
            'commandName' => SendRegistrationVerification::class, 'command' => serialize($job),
        ]], JSON_THROW_ON_ERROR);
        $row['payload'] = $encodeVerification($verification);
        $checks['verification_job_allowed'] = ! lanQueueBlocked(lanQueueSummary($row, $fixture));
        $checks['current_verification_recipient_checked'] = lanQueueExpectedMail($fixture, lanQueueMail($row))['recipient'] === 'register@example.invalid';
        foreach ([
            'expired' => "expires_at='2000-01-01 00:00:00'",
            'consumed' => "verified_at='2000-01-01 00:00:00'",
            'completed' => "completed_at='2000-01-01 00:00:00'",
            'superseded' => "token_hash='different-generation'",
            'already_registered' => "email='rejected@example.invalid'",
        ] as $name => $change) {
            $fixture->beginTransaction();
            $fixture->exec('UPDATE registration_requests SET '.$change.' WHERE id=1');
            $checks[$name.'_verification_processed_without_send'] = ! lanQueueBlocked(lanQueueSummary($row, $fixture))
                && lanQueueExpectedMail($fixture, lanQueueMail($row)) === null;
            $fixture->rollBack();
        }
        $verification->registrationRequestId = 999;
        $row['payload'] = $encodeVerification($verification);
        $checks['missing_verification_processed_without_send'] = ! lanQueueBlocked(lanQueueSummary($row, $fixture))
            && lanQueueExpectedMail($fixture, lanQueueMail($row)) === null;
        $verification->chained = ['synthetic'];
        $row['payload'] = $encodeVerification($verification);
        $checks['chained_verification_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $skip = new SkipRegistrationVerification;
        $encodeNoEmail = fn ($job) => json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [
            'commandName' => SkipRegistrationVerification::class, 'command' => serialize($job),
        ]], JSON_THROW_ON_ERROR);
        $row['payload'] = $encodeNoEmail($skip);
        $checks['no_email_job_allowed'] = ! lanQueueBlocked(lanQueueSummary($row, $fixture));
        $noEmail = lanQueueMail($row);
        $checks['no_email_job_has_no_delivery_target'] = $noEmail !== null && lanQueueExpectedMail($fixture, $noEmail) === null;
        $checks['no_email_job_has_only_queue_metadata'] = get_object_vars($skip) === ['connection' => 'database', 'tries' => 1];
        $skip->connection = 'sync';
        $row['payload'] = $encodeNoEmail($skip);
        $checks['no_email_other_connection_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $skip->connection = 'database';
        $skip->tries = 2;
        $row['payload'] = $encodeNoEmail($skip);
        $checks['no_email_retry_override_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $skip->tries = 1;
        $row['payload'] = $encodeNoEmail($skip);
        $row['attempts'] = 1;
        $checks['no_email_previous_attempt_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['attempts'] = 0;
        $row['reserved_at'] = time();
        $checks['no_email_reserved_job_blocked'] = lanQueueBlocked(lanQueueSummary($row, $fixture));
        $row['reserved_at'] = null;
        $checks['no_email_failed_job_not_retried'] = lanQueueBlocked(lanQueueSummary($row, $fixture, true));
        echo json_encode(['database' => 'sqlite::memory:', 'scope_guard_checks' => $checks,
            'mail_sent' => false, 'jobs_created' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(in_array(false, $checks, true) ? 1 : 0);
    }
    $c = require $root.'/bootstrap/cache/config.php';
    $qc = $c['queue']['connections'][$c['queue']['default']];
    $db = $c['database']['connections'][$qc['connection'] ?? $c['database']['default']];
    $file = realpath($db['database']);
    if ($c['queue']['default'] !== 'database' || $qc['driver'] !== 'database' || $qc['table'] !== 'jobs'
        || $db['driver'] !== 'sqlite' || ! empty($db['url']) || ! $file || ! is_file($file)
        || $c['mail']['default'] !== 'smtp' || ($c['mail']['mailers']['smtp']['transport'] ?? '') !== 'smtp') {
        throw new RuntimeException('Expected the existing database queue and direct SMTP.');
    }
    $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $pdo->exec('PRAGMA query_only = ON');
    $pending = array_map(fn ($row) => lanQueueSummary($row, $pdo), $pdo->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    $failed = array_map(fn ($row) => lanQueueSummary($row, $pdo, true), $pdo->query('SELECT * FROM failed_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    $blocked = array_values(array_filter($pending, 'lanQueueBlocked'));
    $inventory = ['processing_scope' => $state['processing_scope'], 'php_version' => PHP_VERSION, 'queue_connection' => 'database', 'queue_name' => $qc['queue'],
        'effective_mailer' => 'smtp', 'smtp_is_gmail' => in_array(strtolower($c['mail']['mailers']['smtp']['host'] ?? ''), ['smtp.gmail.com', 'smtp.googlemail.com']),
        'smtp_port' => $c['mail']['mailers']['smtp']['port'], 'smtp_credentials_configured' => ! empty($c['mail']['mailers']['smtp']['username']) && ! empty($c['mail']['mailers']['smtp']['password']),
        'pending_jobs_count' => count($pending), 'failed_jobs_count' => count($failed),
        'pending_jobs' => $pending, 'failed_jobs' => $failed, 'start_allowed' => ! $blocked, 'blocking_jobs' => $blocked];
    if (! $work) {
        echo json_encode($inventory, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if ($blocked) {
        echo json_encode($inventory, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(2);
    }
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $lock = fopen($directory.'/worker.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        echo '{"status":"already_running"}'.PHP_EOL;
        exit(0);
    }
    if (file_exists($stopFile)) {
        unlink($stopFile);
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // Only this local worker gets one-attempt behavior. The existing mailable
    // and its production retry configuration remain untouched.
    class LanMailDatabaseJob extends DatabaseJob
    {
        public function maxTries()
        {
            return 1;
        }

        public function retryUntil()
        {
            return null;
        }

        public function fire()
        {
            try {
                parent::fire();
            } catch (Throwable $e) {
                throw new RuntimeException('Local account email attempt failed; transport details withheld.');
            }
        }
    }

    $queue = $app->make('queue')->connection('database');
    if (realpath($queue->getDatabase()->getDatabaseName()) !== $file) {
        throw new RuntimeException('Queue database changed.');
    }
    if ($app['config']->get('queue.default') !== 'database'
        || $app['config']->get('queue.connections.database.table') !== 'jobs'
        || $app['config']->get('mail') !== $c['mail']
        || ! $app->make('mail.manager')->mailer('smtp')->getSymfonyTransport() instanceof SmtpTransport) {
        throw new RuntimeException('Worker and inspected queue or SMTP configuration differ.');
    }
    $worker = $app->make('queue.worker');
    $worker->setName('lan-account-mail');
    $currentMail = null;
    Worker::popUsing('lan-account-mail', function () use ($queue, $app, $worker, $pdo, $qc, $stopFile, &$state, &$currentMail) {
        if (file_exists($stopFile)) {
            $state['stop_reason'] = 'stop_requested';
            $worker->shouldQuit = true;

            return null;
        }
        try {
            $job = $queue->getDatabase()->transaction(function () use ($queue, $app, $worker, $pdo, $qc, &$state, &$currentMail) {
                $connection = $queue->getDatabase();
                $rows = $connection->table('jobs')->orderBy('id')->lockForUpdate()->get();
                $candidate = null;
                $blocked = [];
                foreach ($rows as $row) {
                    $summary = lanQueueSummary((array) $row, $pdo);
                    if (lanQueueBlocked($summary)) {
                        $blocked[] = $summary;
                    } elseif (! $candidate && $row->queue === $qc['queue'] && (int) $row->available_at <= time()) {
                        $candidate = $row;
                    }
                }
                if ($blocked) {
                    $state['stop_reason'] = 'unrelated_or_previously_attempted_jobs';
                    $state['blocking_jobs'] = $blocked;
                    $worker->shouldQuit = true;
                    lanQueueEvent('scope_blocked', ['jobs' => $blocked]);

                    return null;
                }
                if (! $candidate) {
                    return null;
                }
                $now = time();
                $changed = $connection->table('jobs')->where('id', $candidate->id)->whereNull('reserved_at')->where('attempts', 0)
                    ->where('available_at', '<=', $now)->update(['reserved_at' => $now, 'attempts' => 1]);
                if ($changed !== 1) {
                    throw new RuntimeException('Exclusive reservation failed.');
                }
                $candidate->reserved_at = $now;
                $candidate->attempts = 1;
                $currentMail = lanQueueMail((array) $candidate);

                return new LanMailDatabaseJob($app, $queue, new DatabaseJobRecord($candidate), 'database', $candidate->queue);
            });
            lanQueueWriteState();

            return $job;
        } catch (Throwable) {
            $state['stop_reason'] = 'queue_inspection_error';
            $worker->shouldQuit = true;
            lanQueueWriteState();

            return null;
        }
    });
    $app['events']->listen(JobProcessing::class, function ($event) use (&$state) {
        $state['current_job_id'] = (int) $event->job->getJobId();
        $state['mail_sends_this_job'] = 0;
        lanQueueEvent('job_started', ['job_id' => $state['current_job_id']]);
        lanQueueWriteState();
    });
    $app['events']->listen(MessageSending::class, function (MessageSending $event) use (&$state, &$currentMail, $pdo) {
        $state['mail_sends_this_job']++;
        $to = $event->message->getTo();
        $expected = $currentMail ? lanQueueExpectedMail($pdo, $currentMail) : null;
        if (! $currentMail || empty($state['current_job_id']) || $state['mail_sends_this_job'] !== 1 || count($to) !== 1
            || ! $expected || strtolower($to[0]->getAddress()) !== $expected['recipient']
            || $event->message->getCc() || $event->message->getBcc()
            || $event->message->getSubject() !== $expected['subject']) {
            throw new RuntimeException('Account-email recipient or one-send guard failed.');
        }
    });
    $app['events']->listen(MessageSent::class, function (MessageSent $event) use (&$state) {
        $debug = $event->sent->getSymfonySentMessage()->getDebug();
        preg_match_all('/(?:^|\n)[^\r\n]*? < ([0-9]{3})(?:[ -])/', $debug, $codes);
        $reply = ! empty($codes[1]) ? (int) end($codes[1]) : null;
        unset($debug, $codes);
        if ($reply === 250) {
            $state['smtp_accepted']++;
        }
        lanQueueEvent('mail_send_returned', ['job_id' => $state['current_job_id'], 'smtp_reply_code' => $reply]);
    });
    $app['events']->listen(JobProcessed::class, function ($event) use (&$state, &$currentMail) {
        $state['processed']++;
        $noEmail = ($currentMail['kind'] ?? '') === 'registration_no_email';
        $skipped = ($noEmail || ($currentMail['kind'] ?? '') === 'registration_verification') && $state['mail_sends_this_job'] === 0;
        $state[$skipped ? 'skipped' : 'succeeded']++;
        unset($state['current_job_id']);
        $currentMail = null;
        $outcome = $noEmail ? 'registration_skipped_without_email' : ($skipped ? 'verification_skipped_without_send' : 'job_succeeded');
        lanQueueEvent($outcome, ['job_id' => (int) $event->job->getJobId()]);
        lanQueueWriteState();
    });
    $app['events']->listen(JobFailed::class, function ($event) use ($app, &$state, &$currentMail) {
        $app->make('queue.failer')->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        $state['processed']++;
        $state['failed']++;
        unset($state['current_job_id']);
        $currentMail = null;
        lanQueueEvent('job_failed', ['job_id' => (int) $event->job->getJobId()]);
        lanQueueWriteState();
    });
    $state['status'] = 'running';
    $state['started_utc'] = gmdate('Y-m-d H:i:s');
    lanQueueEvent('worker_started', ['pid' => getmypid(), 'processing_scope' => $state['processing_scope']]);
    lanQueueWriteState();
    $status = $worker->daemon('database', $qc['queue'], new WorkerOptions(name: 'lan-account-mail', sleep: 2, timeout: 60, maxTries: 1));
    $state['status'] = 'stopped';
    lanQueueEvent('worker_stopped', ['reason' => $state['stop_reason'] ?? 'laravel_worker_exit']);
    lanQueueWriteState();
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($status);
} catch (Throwable) {
    if ($work && isset($lock) && is_resource($lock)) {
        $state['status'] = 'error';
        $state['stop_reason'] = 'setup_error';
        lanQueueWriteState();
    }
    echo '{"status":"error","details_withheld":true,"jobs_not_retried":true}'.PHP_EOL;
    exit(1);
}
