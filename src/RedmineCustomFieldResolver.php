<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\Http\RedmineHttpClient;

final class RedmineCustomFieldResolver
{
    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $issueCustomFieldsByTracker = [];

    /**
     * @var array<int, array<string, int>>
     */
    private array $nameMapByTracker = [];

    /**
     * @var array<int, int[]>
     */
    private array $requiredIdsByTracker = [];

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $customFieldsCache = null;

    public function __construct(private RedmineHttpClient $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIssueCustomFieldsForTracker(int $trackerId, RequestContext $context): array
    {
        if (array_key_exists($trackerId, $this->issueCustomFieldsByTracker)) {
            return $this->issueCustomFieldsByTracker[$trackerId];
        }

        $fields = [];
        foreach ($this->getCustomFields($context) as $field) {
            if (($field['customized_type'] ?? null) !== 'issue') {
                continue;
            }

            if (!$this->customFieldAppliesToTracker($field, $trackerId)) {
                continue;
            }

            $normalized = [
                'id' => (int) ($field['id'] ?? 0),
                'name' => (string) ($field['name'] ?? ''),
                'required' => (bool) ($field['is_required'] ?? false),
                'possible_values' => $this->normalizePossibleValues($field['possible_values'] ?? null),
            ];

            if (array_key_exists('multiple', $field)) {
                $normalized['multiple'] = (bool) $field['multiple'];
            }

            $fields[] = $normalized;
        }

        $this->issueCustomFieldsByTracker[$trackerId] = $fields;

        return $fields;
    }

    /**
     * @return array<string, int>
     */
    public function getIssueCustomFieldNameToIdMapForTracker(int $trackerId, RequestContext $context): array
    {
        if (array_key_exists($trackerId, $this->nameMapByTracker)) {
            return $this->nameMapByTracker[$trackerId];
        }

        $map = [];
        foreach ($this->getIssueCustomFieldsForTracker($trackerId, $context) as $field) {
            $normalizedName = $this->normalizeFieldName($field['name'] ?? '');
            if ($normalizedName === '') {
                continue;
            }
            $map[$normalizedName] = (int) ($field['id'] ?? 0);
        }

        $this->nameMapByTracker[$trackerId] = $map;

        return $map;
    }

    /**
     * @return int[]
     */
    public function getRequiredIssueCustomFieldIdsForTracker(int $trackerId, RequestContext $context): array
    {
        if (array_key_exists($trackerId, $this->requiredIdsByTracker)) {
            return $this->requiredIdsByTracker[$trackerId];
        }

        $required = [];
        foreach ($this->getIssueCustomFieldsForTracker($trackerId, $context) as $field) {
            if (($field['required'] ?? false) === true) {
                $required[] = (int) ($field['id'] ?? 0);
            }
        }

        $this->requiredIdsByTracker[$trackerId] = $required;

        return $required;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCustomFields(RequestContext $context): array
    {
        if ($this->customFieldsCache !== null) {
            return $this->customFieldsCache;
        }

        $response = $this->client->request('GET', '/custom_fields.json', null, [], $context);
        $fields = $response['custom_fields'] ?? [];
        $this->customFieldsCache = is_array($fields) ? $fields : [];

        return $this->customFieldsCache;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function customFieldAppliesToTracker(array $field, int $trackerId): bool
    {
        $trackers = $field['trackers'] ?? null;
        if (!is_array($trackers)) {
            return false;
        }

        foreach ($trackers as $tracker) {
            if (is_array($tracker) && isset($tracker['id']) && (int) $tracker['id'] === $trackerId) {
                return true;
            }

            if (!is_array($tracker) && (int) $tracker === $trackerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function normalizePossibleValues(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $candidate = $value;
            if (is_array($value)) {
                $candidate = $value['value'] ?? null;
            }

            if (is_string($candidate) || is_numeric($candidate)) {
                $normalized[] = (string) $candidate;
            }
        }

        return $normalized;
    }

    private function normalizeFieldName(string $name): string
    {
        $normalized = $name;
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($converted !== false) {
                $normalized = $converted;
            }
        }

        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized;
    }
}
