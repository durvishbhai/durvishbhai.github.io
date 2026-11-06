<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Module 23: the dynamic form builder.
 *  - An administrator builds a form from typed fields (validation, required, min/max, options) with conditional "show this field only if..." rules.
 *  - A form is public at /f/<slug>, and its access can be: anyone with the link, only people with a secret link, signed-in users, or signed-in clients.
 *  - Answers are validated on the SERVER against the form as it is now (hidden fields are ignored, so a crafted post cannot fill them), stored with a
 *    snapshot of each question's label (later edits to the form never rewrite history), and exported to CSV.
 *  - A form may also create a CRM lead from the fields mapped to enquiry columns. Staff with `forms.view_submissions` are notified.
 */
final class FormService
{
    public const MAX_FIELDS = 60;

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM forms WHERE public_id = ?', [$publicId]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::one('SELECT * FROM forms WHERE slug = ? AND archived_at IS NULL', [$slug]);
    }

    /** @return list<array<string,mixed>> */
    public static function fields(array $form): array
    {
        $rows = Database::all('SELECT * FROM form_fields WHERE form_id = ? ORDER BY position, id', [$form['id']]);
        foreach ($rows as &$r) {
            $r['options'] = $r['options_json'] === null ? [] : (array) json_decode((string) $r['options_json'], true);
        }

        return $rows;
    }

    // ------------------------------------------------------------------------------------------------ building

    /** @param array<string,mixed> $in */
    public static function create(int $actorId, array $in, ?string $template = null): array
    {
        Gate::authorize('forms.manage', $actorId);
        $tpl = $template !== null && $template !== '' ? (Config::get("forms.templates.{$template}") ?? throw new ValidationException(['Unknown template.'])) : null;
        foreach (['title', 'description'] as $k) {
            if (trim((string) ($in[$k] ?? '')) === '' && $tpl !== null) {
                $in[$k] = $tpl[$k] ?? '';
            }
        }
        $v = self::validatedForm($in, null);
        $pub = uuid_hex();

        return Database::transaction(function () use ($actorId, $v, $tpl, $pub): array {
            Database::run(
                "INSERT INTO forms (public_id, slug, title, description, status, access, access_token, success_message, submit_label, notify_permission, create_enquiry, max_submissions, closes_on, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $v['slug'], $v['title'], $v['description'], $v['access'], $v['access'] === 'private_link' ? bin2hex(random_bytes(16)) : null, $v['success'], $v['submit_label'], 'forms.view_submissions', $tpl !== null && !empty($tpl['create_enquiry']) ? 1 : $v['create_enquiry'], $v['max'], $v['closes_on'], $actorId],
            );
            $id = Database::insertId();
            if ($tpl !== null) {
                foreach ($tpl['fields'] as $pos => $f) {
                    self::insertField($id, $pos + 1, [
                        'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => !empty($f['required']) ? '1' : '', 'options' => implode("\n", $f['options'] ?? []),
                        'min' => (string) ($f['min'] ?? ''), 'max' => (string) ($f['max'] ?? ''), 'maps_to' => $f['maps_to'] ?? '',
                        'show_if_field' => $f['show_if'][0] ?? '', 'show_if_op' => $f['show_if'][1] ?? '', 'show_if_value' => $f['show_if'][2] ?? '',
                    ], true);
                }
            }
            Audit::record('form.created', 'form', $id, [], ['slug' => $v['slug'], 'template' => $tpl === null ? null : 'yes'], [], $actorId);

            return ['public_id' => $pub, 'id' => $id];
        });
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $form, array $in): void
    {
        Gate::authorize('forms.manage', $actorId);
        $v = self::validatedForm($in, $form);
        Database::transaction(function () use ($actorId, $form, $v): void {
            $token = $form['access_token'];
            if ($v['access'] === 'private_link' && $token === null) {
                $token = bin2hex(random_bytes(16));
            }
            Database::run('UPDATE forms SET title = ?, description = ?, access = ?, access_token = ?, success_message = ?, submit_label = ?, create_enquiry = ?, max_submissions = ?, closes_on = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$v['title'], $v['description'], $v['access'], $v['access'] === 'private_link' ? $token : null, $v['success'], $v['submit_label'], $v['create_enquiry'], $v['max'], $v['closes_on'], $form['id']]);
            Audit::record('form.updated', 'form', (int) $form['id'], ['access' => $form['access'], 'status' => $form['status']], ['access' => $v['access']], [], $actorId);
        });
    }

    public static function setStatus(int $actorId, array $form, string $status): void
    {
        Gate::authorize('forms.manage', $actorId);
        if (!in_array($status, ['draft', 'published', 'closed'], true)) {
            throw new HttpException(422);
        }
        if ($status === 'published') {
            $fields = array_filter(self::fields($form), static fn (array $f): bool => !in_array($f['field_type'], (array) Config::get('forms.display_only'), true));
            if ($fields === []) {
                throw new ValidationException(['Add at least one question before publishing.']);
            }
            if ((int) $form['create_enquiry'] === 1) {
                $mapped = array_column($fields, 'maps_to');
                if (!in_array('contact_name', $mapped, true) || !in_array('email', $mapped, true)) {
                    throw new ValidationException(['A form that creates CRM leads needs a question mapped to "Contact name" and one mapped to "Email".']);
                }
            }
        }
        Database::run('UPDATE forms SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $form['id']]);
        Audit::record('form.' . $status, 'form', (int) $form['id'], ['status' => $form['status']], ['status' => $status], [], $actorId);
    }

    public static function archive(int $actorId, array $form): void
    {
        Gate::authorize('forms.manage', $actorId);
        Database::run("UPDATE forms SET archived_at = UTC_TIMESTAMP(), status = 'closed', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$form['id']]);
        Audit::record('form.archived', 'form', (int) $form['id'], [], [], ['slug' => $form['slug']], $actorId);
    }

    public static function rotateToken(int $actorId, array $form): void
    {
        Gate::authorize('forms.manage', $actorId);
        if ($form['access'] !== 'private_link') {
            throw new ValidationException(['Only forms with a private link have a secret key.']);
        }
        Database::run('UPDATE forms SET access_token = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [bin2hex(random_bytes(16)), $form['id']]);
        Audit::record('form.link_rotated', 'form', (int) $form['id'], [], [], [], $actorId);
    }

    /** @param array<string,mixed> $in @param array<string,mixed>|null $existing */
    private static function validatedForm(array $in, ?array $existing): array
    {
        $errors = [];
        $get = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $title = (string) $get(static fn () => FieldRules::line($in['title'] ?? '', 'Title', 3, 160));
        $desc = $get(static fn () => FieldRules::text($in['description'] ?? '', 'Description', 2, 2000, false));
        $slug = $existing['slug'] ?? strtolower(trim((string) ($in['slug'] ?? '')));
        if ($existing === null) {
            if ($slug === '') {
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            }
            if (!preg_match('/^[a-z0-9][a-z0-9-]{2,59}$/', $slug) || in_array($slug, (array) Config::get('forms.reserved_slugs'), true)) {
                $errors[] = 'The web address must be 3 to 60 letters, digits or dashes (and not a reserved word).';
            } elseif (Database::one('SELECT 1 AS x FROM forms WHERE slug = ?', [$slug]) !== null) {
                $errors[] = 'That web address is already used by another form.';
            }
        }
        $access = (string) $get(static fn () => FieldRules::choice($in['access'] ?? 'public', (array) Config::get('forms.access'), 'access setting', false, 'public'));
        $success = $get(static fn () => FieldRules::line($in['success_message'] ?? '', 'Thank-you message', 2, 500, false));
        $label = $get(static fn () => FieldRules::line($in['submit_label'] ?? '', 'Button text', 2, 40, false));
        $max = trim((string) ($in['max_submissions'] ?? ''));
        if ($max !== '' && (!ctype_digit($max) || (int) $max < 1 || (int) $max > 1_000_000)) {
            $errors[] = 'Maximum submissions must be a whole number of 1 or more, or empty.';
        }
        $closes = $get(static fn () => FieldRules::date($in['closes_on'] ?? '', 'Closing date', false));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'description' => is_string($desc) ? $desc : null, 'slug' => $slug, 'access' => $access, 'success' => is_string($success) ? $success : null, 'submit_label' => is_string($label) && $label !== '' ? $label : 'Submit',
            'create_enquiry' => !empty($in['create_enquiry']) ? 1 : 0, 'max' => $max === '' ? null : (int) $max, 'closes_on' => is_string($closes) ? $closes : null];
    }

    // ------------------------------------------------------------------------------------------------ fields

    /** @param array<string,mixed> $in */
    public static function addField(int $actorId, array $form, array $in): void
    {
        Gate::authorize('forms.manage', $actorId);
        Database::transaction(function () use ($actorId, $form, $in): void {
            Database::one('SELECT id FROM forms WHERE id = ? FOR UPDATE', [$form['id']]);
            $n = (int) Database::one('SELECT COUNT(*) AS n FROM form_fields WHERE form_id = ?', [$form['id']])['n'];
            if ($n >= self::MAX_FIELDS) {
                throw new ValidationException(['A form can have at most ' . self::MAX_FIELDS . ' questions.']);
            }
            $pos = (int) Database::one('SELECT COALESCE(MAX(position), 0) AS p FROM form_fields WHERE form_id = ?', [$form['id']])['p'] + 1;
            $key = self::insertField((int) $form['id'], $pos, $in);
            Audit::record('form.field_added', 'form', (int) $form['id'], [], ['key' => $key], [], $actorId);
        });
    }

    /** @param array<string,mixed> $in */
    public static function updateField(int $actorId, array $form, int $fieldId, array $in): void
    {
        Gate::authorize('forms.manage', $actorId);
        $cur = Database::one('SELECT * FROM form_fields WHERE id = ? AND form_id = ?', [$fieldId, $form['id']]) ?? throw new HttpException(404);
        $v = self::validatedField($in + ['key' => $cur['field_key']], (int) $form['id'], $fieldId);
        Database::transaction(function () use ($actorId, $form, $cur, $v): void {
            Database::run('UPDATE form_fields SET label = ?, field_type = ?, required = ?, options_json = ?, placeholder = ?, help_text = ?, min_value = ?, max_value = ?, show_if_field = ?, show_if_op = ?, show_if_value = ?, maps_to = ? WHERE id = ?',
                [$v['label'], $v['type'], $v['required'], $v['options'] === [] ? null : json_encode($v['options'], JSON_UNESCAPED_UNICODE), $v['placeholder'], $v['help'], $v['min'], $v['max'], $v['show_field'], $v['show_op'], $v['show_value'], $v['maps_to'], $cur['id']]);
            Audit::record('form.field_updated', 'form', (int) $form['id'], [], [], ['key' => $cur['field_key']], $actorId);
        });
    }

    public static function deleteField(int $actorId, array $form, int $fieldId): void
    {
        Gate::authorize('forms.manage', $actorId);
        $cur = Database::one('SELECT * FROM form_fields WHERE id = ? AND form_id = ?', [$fieldId, $form['id']]) ?? throw new HttpException(404);
        Database::transaction(function () use ($actorId, $form, $cur): void {
            Database::run('UPDATE form_fields SET show_if_field = NULL, show_if_op = NULL, show_if_value = NULL WHERE form_id = ? AND show_if_field = ?', [$form['id'], $cur['field_key']]);
            Database::run('DELETE FROM form_fields WHERE id = ?', [$cur['id']]);
            Audit::record('form.field_removed', 'form', (int) $form['id'], [], [], ['key' => $cur['field_key']], $actorId);
        });
    }

    public static function moveField(int $actorId, array $form, int $fieldId, string $dir): void
    {
        Gate::authorize('forms.manage', $actorId);
        Database::transaction(function () use ($form, $fieldId, $dir): void {
            $rows = Database::all('SELECT id, position FROM form_fields WHERE form_id = ? ORDER BY position, id FOR UPDATE', [$form['id']]);
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $i = array_search($fieldId, $ids, true);
            if ($i === false) {
                throw new HttpException(404);
            }
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if (!isset($ids[$j])) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $pos => $id) {
                Database::run('UPDATE form_fields SET position = ? WHERE id = ?', [$pos + 1, $id]);
            }
        });
    }

    /** @param array<string,mixed> $in @return string the field key */
    private static function insertField(int $formId, int $position, array $in, bool $trusted = false): string
    {
        $v = self::validatedField($in, $formId, null);
        Database::run(
            'INSERT INTO form_fields (form_id, position, field_key, label, field_type, required, options_json, placeholder, help_text, min_value, max_value, show_if_field, show_if_op, show_if_value, maps_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$formId, $position, $v['key'], $v['label'], $v['type'], $v['required'], $v['options'] === [] ? null : json_encode($v['options'], JSON_UNESCAPED_UNICODE), $v['placeholder'], $v['help'], $v['min'], $v['max'], $v['show_field'], $v['show_op'], $v['show_value'], $v['maps_to']],
        );

        return $v['key'];
    }

    /** @param array<string,mixed> $in */
    private static function validatedField(array $in, int $formId, ?int $fieldId): array
    {
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        $types = (array) Config::get('forms.types');
        if (!isset($types[$type])) {
            $errors[] = 'Choose a question type.';
        }
        $label = trim((string) preg_replace('/\s+/', ' ', (string) ($in['label'] ?? '')));
        if (mb_strlen($label) < 1 || mb_strlen($label) > 200) {
            $errors[] = 'The question text must be 1 to 200 characters.';
        }
        $key = strtolower(trim((string) ($in['key'] ?? '')));
        if ($key === '') {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
            $key = substr($key === '' ? 'field' : $key, 0, 30);
            $base = $key;
            for ($n = 2; Database::one('SELECT 1 AS x FROM form_fields WHERE form_id = ? AND field_key = ? AND id <> ?', [$formId, $key, $fieldId ?? 0]) !== null; $n++) {
                $key = substr($base, 0, 36) . '_' . $n;
            }
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
            $errors[] = 'The field key must start with a letter and use only a-z, 0-9 and underscores.';
        } elseif (Database::one('SELECT 1 AS x FROM form_fields WHERE form_id = ? AND field_key = ? AND id <> ?', [$formId, $key, $fieldId ?? 0]) !== null) {
            $errors[] = 'Another question already uses the key ' . $key . '.';
        }
        $options = [];
        if (in_array($type, (array) Config::get('forms.choice_types'), true)) {
            foreach (preg_split('/\R/', (string) ($in['options'] ?? '')) ?: [] as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $options[$o] = mb_substr($o, 0, 120);
                }
            }
            $options = array_values($options);
            if (count($options) < 2 || count($options) > 50) {
                $errors[] = 'Give between 2 and 50 choices, one per line.';
            }
        }
        $display = in_array($type, (array) Config::get('forms.display_only'), true);
        $min = self::intOrNull($in['min'] ?? '', 'Minimum', $errors);
        $max = self::intOrNull($in['max'] ?? '', 'Maximum', $errors);
        if ($min !== null && $max !== null && $max < $min) {
            $errors[] = 'The maximum cannot be below the minimum.';
        }
        $showField = trim((string) ($in['show_if_field'] ?? ''));
        $showOp = (string) ($in['show_if_op'] ?? '');
        $showValue = trim((string) ($in['show_if_value'] ?? ''));
        if ($showField === '') {
            $showField = $showOp = $showValue = '';
        } else {
            $ref = Database::one('SELECT field_type FROM form_fields WHERE form_id = ? AND field_key = ?', [$formId, $showField]);
            if ($ref === null || $showField === $key) {
                $errors[] = 'The "show only if" rule must point at another question of this form.';
            } elseif (!in_array($showOp, ['equals', 'not_equals', 'contains', 'filled'], true)) {
                $errors[] = 'Choose how the "show only if" rule compares.';
            } elseif ($showOp !== 'filled' && $showValue === '') {
                $errors[] = 'Enter the value the "show only if" rule compares with.';
            }
            if ($ref !== null && $fieldId !== null && self::wouldLoop($formId, $key, $showField)) {
                $errors[] = 'These "show only if" rules would depend on each other in a circle.';
            }
        }
        $maps = trim((string) ($in['maps_to'] ?? ''));
        if ($maps !== '' && !isset(((array) Config::get('forms.enquiry_fields'))[$maps])) {
            $errors[] = 'Unknown CRM field.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => !$display && !empty($in['required']) ? 1 : 0, 'options' => $options, 'placeholder' => self::nullable($in['placeholder'] ?? '', 120), 'help' => self::nullable($in['help'] ?? '', 255),
            'min' => $display ? null : $min, 'max' => $display ? null : $max, 'show_field' => $showField === '' ? null : $showField, 'show_op' => $showOp === '' ? null : $showOp, 'show_value' => $showValue === '' ? null : mb_substr($showValue, 0, 190), 'maps_to' => $maps === '' || $display ? null : $maps];
    }

    private static function wouldLoop(int $formId, string $key, string $dependsOn): bool
    {
        $seen = [$key => true];
        $cur = $dependsOn;
        for ($i = 0; $i < 70; $i++) {
            if (isset($seen[$cur])) {
                return true;
            }
            $seen[$cur] = true;
            $next = Database::one('SELECT show_if_field FROM form_fields WHERE form_id = ? AND field_key = ?', [$formId, $cur])['show_if_field'] ?? null;
            if ($next === null) {
                return false;
            }
            $cur = (string) $next;
        }

        return true;
    }

    /** @param list<string> $errors */
    private static function intOrNull(mixed $v, string $label, array &$errors): ?int
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^-?\d{1,9}$/', $s)) {
            $errors[] = "{$label} must be a whole number.";

            return null;
        }

        return (int) $s;
    }

    private static function nullable(mixed $v, int $max): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    // ------------------------------------------------------------------------------------------------ filling in

    /** Why the viewer cannot fill this form in right now, or null when they can. */
    public static function blocker(array $form, ?int $userId, ?string $token): ?string
    {
        if ($form['archived_at'] !== null || $form['status'] === 'draft') {
            return 'not_found';
        }
        if ($form['access'] === 'private_link' && !hash_equals((string) $form['access_token'], (string) $token)) {
            return 'not_found';
        }
        if ($form['status'] === 'closed' || ($form['closes_on'] !== null && AppTime::today() > $form['closes_on'])) {
            return 'closed';
        }
        if ($form['max_submissions'] !== null && (int) Database::one('SELECT COUNT(*) AS n FROM form_submissions WHERE form_id = ?', [$form['id']])['n'] >= (int) $form['max_submissions']) {
            return 'closed';
        }
        if (in_array($form['access'], ['login', 'client'], true)) {
            if ($userId === null) {
                return 'login';
            }
            if ($form['access'] === 'client' && Database::one('SELECT 1 AS x FROM clients WHERE user_id = ? AND archived_at IS NULL', [$userId]) === null) {
                return 'forbidden';
            }
        }

        return null;
    }

    /** Is a field visible given the answers so far? A field whose controlling field is itself hidden is hidden too. @param list<array<string,mixed>> $fields @param array<string,mixed> $answers */
    public static function visible(array $field, array $fields, array $answers, int $depth = 0): bool
    {
        if ($field['show_if_field'] === null || $depth > 70) {
            return true;
        }
        $ctrl = null;
        foreach ($fields as $f) {
            if ($f['field_key'] === $field['show_if_field']) {
                $ctrl = $f;
            }
        }
        if ($ctrl === null || !self::visible($ctrl, $fields, $answers, $depth + 1)) {
            return false;
        }
        $given = $answers[$field['show_if_field']] ?? '';
        $flat = is_array($given) ? implode("\n", array_map('strval', $given)) : (string) $given;
        $want = (string) $field['show_if_value'];

        return match ($field['show_if_op']) {
            'equals' => is_array($given) ? in_array($want, array_map('strval', $given), true) : $flat === $want,
            'not_equals' => is_array($given) ? !in_array($want, array_map('strval', $given), true) : $flat !== $want,
            'contains' => $want !== '' && mb_stripos($flat, $want) !== false,
            'filled' => trim($flat) !== '' && $flat !== '0',
            default => true,
        };
    }

    /**
     * @param array<string,mixed> $raw posted answers keyed by field key
     * @return array{public_id:string,reference:string,enquiry_ref:?string}
     */
    public static function submit(array $form, array $raw, ?int $userId, ?string $token, string $ip, bool $consent, string $submitToken = ''): array
    {
        $why = self::blocker($form, $userId, $token);
        if ($why !== null) {
            throw new HttpException($why === 'login' ? 401 : ($why === 'forbidden' ? 403 : 404));
        }
        $fields = self::fields($form);
        $clean = [];
        $errors = [];
        foreach ($fields as $f) {
            if (in_array($f['field_type'], (array) Config::get('forms.display_only'), true) || !self::visible($f, $fields, $raw)) {
                continue;
            }
            try {
                $clean[$f['field_key']] = self::answer($f, $raw[$f['field_key']] ?? null);
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ((int) $form['create_enquiry'] === 1 && !$consent) {
            $errors[] = 'Please confirm that we may contact you about this request.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($submitToken !== '' && preg_match('/^[a-f0-9]{32}$/', $submitToken)) {
            $dup = Database::one('SELECT public_id, reference FROM form_submissions WHERE submit_token = ?', [$submitToken]);
            if ($dup !== null) {
                return ['public_id' => $dup['public_id'], 'reference' => $dup['reference'], 'enquiry_ref' => null];
            }
        }
        $snapshot = [];
        foreach ($fields as $f) {
            if (array_key_exists($f['field_key'], $clean)) {
                $snapshot[] = ['key' => $f['field_key'], 'label' => $f['label'], 'type' => $f['field_type'], 'value' => $clean[$f['field_key']]];
            }
        }

        return Database::transaction(function () use ($form, $fields, $clean, $snapshot, $userId, $ip, $submitToken, $consent): array {
            $locked = Database::one('SELECT * FROM forms WHERE id = ? FOR UPDATE', [$form['id']]);
            if ($locked['max_submissions'] !== null && (int) Database::one('SELECT COUNT(*) AS n FROM form_submissions WHERE form_id = ?', [$form['id']])['n'] >= (int) $locked['max_submissions']) {
                throw new HttpException(404);
            }
            $count = (int) Database::one('SELECT COUNT(*) AS n FROM form_submissions WHERE form_id = ?', [$form['id']])['n'];
            $ref = 'FS' . strtoupper(substr((string) $form['public_id'], 0, 4)) . '-' . gmdate('y') . '-' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $pub = uuid_hex();
            $enquiryId = null;
            $enquiryRef = null;
            if ((int) $form['create_enquiry'] === 1) {
                $in = [];
                foreach ($fields as $f) {
                    if ($f['maps_to'] !== null && isset($clean[$f['field_key']])) {
                        $val = $clean[$f['field_key']];
                        $in[$f['maps_to']] = is_array($val) ? implode(', ', $val) : (string) $val;
                    }
                }
                $in['description'] = ($in['description'] ?? '') !== '' ? $in['description'] : 'Submitted through the form "' . $form['title'] . '".';
                try {
                    $e = EnquiryService::create(null, 'contact_form', $in, ['consent' => $consent, 'ip' => $ip]);
                    $enquiryId = $e['id'];
                    $enquiryRef = $e['reference'];
                } catch (ValidationException) {
                    $enquiryId = null; // the answers are still stored; staff see the submission
                }
            }
            Database::run(
                'INSERT INTO form_submissions (public_id, form_id, reference, submitted_by, ip_hash, data_json, status, enquiry_id, submit_token, created_at) VALUES (?, ?, ?, ?, ?, ?, \'new\', ?, ?, UTC_TIMESTAMP())',
                [$pub, $form['id'], $ref, $userId, hash_hmac('sha256', $ip, (string) Config::get('app.key', 'unset')), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $enquiryId, $submitToken !== '' ? $submitToken : null],
            );
            $id = Database::insertId();
            Audit::record('form.submitted', 'form_submission', $id, [], [], ['form' => $form['slug'], 'reference' => $ref], $userId);
            Notifier::send(Notifier::withPermission('forms.view_submissions'), 'form.submitted', 'New answer: ' . $form['title'], $ref, '/forms/' . $form['public_id'] . '/submissions', null, 'form_submission', $id, 'fsub:' . $id);

            return ['public_id' => $pub, 'reference' => $ref, 'enquiry_ref' => $enquiryRef];
        });
    }

    /** Validates one visible answer. @param array<string,mixed> $f */
    private static function answer(array $f, mixed $v): mixed
    {
        $label = (string) $f['label'];
        $req = (int) $f['required'] === 1;
        $min = $f['min_value'];
        $max = $f['max_value'];
        switch ($f['field_type']) {
            case 'text':
            case 'textarea':
                $limit = $f['field_type'] === 'text' ? 300 : 5000;
                $s = FieldRules::clean(is_array($v) ? '' : $v);
                if ($s === '') {
                    if ($req) {
                        throw new ValidationException(["{$label} is required."]);
                    }

                    return '';
                }
                if (($min !== null && mb_strlen($s) < (int) $min) || mb_strlen($s) > ($max !== null ? min((int) $max, $limit) : $limit)) {
                    throw new ValidationException(["{$label} must be " . ($min !== null ? "at least {$min} and " : '') . 'at most ' . ($max !== null ? min((int) $max, $limit) : $limit) . ' characters.']);
                }

                return $s;
            case 'email':
                return (string) (FieldRules::email(is_array($v) ? '' : $v, $req) ?? '');
            case 'phone':
                return (string) (FieldRules::phone(is_array($v) ? '' : $v, $req) ?? '');
            case 'date':
                return (string) (FieldRules::date(is_array($v) ? '' : $v, $label, $req) ?? '');
            case 'number':
                $s = trim(is_array($v) ? '' : (string) $v);
                if ($s === '') {
                    if ($req) {
                        throw new ValidationException(["{$label} is required."]);
                    }

                    return '';
                }
                if (!preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $s)) {
                    throw new ValidationException(["{$label} must be a number."]);
                }
                if (($min !== null && (float) $s < (float) $min) || ($max !== null && (float) $s > (float) $max)) {
                    throw new ValidationException(["{$label} must be between " . ($min ?? 'any') . ' and ' . ($max ?? 'any') . '.']);
                }

                return $s;
            case 'checkbox':
                $on = $v === '1' || $v === 1 || $v === true || $v === 'on';
                if ($req && !$on) {
                    throw new ValidationException(["{$label} must be ticked."]);
                }

                return $on ? 'Yes' : 'No';
            case 'select':
            case 'radio':
                $s = trim(is_array($v) ? '' : (string) $v);
                if ($s === '') {
                    if ($req) {
                        throw new ValidationException(["{$label} is required."]);
                    }

                    return '';
                }
                if (!in_array($s, $f['options'], true)) {
                    throw new ValidationException(["Choose one of the listed options for {$label}."]);
                }

                return $s;
            case 'multiselect':
                $list = is_array($v) ? array_values(array_unique(array_map('strval', $v))) : [];
                foreach ($list as $o) {
                    if (!in_array($o, $f['options'], true)) {
                        throw new ValidationException(["Choose only the listed options for {$label}."]);
                    }
                }
                if ($req && $list === []) {
                    throw new ValidationException(["{$label} is required."]);
                }
                if (($min !== null && count($list) < (int) $min && ($list !== [] || $req)) || ($max !== null && count($list) > (int) $max)) {
                    throw new ValidationException(["Choose between " . ($min ?? 0) . ' and ' . ($max ?? count($f['options'])) . " options for {$label}."]);
                }

                return $list;
        }

        return '';
    }

    // ------------------------------------------------------------------------------------------------ reading answers

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function submissions(int $userId, array $form, array $f, int $page = 1, int $perPage = 25): array
    {
        Gate::authorize('forms.view_submissions', $userId);
        $w = 'form_id = ?';
        $p = [$form['id']];
        if (in_array($f['status'] ?? '', ['new', 'reviewed', 'archived'], true)) {
            $w .= ' AND status = ?';
            $p[] = $f['status'];
        }
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM form_submissions WHERE {$w}", $p)['n'];
        $perPage = max(1, min(200, $perPage));
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT s.*, u.name AS user_name FROM form_submissions s LEFT JOIN users u ON u.id = s.submitted_by WHERE s.{$w} ORDER BY s.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    public static function markSubmission(int $userId, string $publicId, string $status): void
    {
        Gate::authorize('forms.view_submissions', $userId);
        if (!in_array($status, ['new', 'reviewed', 'archived'], true)) {
            throw new HttpException(422);
        }
        $n = Database::run('UPDATE form_submissions SET status = ? WHERE public_id = ?', [$status, $publicId])->rowCount();
        if ($n > 0) {
            Audit::record('form.submission_' . $status, 'form_submission', null, [], [], ['public_id' => $publicId], $userId);
        }
    }

    /** @return array{csv:string,count:int} */
    public static function exportCsv(int $userId, array $form): array
    {
        Gate::authorize('forms.export', $userId);
        $fields = array_values(array_filter(self::fields($form), static fn (array $f): bool => !in_array($f['field_type'], (array) Config::get('forms.display_only'), true)));
        $headers = ['Reference', 'Submitted (UTC)', 'Submitted by', 'Status'];
        foreach ($fields as $f) {
            $headers[] = $f['label'];
        }
        $rows = [];
        foreach (Database::all('SELECT s.*, u.email AS user_email FROM form_submissions s LEFT JOIN users u ON u.id = s.submitted_by WHERE s.form_id = ? ORDER BY s.id LIMIT 20000', [$form['id']]) as $s) {
            $byKey = [];
            foreach ((array) json_decode((string) $s['data_json'], true) as $a) {
                $byKey[$a['key']] = is_array($a['value']) ? implode('; ', $a['value']) : (string) $a['value'];
            }
            $line = [$s['reference'], $s['created_at'], (string) $s['user_email'], $s['status']];
            foreach ($fields as $f) {
                $line[] = $byKey[$f['field_key']] ?? '';
            }
            $rows[] = $line;
        }
        Audit::record('data.exported', 'form', (int) $form['id'], [], [], ['type' => 'form:' . $form['slug'], 'rows' => count($rows)], $userId);

        return ['csv' => Csv::build($headers, $rows), 'count' => count($rows)];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT f.*, (SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = f.id) AS submissions, (SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = f.id AND s.status = \'new\') AS new_submissions FROM forms f WHERE f.archived_at IS NULL ORDER BY f.id DESC');
    }
}
