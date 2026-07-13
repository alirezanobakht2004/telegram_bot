<?php

declare(strict_types=1);

use SmartToolbox\Core\JobQueue;
use SmartToolbox\Modules\FileTools\FileCapabilities;
use SmartToolbox\Modules\FileTools\FileJobRepository;
use SmartToolbox\Modules\FileTools\FileReferenceExtractor;
use SmartToolbox\Modules\FileTools\FileToolException;
use SmartToolbox\Modules\FileTools\TextFileProcessor;

$rootPath = dirname(__DIR__);

require $rootPath
    . '/vendor/autoload.php';

$assert = static function (
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$extractor = new FileReferenceExtractor();

$reference = $extractor->extract([
    'reply_to_message' => [
        'document' => [
            'file_id' => 'test-file-id',
            'file_unique_id' => 'unique-id',
            'file_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
        ],
    ],
]);

$assert(
    is_array($reference)
    && $reference['file_id'] === 'test-file-id'
    && $reference['file_name'] === 'report.pdf',
    'Telegram file reference extraction failed.'
);

$temporaryDirectory = sys_get_temp_dir()
    . '/smart-toolbox-release-four-'
    . bin2hex(random_bytes(5));

if (!mkdir($temporaryDirectory, 0700, true)) {
    throw new RuntimeException(
        'Temporary test directory could not be created.'
    );
}

$textProcessor = new TextFileProcessor(512000);

$jsonPath = $temporaryDirectory
    . '/output.json';

$jsonResult = $textProcessor->convert(
    'tojson',
    'hello',
    $jsonPath
);

$assert(
    $jsonResult['mime_type'] === 'application/json'
    && str_contains(
        (string) file_get_contents($jsonPath),
        'hello'
    ),
    'Text to JSON conversion failed.'
);

$csvPath = $temporaryDirectory
    . '/output.csv';

$csvResult = $textProcessor->convert(
    'tocsv',
    '[{"name":"Ali","age":30},{"name":"Sara","age":28}]',
    $csvPath
);

$assert(
    $csvResult['mime_type'] === 'text/csv'
    && filesize($csvPath) > 10,
    'JSON to CSV conversion failed.'
);

$textLimitEnforced = false;

try {
    $oversizedPath = $temporaryDirectory
        . '/oversized.txt';

    (new TextFileProcessor(9999999))->convert(
        'totxt',
        str_repeat('a', 512001),
        $oversizedPath
    );
} catch (FileToolException $exception) {
    $textLimitEnforced =
        $exception->errorCode
        === 'text_output_limit';
}

$assert(
    $textLimitEnforced,
    'The fixed 500KB text ceiling was not enforced.'
);

$capabilities = new FileCapabilities();
$allCapabilities = $capabilities->all();

foreach (
    [
        'ext_gd',
        'ext_fileinfo',
        'ext_zip',
        'imagick',
        'image_processing',
        'qr_png',
        'pdftotext',
        'pdfinfo',
        'pdf_text',
        'proc_open',
    ]
    as $capability
) {
    $assert(
        isset($allCapabilities[$capability]),
        'Missing capability: ' . $capability
    );
}

$databaseStatus = 'skipped';

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO(
        'sqlite:'
        . $temporaryDirectory
        . '/test.sqlite',
        options: [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(
        'CREATE TABLE users (
            telegram_id INTEGER PRIMARY KEY
        )'
    );

    $pdo->exec(
        'CREATE TABLE chats (
            telegram_id INTEGER PRIMARY KEY
        )'
    );

    $pdo->exec(
        'CREATE TABLE feature_flags (
            flag_key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            rollout_percentage INTEGER NOT NULL,
            description TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL
        )'
    );

    $pdo->exec(
        "CREATE TABLE job_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_type TEXT NOT NULL,
            unique_key TEXT,
            payload_json TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'queued',
            priority INTEGER NOT NULL DEFAULT 0,
            available_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            locked_by TEXT,
            locked_at INTEGER,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT
        )"
    );

    $pdo->exec(
        'CREATE UNIQUE INDEX idx_job_queue_unique_key
         ON job_queue (unique_key)
         WHERE unique_key IS NOT NULL'
    );

    $pdo->exec(
        'INSERT INTO users (telegram_id)
         VALUES
            (1001),
            (1002),
            (1003)'
    );

    $pdo->exec(
        'INSERT INTO chats (telegram_id)
         VALUES (2001)'
    );

    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/'
        . '012_release_four_file_tools.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'Release-four migration could not be read.'
        );
    }

    $pdo->exec($migration);

    $repository = new FileJobRepository(
        pdo: $pdo,
        queue: new JobQueue($pdo),
        maxActivePerUser: 5,
        maxGlobalProcessing: 4,
        defaultMaxAttempts: 3
    );

    $jobId = $repository->create(
        userId: 1001,
        chatId: 2001,
        requestMessageId: 10,
        operation: 'totxt',
        sourceKind: 'text',
        source: ['input_text' => 'hello']
    );

    $assert(
        $jobId > 0
        && $repository->activeCountForUser(1001) === 1,
        'File job creation failed.'
    );

    $secondUserJobBlocked = false;

    try {
        $repository->create(
            userId: 1001,
            chatId: 2001,
            requestMessageId: 11,
            operation: 'tojson',
            sourceKind: 'text',
            source: ['input_text' => 'second']
        );
    } catch (FileToolException $exception) {
        $secondUserJobBlocked =
            $exception->errorCode
            === 'user_active_job_limit';
    }

    $assert(
        $secondUserJobBlocked,
        'The fixed one-active-job-per-user ceiling was not enforced.'
    );

    $claimed = $repository->claim($jobId);

    $assert(
        is_array($claimed)
        && $claimed['status'] === 'processing',
        'File job claim failed.'
    );

    $repository->complete(
        $jobId,
        'output.txt',
        'text/plain',
        5
    );

    $assert(
        ($repository->find($jobId)['status'] ?? null)
            === 'completed',
        'File job completion failed.'
    );

    $jobTwo = $repository->create(
        userId: 1002,
        chatId: 2001,
        requestMessageId: 12,
        operation: 'totxt',
        sourceKind: 'text',
        source: ['input_text' => 'two']
    );

    $jobThree = $repository->create(
        userId: 1003,
        chatId: 2001,
        requestMessageId: 13,
        operation: 'totxt',
        sourceKind: 'text',
        source: ['input_text' => 'three']
    );

    $assert(
        is_array($repository->claim($jobTwo))
        && is_array($repository->claim($jobThree)),
        'Two global processing slots could not be claimed.'
    );

    $jobFour = $repository->create(
        userId: 1001,
        chatId: 2001,
        requestMessageId: 14,
        operation: 'totxt',
        sourceKind: 'text',
        source: ['input_text' => 'four']
    );

    $globalLimitEnforced = false;

    try {
        $repository->claim($jobFour);
    } catch (FileToolException $exception) {
        $globalLimitEnforced =
            $exception->errorCode
            === 'global_processing_limit';
    }

    $assert(
        $globalLimitEnforced,
        'The fixed two-processing-jobs global ceiling was not enforced.'
    );

    $feature = $pdo->query(
        "SELECT enabled, rollout_percentage
         FROM feature_flags
         WHERE flag_key = 'file_tools'"
    )->fetch(PDO::FETCH_NUM);

    $assert(
        is_array($feature)
        && (int) $feature[0] === 1
        && (int) $feature[1] === 100,
        'File-tools feature flag failed.'
    );

    $databaseStatus = 'passed';
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $temporaryDirectory,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}

rmdir($temporaryDirectory);

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'file_reference_extractor' => true,
            'text_to_json' => true,
            'text_to_csv' => true,
            'capability_detection' => true,
            'fixed_text_limit' => true,
            'fixed_user_job_limit' => true,
            'fixed_global_job_limit' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
