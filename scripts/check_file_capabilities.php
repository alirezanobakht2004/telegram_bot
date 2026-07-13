<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Modules\FileTools\FileCapabilities;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath
        . '/bootstrap/app.php';

    $pdo = Database::connect(
        (string) $config->get(
            'database.path'
        )
    );

    $runtime = new RuntimeSettings(
        $config,
        $pdo
    );

    $capabilities = new FileCapabilities(
        pdo: $pdo,
        pdftotextPath: (string) $runtime->get(
            'modules.file_tools.binaries.pdftotext',
            ''
        ),
        pdfinfoPath: (string) $runtime->get(
            'modules.file_tools.binaries.pdfinfo',
            ''
        )
    );

    $capabilities->saveSnapshot();

    echo json_encode(
        [
            'status' => 'ready',
            'limits' => [
                'file_bytes' => min(
                    10485760,
                    max(
                        1024,
                        (int) $runtime->get(
                            'modules.file_tools.max_file_bytes',
                            10485760
                        )
                    )
                ),
                'image_pixels' => min(
                    12000000,
                    max(
                        1,
                        (int) $runtime->get(
                            'modules.file_tools.max_image_pixels',
                            12000000
                        )
                    )
                ),
                'pdf_pages' => min(
                    20,
                    max(
                        1,
                        (int) $runtime->get(
                            'modules.file_tools.max_pdf_pages',
                            20
                        )
                    )
                ),
                'extracted_text_bytes' => min(
                    512000,
                    max(
                        1024,
                        (int) $runtime->get(
                            'modules.file_tools.max_extracted_text_bytes',
                            512000
                        )
                    )
                ),
                'active_per_user' => 1,
                'global_processing' => min(
                    2,
                    max(
                        1,
                        (int) $runtime->get(
                            'modules.file_tools.max_global_processing',
                            2
                        )
                    )
                ),
                'job_timeout_seconds' => (int) $runtime->get(
                    'modules.file_tools.job_timeout_seconds',
                    45
                ),
            ],
            'capabilities' => $capabilities->all(),
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "[%s] File capability check failed: %s\n",
            date(DATE_ATOM),
            $exception->getMessage()
        )
    );

    exit(1);
}
