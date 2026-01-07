<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Exceptions;

use RuntimeException;

final class MissingRequiredCustomFieldsException extends RuntimeException
{
    /**
     * @param string[] $missingKeys
     * @param int[] $missingIds
     */
    public function __construct(
        public int $trackerId,
        public array $missingKeys = [],
        public array $missingIds = [],
    ) {
        $details = [];
        if ($missingIds !== []) {
            $details[] = sprintf('ids: %s', implode(', ', $missingIds));
        }
        if ($missingKeys !== []) {
            $details[] = sprintf('keys: %s', implode(', ', $missingKeys));
        }

        $message = sprintf(
            'Missing required custom fields for tracker %d%s',
            $trackerId,
            $details === [] ? '' : ' (' . implode('; ', $details) . ')',
        );

        parent::__construct($message);
    }
}
