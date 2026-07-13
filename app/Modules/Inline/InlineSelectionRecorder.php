<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Inline;

use PDO;
use SmartToolbox\Core\UpdateContext;

final class InlineSelectionRecorder
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function record(
        UpdateContext $context
    ): void {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return;
        }

        $resultId = $payload[
            'result_id'
        ] ?? null;
        $userId = $payload[
            'from'
        ]['id'] ?? null;

        if (
            !is_string($resultId)
            || $resultId === ''
            || !is_int($userId)
        ) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO inline_result_selections (
                user_id,
                result_id,
                inline_message_id,
                query_text,
                selected_at
             ) VALUES (
                :user_id,
                :result_id,
                :inline_message_id,
                :query_text,
                :selected_at
             )'
        );

        $statement->execute([
            'user_id' => $userId,
            'result_id' => mb_substr(
                $resultId,
                0,
                100
            ),
            'inline_message_id' =>
                is_string(
                    $payload[
                        'inline_message_id'
                    ] ?? null
                )
                    ? mb_substr(
                        $payload[
                            'inline_message_id'
                        ],
                        0,
                        300
                    )
                    : null,
            'query_text' =>
                is_string(
                    $payload['query'] ?? null
                )
                    ? mb_substr(
                        $payload['query'],
                        0,
                        1000
                    )
                    : null,
            'selected_at' => date(DATE_ATOM),
        ]);
    }
}
