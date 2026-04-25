<?php

declare(strict_types=1);

namespace App\Services\Flows;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Walk an expression AST (produced by {@see ExpressionParser}) against
 * a context of slots + call metadata and return the resolved scalar.
 *
 * Used by:
 *   - {@see TemplateEvaluator} for `{{ }}` interpolation in prompt /
 *     summary / email-body template fields.
 *   - {@see ConditionEvaluator}'s replacement path for branch_if and
 *     rule conditions (Phase 4 will wire this up).
 *   - Dry-run preview in the editor — "what does this evaluate to
 *     right now?".
 *
 * # Syntax (JS-like subset)
 *
 *   "hello"              → string literal
 *   42, 3.14             → number literal
 *   true / false / null  → constants
 *   caller_name          → slot value (or context path if dotted)
 *   call.did             → context path `call.did`
 *   upper(caller_name)   → function call (case-insensitive name)
 *   a + b                → string concat (if either is a non-numeric
 *                          string) or numeric addition
 *   a - b / a * b / a / b / a % b  → numeric
 *   ==, !=, <, <=, >, >= → comparison
 *   &&, ||, !            → boolean logic
 *   cond ? a : b         → ternary
 *   (…)                  → grouping
 *
 * # Context shape
 *
 *   [
 *     'slots'   => ['caller_name' => 'Pat', ...],
 *     'context' => [
 *         'now'  => DateTimeImmutable,
 *         'call' => ['did' => '+15005551212', ...],
 *         'agent'=> ['name' => 'Ava', ...],
 *         'caller'=>['id' => '123', 'matched' => true, ...],
 *     ],
 *   ]
 *
 * Identifier lookup rule: a bare `foo` means `slots.foo` if that slot
 * exists, else `context.foo`. A dotted `a.b.c` first tries the slot
 * path, then the context path. This keeps the common-case short
 * (`caller_name`) while still allowing full context access
 * (`call.did`, `caller.matched`).
 */
class ExpressionEvaluator
{
    public function __construct(
        private readonly ExpressionParser $parser = new ExpressionParser,
    ) {}

    /**
     * Parse + evaluate a source string. The AST is not cached — if you
     * need to evaluate the same expression repeatedly, call
     * {@see evaluateAst} against a pre-parsed AST.
     *
     * @param  array<string, mixed>  $context
     */
    public function evaluate(string $source, array $context = []): mixed
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        $ast = $this->parser->parse($source);

        return $this->evaluateAst($ast, $context);
    }

    /** @param  array<string, mixed>  $context */
    public function evaluateAsString(string $source, array $context = []): string
    {
        return $this->coerceToString($this->evaluate($source, $context));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     */
    public function evaluateAst(array $node, array $context): mixed
    {
        return match ($node['type']) {
            'literal' => $node['value'],
            'ident' => $this->resolveIdent($node['name'], $context),
            'member' => $this->resolveMember($node, $context),
            'call' => $this->callFunction($node['name'], array_map(fn ($a) => $this->evaluateAst($a, $context), $node['args']), $context),
            'unary' => $this->applyUnary($node['op'], $this->evaluateAst($node['arg'], $context)),
            'binary' => $this->applyBinary($node['op'], $node['left'], $node['right'], $context),
            'ternary' => $this->coerceToBool($this->evaluateAst($node['test'], $context))
                ? $this->evaluateAst($node['then'], $context)
                : $this->evaluateAst($node['else'], $context),
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    protected function resolveIdent(string $name, array $context): mixed
    {
        $slots = $context['slots'] ?? [];
        if (is_array($slots) && array_key_exists($name, $slots)) {
            return $slots[$name];
        }
        $ctx = $context['context'] ?? [];
        if (is_array($ctx) && array_key_exists($name, $ctx)) {
            return $ctx[$name];
        }

        return null;
    }

    /**
     * Flatten a member-access chain into a dot path and try slot first,
     * then context. So `call.did` reads `context.call.did`;
     * `caller.state` reads `slots.caller.state` (if the slot exists)
     * otherwise `context.caller.state`.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     */
    protected function resolveMember(array $node, array $context): mixed
    {
        $segments = $this->flattenMember($node);
        if ($segments === null) {
            // Dynamic object; evaluate as regular expression.
            $obj = $this->evaluateAst($node['object'], $context);

            return is_array($obj) ? ($obj[$node['prop']] ?? null) : null;
        }
        $path = implode('.', $segments);
        $slotHit = $this->lookup('slots.'.$path, $context);
        if ($slotHit !== null) {
            return $slotHit;
        }

        return $this->lookup('context.'.$path, $context);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<int, string>|null
     */
    protected function flattenMember(array $node): ?array
    {
        $segments = [$node['prop']];
        $cursor = $node['object'];
        while (is_array($cursor) && ($cursor['type'] ?? null) === 'member') {
            array_unshift($segments, $cursor['prop']);
            $cursor = $cursor['object'];
        }
        if (is_array($cursor) && ($cursor['type'] ?? null) === 'ident') {
            array_unshift($segments, $cursor['name']);

            return $segments;
        }

        return null;
    }

    protected function applyUnary(string $op, mixed $v): mixed
    {
        return match ($op) {
            '!' => ! $this->coerceToBool($v),
            '-' => -1 * $this->coerceToNumber($v),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $context
     */
    protected function applyBinary(string $op, array $left, array $right, array $context): mixed
    {
        // Short-circuit && and ||.
        if ($op === '&&') {
            $a = $this->evaluateAst($left, $context);
            if (! $this->coerceToBool($a)) {
                return false;
            }

            return $this->coerceToBool($this->evaluateAst($right, $context));
        }
        if ($op === '||') {
            $a = $this->evaluateAst($left, $context);
            if ($this->coerceToBool($a)) {
                return true;
            }

            return $this->coerceToBool($this->evaluateAst($right, $context));
        }

        $a = $this->evaluateAst($left, $context);
        $b = $this->evaluateAst($right, $context);

        return match ($op) {
            '+' => $this->opPlus($a, $b),
            '-' => $this->coerceToNumber($a) - $this->coerceToNumber($b),
            '*' => $this->coerceToNumber($a) * $this->coerceToNumber($b),
            '/' => $this->opDivide($a, $b),
            '%' => $this->opMod($a, $b),
            '==' => $this->opEquals($a, $b),
            '!=' => ! $this->opEquals($a, $b),
            '<' => $this->coerceToNumber($a) < $this->coerceToNumber($b),
            '<=' => $this->coerceToNumber($a) <= $this->coerceToNumber($b),
            '>' => $this->coerceToNumber($a) > $this->coerceToNumber($b),
            '>=' => $this->coerceToNumber($a) >= $this->coerceToNumber($b),
            default => null,
        };
    }

    protected function opPlus(mixed $a, mixed $b): mixed
    {
        // If either side is a non-numeric string, concatenate. Matches
        // JS semantics and what authors expect from `"Hello " + name`.
        if ((is_string($a) && ! is_numeric($a)) || (is_string($b) && ! is_numeric($b))) {
            return $this->coerceToString($a).$this->coerceToString($b);
        }

        return $this->coerceToNumber($a) + $this->coerceToNumber($b);
    }

    protected function opDivide(mixed $a, mixed $b): float|int
    {
        $x = $this->coerceToNumber($a);
        $y = $this->coerceToNumber($b);
        if ($y == 0) {
            return 0;
        }

        return $x / $y;
    }

    protected function opMod(mixed $a, mixed $b): int|float
    {
        $x = $this->coerceToNumber($a);
        $y = $this->coerceToNumber($b);
        if ($y == 0) {
            return 0;
        }
        if (is_int($x) && is_int($y)) {
            return $x % $y;
        }

        return fmod((float) $x, (float) $y);
    }

    protected function opEquals(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return $this->coerceToNumber($a) == $this->coerceToNumber($b);
        }

        return $this->coerceToString($a) === $this->coerceToString($b);
    }

    public function coerceToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }

    public function coerceToBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === '' || $s === 'false' || $s === '0' || $s === 'no') {
                return false;
            }

            return true;
        }
        if (is_numeric($v)) {
            return ((float) $v) !== 0.0;
        }

        return (bool) $v;
    }

    public function coerceToNumber(mixed $v): float|int
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_string($v)) {
            $trimmed = trim($v);
            if ($trimmed === '') {
                return 0;
            }
            if (ctype_digit(ltrim($trimmed, '-'))) {
                return (int) $trimmed;
            }

            return is_numeric($trimmed) ? (float) $trimmed : 0;
        }

        return 0;
    }

    /**
     * Function call dispatch. Names are matched case-insensitively so
     * authors can use either snake_case (JS-like: `format_phone`) or
     * camel (VB-like: `FormatPhone`); both map to the same impl.
     *
     * Unknown functions raise — better to fail loudly than silently
     * return garbage.
     *
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    public function callFunction(string $name, array $args, array $context = []): mixed
    {
        $fn = strtolower(str_replace('_', '', $name));

        return match ($fn) {
            // ── Date / time ──
            'now' => $this->fnNow($context),
            'date' => $this->fnDate($context),
            'time' => $this->fnNow($context),
            'dateadd' => $this->fnDateAdd($args[0] ?? 'day', $args[1] ?? 0, $args[2] ?? null, $context),
            'datediff' => $this->fnDateDiff($args[0] ?? 'day', $args[1] ?? null, $args[2] ?? null),
            'datepart' => $this->fnDatePart($args[0] ?? 'day', $args[1] ?? null, $context),
            'formatdate' => $this->fnFormatDate($args[0] ?? null, $args[1] ?? 'ShortDate'),
            'weekday' => $this->fnWeekday($args[0] ?? null, $context),
            'month' => $this->fnMonth($args[0] ?? null, $context),
            'age', 'getage' => $this->fnGetAge($args[0] ?? null, $context),
            'daterange' => $this->fnDateRange($args[0] ?? null, $args[1] ?? null, $args[2] ?? null, $context),
            'timerange' => $this->fnTimeRange($args[0] ?? null, $args[1] ?? null, $args[2] ?? null, $context),

            // ── String ──
            'upper', 'ucase' => strtoupper($this->coerceToString($args[0] ?? '')),
            'lower', 'lcase' => strtolower($this->coerceToString($args[0] ?? '')),
            'left' => substr($this->coerceToString($args[0] ?? ''), 0, max(0, (int) $this->coerceToNumber($args[1] ?? 0))),
            'right' => $this->fnRight($args[0] ?? '', $args[1] ?? 0),
            'mid', 'substr' => $this->fnMid($args[0] ?? '', $args[1] ?? 1, $args[2] ?? null),
            'indexof', 'instr' => $this->fnInStr($args[0] ?? '', $args[1] ?? ''),
            'len', 'length' => mb_strlen($this->coerceToString($args[0] ?? '')),
            'replace' => str_replace(
                $this->coerceToString($args[1] ?? ''),
                $this->coerceToString($args[2] ?? ''),
                $this->coerceToString($args[0] ?? ''),
            ),
            'trim' => trim($this->coerceToString($args[0] ?? '')),
            'ltrim' => ltrim($this->coerceToString($args[0] ?? '')),
            'rtrim' => rtrim($this->coerceToString($args[0] ?? '')),
            'formatphone' => $this->fnFormatPhone($args[0] ?? ''),
            'normalize' => preg_replace('/\s+/', ' ', trim($this->coerceToString($args[0] ?? ''))) ?? '',
            'split' => explode(
                $this->coerceToString($args[1] ?? ','),
                $this->coerceToString($args[0] ?? ''),
            ),
            'concat' => implode('', array_map(fn ($v) => $this->coerceToString($v), $args)),

            // ── Math ──
            'abs' => abs((float) $this->coerceToNumber($args[0] ?? 0)),
            'round' => round((float) $this->coerceToNumber($args[0] ?? 0), (int) $this->coerceToNumber($args[1] ?? 0)),
            'floor' => floor((float) $this->coerceToNumber($args[0] ?? 0)),
            'ceil' => ceil((float) $this->coerceToNumber($args[0] ?? 0)),
            'sign', 'sgn' => $this->fnSgn($args[0] ?? 0),
            'sqrt', 'sqr' => sqrt((float) $this->coerceToNumber($args[0] ?? 0)),
            'min' => count($args) > 0 ? min(array_map(fn ($v) => $this->coerceToNumber($v), $args)) : 0,
            'max' => count($args) > 0 ? max(array_map(fn ($v) => $this->coerceToNumber($v), $args)) : 0,

            // ── Format ──
            'formatnumber' => $this->fnFormatNumber($args[0] ?? 0, $args[1] ?? 'Fixed', $args[2] ?? 2),
            'formatage' => $this->fnFormatAge($args[0] ?? null, $context),
            'currency' => '$'.number_format((float) $this->coerceToNumber($args[0] ?? 0), 2, '.', ','),

            // ── Logic / cast ──
            'iif', 'if' => $this->coerceToBool($args[0] ?? false) ? ($args[1] ?? null) : ($args[2] ?? null),
            'isempty', 'empty' => $this->fnIsEmpty($args[0] ?? null),
            'tostring', 'cstr' => $this->coerceToString($args[0] ?? ''),
            'tonumber', 'cdbl' => (float) $this->coerceToNumber($args[0] ?? 0),
            'tobool', 'cbool' => $this->coerceToBool($args[0] ?? false),
            'todate', 'cdate' => $this->toDateTime($args[0] ?? null, $context),

            default => throw new RuntimeException("Unknown expression function: {$name}"),
        };
    }

    // ── Date helpers ──────────────────────────────────────────────

    protected function fnNow(array $context): DateTimeImmutable
    {
        $candidate = $this->lookup('context.now', $context);
        if ($candidate instanceof DateTimeImmutable) {
            return $candidate;
        }
        if (is_string($candidate) && $candidate !== '') {
            try {
                return new DateTimeImmutable($candidate);
            } catch (\Exception) {
                // fall through
            }
        }

        return new DateTimeImmutable('now', new DateTimeZone(config('app.timezone') ?? 'UTC'));
    }

    protected function fnDate(array $context): DateTimeImmutable
    {
        return $this->fnNow($context)->setTime(0, 0, 0);
    }

    protected function toDateTime(mixed $v, array $context): ?DateTimeImmutable
    {
        if ($v instanceof DateTimeImmutable) {
            return $v;
        }
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            try {
                return new DateTimeImmutable($v);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    protected function fnDateAdd(mixed $interval, mixed $amount, mixed $date, array $context): ?DateTimeImmutable
    {
        $dt = $this->toDateTime($date, $context) ?? $this->fnNow($context);
        $n = (int) $this->coerceToNumber($amount);
        $unit = strtolower((string) $this->coerceToString($interval));
        $spec = match ($unit) {
            'year', 'years' => 'P'.abs($n).'Y',
            'month', 'months' => 'P'.abs($n).'M',
            'week', 'weeks' => 'P'.(abs($n) * 7).'D',
            'day', 'days' => 'P'.abs($n).'D',
            'hour', 'hours' => 'PT'.abs($n).'H',
            'minute', 'minutes' => 'PT'.abs($n).'M',
            'second', 'seconds' => 'PT'.abs($n).'S',
            default => 'P'.abs($n).'D',
        };
        try {
            $interval = new DateInterval($spec);
        } catch (\Exception) {
            return $dt;
        }

        return $n >= 0 ? $dt->add($interval) : $dt->sub($interval);
    }

    protected function fnDateDiff(mixed $interval, mixed $a, mixed $b): int
    {
        $da = $this->toDateTime($a, []);
        $db = $this->toDateTime($b, []);
        if (! $da || ! $db) {
            return 0;
        }
        $seconds = $db->getTimestamp() - $da->getTimestamp();
        $unit = strtolower((string) $this->coerceToString($interval));

        return match ($unit) {
            'second', 'seconds' => $seconds,
            'minute', 'minutes' => intdiv($seconds, 60),
            'hour', 'hours' => intdiv($seconds, 3600),
            'day', 'days' => intdiv($seconds, 86400),
            'week', 'weeks' => intdiv($seconds, 604800),
            'month', 'months' => $this->approxMonthsBetween($da, $db),
            'year', 'years' => (int) $da->diff($db)->y * ($seconds < 0 ? -1 : 1),
            default => intdiv($seconds, 86400),
        };
    }

    protected function approxMonthsBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $diff = $a->diff($b);
        $months = $diff->y * 12 + $diff->m;

        return $diff->invert ? -$months : $months;
    }

    protected function fnDatePart(mixed $part, mixed $date, array $context): int|string
    {
        $dt = $this->toDateTime($date, $context) ?? $this->fnNow($context);
        $unit = strtolower((string) $this->coerceToString($part));

        return match ($unit) {
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day' => (int) $dt->format('j'),
            'hour' => (int) $dt->format('G'),
            'minute' => (int) $dt->format('i'),
            'second' => (int) $dt->format('s'),
            'weekday' => ((int) $dt->format('w')) + 1,
            'dayofyear' => (int) $dt->format('z') + 1,
            'quarter' => (int) ceil(((int) $dt->format('n')) / 3),
            default => $dt->format($unit),
        };
    }

    protected function fnFormatDate(mixed $date, mixed $format): string
    {
        $dt = $this->toDateTime($date, []);
        if (! $dt) {
            return '';
        }
        $fmt = (string) $this->coerceToString($format);

        return $dt->format(match ($fmt) {
            'LongDate' => 'l, F j, Y',
            'ShortDate' => 'n/j/Y',
            'LongTime' => 'g:i:s A',
            'ShortTime' => 'g:i A',
            'LongDateLongTime' => 'l, F j, Y g:i:s A',
            'LongDateShortTime' => 'l, F j, Y g:i A',
            'ShortDateShortTime' => 'n/j/Y g:i A',
            'ISO', 'Iso8601' => \DateTimeInterface::ATOM,
            default => $fmt,
        });
    }

    protected function fnWeekday(mixed $date, array $context): int
    {
        $dt = $this->toDateTime($date, $context) ?? $this->fnNow($context);

        return ((int) $dt->format('w')) + 1;
    }

    protected function fnMonth(mixed $date, array $context): int
    {
        $dt = $this->toDateTime($date, $context) ?? $this->fnNow($context);

        return (int) $dt->format('n');
    }

    protected function fnGetAge(mixed $birth, array $context): int
    {
        $dob = $this->toDateTime($birth, $context);
        if (! $dob) {
            return 0;
        }

        return (int) $dob->diff($this->fnNow($context))->y;
    }

    protected function fnDateRange(mixed $date, mixed $start, mixed $end, array $context): bool
    {
        $d = $this->toDateTime($date, $context) ?? $this->fnNow($context);
        $s = $this->toDateTime($start, $context);
        $e = $this->toDateTime($end, $context);
        if (! $s || ! $e) {
            return false;
        }

        return $d >= $s && $d <= $e;
    }

    protected function fnTimeRange(mixed $time, mixed $start, mixed $end, array $context): bool
    {
        $minute = fn (mixed $v) => $this->toMinuteOfDay($v, $context);
        $t = $minute($time);
        $s = $minute($start);
        $e = $minute($end);
        if ($t === null || $s === null || $e === null) {
            return false;
        }
        if ($s <= $e) {
            return $t >= $s && $t <= $e;
        }

        return $t >= $s || $t <= $e;
    }

    protected function toMinuteOfDay(mixed $v, array $context): ?int
    {
        if ($v instanceof DateTimeImmutable) {
            return ((int) $v->format('G')) * 60 + (int) $v->format('i');
        }
        if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        return null;
    }

    // ── String helpers ────────────────────────────────────────────

    protected function fnRight(mixed $s, mixed $n): string
    {
        $str = $this->coerceToString($s);
        $len = max(0, (int) $this->coerceToNumber($n));
        if ($len === 0) {
            return '';
        }

        return substr($str, -$len);
    }

    protected function fnMid(mixed $s, mixed $start, mixed $length): string
    {
        $str = $this->coerceToString($s);
        // 1-indexed to match VB/spreadsheet conventions — which is
        // what authors coming from NxtScript / Excel expect.
        $from = max(0, ((int) $this->coerceToNumber($start)) - 1);
        if ($length === null) {
            return substr($str, $from);
        }
        $len = max(0, (int) $this->coerceToNumber($length));

        return substr($str, $from, $len);
    }

    protected function fnInStr(mixed $haystack, mixed $needle): int
    {
        $h = $this->coerceToString($haystack);
        $n = $this->coerceToString($needle);
        if ($n === '') {
            return 0;
        }
        $pos = strpos($h, $n);

        return $pos === false ? 0 : $pos + 1;
    }

    protected function fnFormatPhone(mixed $raw): string
    {
        $digits = preg_replace('/\D+/', '', $this->coerceToString($raw));
        if (strlen($digits) === 10) {
            return '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6);
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+1 ('.substr($digits, 1, 3).') '.substr($digits, 4, 3).'-'.substr($digits, 7);
        }

        return $this->coerceToString($raw);
    }

    protected function fnSgn(mixed $v): int
    {
        $n = (float) $this->coerceToNumber($v);

        return $n === 0.0 ? 0 : ($n > 0 ? 1 : -1);
    }

    protected function fnFormatNumber(mixed $v, mixed $style, mixed $decimals): string
    {
        $n = (float) $this->coerceToNumber($v);
        $d = max(0, (int) $this->coerceToNumber($decimals));
        $kind = strtolower((string) $this->coerceToString($style));

        return match ($kind) {
            'currency', 'c' => '$'.number_format($n, 2, '.', ','),
            'percent', 'p' => number_format($n * 100, $d, '.', ',').'%',
            'fixed', 'f' => number_format($n, $d, '.', ''),
            'comma', ',' => number_format($n, $d, '.', ','),
            default => number_format($n, $d, '.', ''),
        };
    }

    protected function fnFormatAge(mixed $birth, array $context): string
    {
        $years = $this->fnGetAge($birth, $context);
        if ($years <= 0) {
            return '';
        }

        return $years.' '.($years === 1 ? 'year' : 'years').' old';
    }

    protected function fnIsEmpty(mixed $v): bool
    {
        if ($v === null) {
            return true;
        }
        if (is_string($v)) {
            return trim($v) === '';
        }
        if (is_array($v)) {
            return $v === [];
        }

        return false;
    }

    /**
     * Dot-path lookup against a nested context. Returns null when any
     * segment is missing.
     *
     * @param  array<string, mixed>  $context
     */
    public function lookup(string $path, array $context): mixed
    {
        if ($path === '') {
            return null;
        }
        $cursor = $context;
        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor)) {
                if (! array_key_exists($segment, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$segment];

                continue;
            }
            if (is_object($cursor) && isset($cursor->{$segment})) {
                $cursor = $cursor->{$segment};

                continue;
            }

            return null;
        }

        return $cursor;
    }
}
