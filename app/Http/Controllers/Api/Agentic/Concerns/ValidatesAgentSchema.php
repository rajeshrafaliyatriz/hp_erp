<?php

namespace App\Http\Controllers\Api\Agentic\Concerns;

/**
 * Validates submitted values against an agent's declared field schema.
 *
 * Agents do not share an input contract - the Excel agent wants a spreadsheet,
 * the SEO agent a URL and an analysis mode, the marketing agent a business
 * type, an audience and a goal. Rather than hard-coding a screen per agent,
 * each carries a schema and this validates against it.
 *
 * The schema is data, so it is not trusted: an unknown type validates as free
 * text rather than throwing, and submitted keys with no matching field are
 * dropped instead of being forwarded to the endpoint.
 */
trait ValidatesAgentSchema
{
    /** Types whose value is a list rather than a scalar. */
    private const LIST_TYPES = ['multiselect', 'tags'];

    /**
     * Decode a schema column into a list of field definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function decodeSchema($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return [];
        }

        $fields = [];

        foreach ($value as $field) {
            // A field without a name has nothing to bind to.
            if (!is_array($field) || !isset($field['name']) || trim((string) $field['name']) === '') {
                continue;
            }

            $field['name'] = (string) $field['name'];
            $field['type'] = isset($field['type']) ? (string) $field['type'] : 'text';
            $field['label'] = isset($field['label']) ? (string) $field['label'] : $field['name'];
            $field['required'] = !empty($field['required']);
            $field['secret'] = !empty($field['secret']);

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Validate and coerce values against a schema.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>              $values
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    protected function applySchema(array $schema, array $values): array
    {
        $clean = [];
        $errors = [];

        foreach ($schema as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $label = $field['label'];

            $raw = $values[$name] ?? null;

            // A file is uploaded on its own path, so an absent one here is not
            // automatically an error - the caller checks for the upload.
            if ($type === 'file') {
                if (array_key_exists($name, $values) && $values[$name] !== null) {
                    $clean[$name] = $values[$name];
                }
                continue;
            }

            if ($this->isBlank($raw)) {
                if ($field['required']) {
                    $errors[$name] = $label . ' is required.';
                } elseif (array_key_exists('default', $field)) {
                    $clean[$name] = $field['default'];
                }
                continue;
            }

            $result = $this->coerceField($field, $raw);

            if (isset($result['error'])) {
                $errors[$name] = $result['error'];
                continue;
            }

            $clean[$name] = $result['value'];
        }

        return ['values' => $clean, 'errors' => $errors];
    }

    /** Empty string, empty list and null all count as "not answered". */
    private function isBlank($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @return array{value?: mixed, error?: string}
     */
    private function coerceField(array $field, $raw): array
    {
        $type = $field['type'];
        $label = $field['label'];

        switch ($type) {
            case 'number':
                if (!is_numeric($raw)) {
                    return ['error' => $label . ' must be a number.'];
                }

                $number = $raw + 0;

                if (isset($field['min']) && $number < $field['min']) {
                    return ['error' => $label . ' must be at least ' . $field['min'] . '.'];
                }

                if (isset($field['max']) && $number > $field['max']) {
                    return ['error' => $label . ' must be at most ' . $field['max'] . '.'];
                }

                return ['value' => $number];

            case 'boolean':
                return ['value' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false];

            case 'email':
                if (!filter_var((string) $raw, FILTER_VALIDATE_EMAIL)) {
                    return ['error' => $label . ' must be a valid email address.'];
                }

                return ['value' => (string) $raw];

            case 'url':
                $url = trim((string) $raw);

                // A bare domain is what people actually paste, so accept it and
                // normalise rather than rejecting on a missing scheme.
                if (!preg_match('~^https?://~i', $url)) {
                    $url = 'https://' . $url;
                }

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return ['error' => $label . ' must be a valid URL.'];
                }

                return ['value' => $url];

            case 'date':
                if (strtotime((string) $raw) === false) {
                    return ['error' => $label . ' must be a valid date.'];
                }

                return ['value' => date('Y-m-d', strtotime((string) $raw))];

            case 'select':
                $allowed = $this->optionValues($field);

                if ($allowed !== [] && !in_array((string) $raw, $allowed, true)) {
                    return ['error' => $label . ' must be one of: ' . implode(', ', $allowed) . '.'];
                }

                return ['value' => (string) $raw];

            case 'multiselect':
            case 'tags':
                $list = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string) $raw)), fn ($i) => $i !== '');
                $list = array_values(array_map('strval', $list));

                if ($type === 'multiselect') {
                    $allowed = $this->optionValues($field);

                    if ($allowed !== []) {
                        $unknown = array_diff($list, $allowed);

                        if ($unknown !== []) {
                            return ['error' => $label . ' has invalid options: ' . implode(', ', $unknown) . '.'];
                        }
                    }
                }

                return ['value' => $list];

            default:
                // text, textarea, password and anything unrecognised.
                $text = (string) $raw;

                if (isset($field['max_length']) && mb_strlen($text) > (int) $field['max_length']) {
                    return ['error' => $label . ' must be at most ' . $field['max_length'] . ' characters.'];
                }

                return ['value' => $text];
        }
    }

    /** @return array<int, string> */
    private function optionValues(array $field): array
    {
        if (!isset($field['options']) || !is_array($field['options'])) {
            return [];
        }

        $values = [];

        foreach ($field['options'] as $option) {
            if (is_array($option) && isset($option['value'])) {
                $values[] = (string) $option['value'];
            } elseif (is_scalar($option)) {
                $values[] = (string) $option;
            }
        }

        return $values;
    }

    /** True when the schema has at least one field the user must answer. */
    protected function schemaHasRequired(array $schema): bool
    {
        foreach ($schema as $field) {
            if ($field['required']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human-readable summary of the first few validation errors, for the
     * single `message` string the client shows in a toast.
     */
    protected function summariseErrors(array $errors): string
    {
        $messages = array_slice(array_values($errors), 0, 3);

        return implode(' ', $messages);
    }
}
