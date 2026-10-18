<?php
// Data: PDO helpers and validate(). No ORM, no query builder. See docs/SPEC.md §7.
declare(strict_types=1);

/** The shared PDO connection (lazy). A connection failure is logged and answered 503; it never shows the DSN. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $c = (array) config('db', []);
    $dsn = (string) ($c['dsn'] ?? '');
    $sqlite = str_starts_with($dsn, 'sqlite:');
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3];
    if (!$sqlite) $opts[PDO::ATTR_EMULATE_PREPARES] = false;
    try {
        if ($sqlite && !is_dir($dir = dirname(substr($dsn, 7)))) @mkdir($dir, 0775, true);
        $pdo = new PDO($dsn, $c['user'] ?? null, $c['pass'] ?? null, $opts);
        if ($sqlite) $pdo->exec('PRAGMA foreign_keys = ON');
    } catch (PDOException $e) {
        $pdo = null;
        ink_log('error', 'db(): cannot connect', ['driver' => strstr($dsn, ':', true) ?: '?', 'error' => $e->getMessage()]);
        throw new InkUnavailable('Database unavailable'); // fresh exception: no DSN/password in its trace
    }
    return $pdo;
}

/** Prepared statement, executed with $params (? or :name placeholders). */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row or null. */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }

/** First column of the first row, or null when there is no row. */
function val(string $sql, array $params = []): mixed
{
    $row = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

/** Run $fn(db()) in a transaction: commit, or roll back and rethrow. Nested calls join the outer transaction. */
function tx(callable $fn): mixed
{
    $db = db();
    if ($db->inTransaction()) return $fn($db);
    $db->beginTransaction();
    try {
        $result = $fn($db);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * [$clean, $errors] = validate(input(), ['email' => 'required|email', 'age' => 'int|min:18']);
 * Strings are trimmed first. Rules: required email int numeric url bool array min:N max:N in:a,b regex:/…/ (last).
 * $clean has exactly the fields with rules (typed for int/numeric/bool; null when empty and not required;
 * the trimmed input when invalid). $errors[field] = one message in the current locale.
 */
function validate(array $data, array $rules): array
{
    $clean = $errors = [];
    foreach ($rules as $field => $spec) {
        $list = ink_rules((string) $spec);
        $names = array_column($list, 0);
        $v = $data[$field] ?? null;
        if (is_string($v)) $v = trim($v);
        if ($v === null || $v === '' || $v === []) {
            $clean[$field] = null;
            if (in_array('required', $names, true)) $errors[$field] = ink_msg('必填', 'Required');
            continue;
        }
        $wantArray = in_array('array', $names, true);
        $number = in_array('int', $names, true) || in_array('numeric', $names, true);
        $error = null;
        if ($wantArray !== is_array($v)) {
            $error = $wantArray ? ['必须是列表', 'Must be a list'] : ['格式不正确', 'Invalid value'];
        } elseif (!is_array($v) && (!is_scalar($v) || (is_string($v) && !preg_match('//u', $v)))) {
            $error = ['格式不正确', 'Invalid value'];
        }
        $raw = $v;
        foreach ($list as [$rule, $arg]) {
            if ($error !== null) break;
            $error = ink_rule($rule, $arg, $v, $number);
        }
        if ($error !== null) {
            $errors[$field] = strtr(ink_msg($error[0], $error[1]), ['{n}' => (string) ($error[2] ?? '')]);
            $v = is_scalar($raw) ? $raw : null;
        }
        $clean[$field] = $v;
    }
    return [$clean, $errors];
}

/** Parse 'required|min:3|regex:/a|b/' → [[name, arg], …]. Unknown rules are a bug: LogicException. */
function ink_rules(string $spec): array
{
    $list = [];
    $at = strpos('|' . $spec, '|regex:'); // regex is last and may itself contain '|'
    foreach (explode('|', $at === false ? $spec : substr($spec, 0, max(0, $at - 1))) as $r) {
        if (($r = trim($r)) === '') continue;
        [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
        $known = in_array($name, ['required', 'email', 'int', 'numeric', 'url', 'bool', 'array', 'min', 'max', 'in'], true);
        if (!$known || (($name === 'min' || $name === 'max') && !is_numeric($arg)) || ($name === 'in' && $arg === null)) {
            throw new LogicException("validate(): bad rule '$r'");
        }
        $list[] = [$name, $arg];
    }
    if ($at !== false) $list[] = ['regex', substr($spec, $at + 6)];
    return $list;
}

/** Check one rule; may convert $v (int/numeric/bool). Returns null or [zh, en, n]. */
function ink_rule(string $rule, ?string $arg, mixed &$v, bool $number): ?array
{
    switch ($rule) {
        case 'required':
        case 'array':
            return null;
        case 'email':
            return filter_var($v, FILTER_VALIDATE_EMAIL) === false ? ['邮箱格式不正确', 'Invalid email address'] : null;
        case 'url':
            $ok = filter_var($v, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', (string) $v);
            return $ok ? null : ['网址格式不正确', 'Invalid URL'];
        case 'int': // an int, or a string of digits with an optional sign ('05' → 5); never a bool or float
            $s = is_int($v) ? (string) $v : (is_string($v) && preg_match('/^([+-]?)0*(\d+)$/D', $v, $m) ? ($m[1] === '-' ? '-' : '') . $m[2] : '');
            $i = filter_var($s, FILTER_VALIDATE_INT); // also rejects numbers outside PHP's int range
            if ($i === false) return ['必须是整数', 'Must be a whole number'];
            $v = $i;
            return null;
        case 'numeric': // finite numbers only ('1e999' is INF: rejected); never a bool
            if (is_bool($v) || !is_numeric($v) || !is_finite($v + 0)) return ['必须是数字', 'Must be a number'];
            $v = $v + 0;
            return null;
        case 'bool':
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($b === null) return ['必须是“是”或“否”', 'Must be yes or no'];
            $v = $b;
            return null;
        case 'min':
        case 'max':
            if (is_array($v)) [$size, $kind] = [count($v), 2];
            elseif ($number) [$size, $kind] = [is_numeric($v) ? $v + 0 : null, 1];
            else [$size, $kind] = [preg_match_all('/./su', (string) $v), 0];
            if ($size === null || ($rule === 'min' ? $size >= $arg + 0 : $size <= $arg + 0)) return null;
            $msg = $rule === 'min'
                ? [['至少 {n} 个字符', 'At least {n} characters'], ['不能小于 {n}', 'Must be at least {n}'], ['至少选择 {n} 项', 'Choose at least {n}']]
                : [['最多 {n} 个字符', 'At most {n} characters'], ['不能大于 {n}', 'Must be at most {n}'], ['最多选择 {n} 项', 'Choose at most {n}']];
            return [...$msg[$kind], $arg];
        case 'in': // compared and stored as a string (JSON 5 → '5')
            if (!in_array((string) $v, explode(',', (string) $arg), true)) return ['选项无效', 'Invalid choice'];
            $v = (string) $v;
            return null;
        case 'regex':
            $m = @preg_match((string) $arg, (string) $v);
            if ($m === false) throw new LogicException("validate(): bad regex $arg");
            return $m === 1 ? null : ['格式不正确', 'Invalid format'];
    }
    throw new LogicException("validate(): unknown rule '$rule'");
}
