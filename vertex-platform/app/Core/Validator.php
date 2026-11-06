<?php

declare(strict_types=1);

namespace Vertex\Core;

/**
 * Server-side input validation. Rules are pipe-separated:
 *   required | email | min:N | max:N | numeric | integer | in:a,b,c | regex:/.../ | confirmed
 * Lengths use mb_strlen. Empty non-required fields skip the remaining rules.
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    private function __construct(private array $data, private array $rules, private array $labels)
    {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /** @return array<string,mixed> only fields that have rules and passed */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $rules = $ruleString === '' ? [] : explode('|', $ruleString);
            $required = in_array('required', $rules, true);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $this->fail($field, 'is required');
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }

            $before = count($this->errors[$field] ?? []);
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, $value, $name, $arg);
            }
            if (count($this->errors[$field] ?? []) === $before) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function apply(string $field, mixed $value, string $rule, ?string $arg): void
    {
        $str = is_scalar($value) ? (string) $value : '';
        switch ($rule) {
            case 'required':
                break;
            case 'email':
                if (!is_scalar($value) || !filter_var($str, FILTER_VALIDATE_EMAIL) || mb_strlen($str) > 190) {
                    $this->fail($field, 'must be a valid email address');
                }
                break;
            case 'min':
                if (mb_strlen($str) < (int) $arg) {
                    $this->fail($field, "must be at least {$arg} characters");
                }
                break;
            case 'max':
                if (mb_strlen($str) > (int) $arg) {
                    $this->fail($field, "must be at most {$arg} characters");
                }
                break;
            case 'numeric':
                if (!is_numeric($str)) {
                    $this->fail($field, 'must be a number');
                }
                break;
            case 'integer':
                if (filter_var($str, FILTER_VALIDATE_INT) === false) {
                    $this->fail($field, 'must be a whole number');
                }
                break;
            case 'in':
                if (!in_array($str, explode(',', (string) $arg), true)) {
                    $this->fail($field, 'has an invalid value');
                }
                break;
            case 'regex':
                if (!is_scalar($value) || @preg_match((string) $arg, $str) !== 1) {
                    $this->fail($field, 'has an invalid format');
                }
                break;
            case 'confirmed':
                if (!hash_equals($str, (string) ($this->data[$field . '_confirmation'] ?? ''))) {
                    $this->fail($field, 'confirmation does not match');
                }
                break;
            default:
                throw new \LogicException("Unknown validation rule '{$rule}'.");
        }
    }

    private function fail(string $field, string $message): void
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        $this->errors[$field][] = "{$label} {$message}.";
    }
}
