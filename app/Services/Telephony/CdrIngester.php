<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallLog;
use App\Models\CallQueue;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns Asterisk's call detail records into Call History.
 *
 * Asterisk writes a row to `asterisk_cdr` for every CDR it finishes
 * (cdr_adaptive_odbc). One call can produce several (one per dial
 * attempt, a voicemail after an unanswered ring, the fallback message
 * after the AI fails), all sharing the call's linkedid. Each run takes
 * the calls that have new rows, rebuilds their call_logs row from all
 * of the call's rows, and marks the rows ingested. Rebuilding rather
 * than patching makes it idempotent and lets a late row correct the
 * call.
 *
 * Calls that never reached a client (the dispatcher's catch-all for
 * unrouted DIDs, operator test extensions) have no team and are only
 * marked ingested.
 */
class CdrIngester
{
    public const TABLE = 'asterisk_cdr';

    /** Raw rows are kept this long after ingestion, for troubleshooting. */
    public const KEEP_RAW_DAYS = 30;

    /**
     * @return array{calls: int, logged: int, skipped: int}
     */
    public function ingest(int $limit = 500): array
    {
        $linkedIds = DB::table(self::TABLE)
            ->whereNull('ingested_at')
            ->whereNotNull('linkedid')
            ->where('linkedid', '!=', '')
            ->orderBy('id')
            ->limit($limit * 4)
            ->pluck('linkedid')
            ->unique()
            ->take($limit)
            ->values();

        $logged = 0;
        $skipped = 0;
        foreach ($linkedIds as $linkedId) {
            DB::transaction(function () use ($linkedId, &$logged, &$skipped) {
                $rows = DB::table(self::TABLE)
                    ->where('linkedid', $linkedId)
                    ->orderBy('started_at')
                    ->orderBy('sequence')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $this->record((string) $linkedId, $rows) ? $logged++ : $skipped++;

                DB::table(self::TABLE)
                    ->whereIn('id', $rows->pluck('id'))
                    ->whereNull('ingested_at')
                    ->update(['ingested_at' => now()]);
            });
        }

        // Rows without a linkedid can't be grouped; nothing to build.
        DB::table(self::TABLE)
            ->whereNull('ingested_at')
            ->where(fn ($q) => $q->whereNull('linkedid')->orWhere('linkedid', ''))
            ->update(['ingested_at' => now()]);

        return ['calls' => $linkedIds->count(), 'logged' => $logged, 'skipped' => $skipped];
    }

    public function pruneRaw(): int
    {
        return DB::table(self::TABLE)
            ->whereNotNull('ingested_at')
            ->where('ingested_at', '<', now()->subDays(self::KEEP_RAW_DAYS))
            ->delete();
    }

    /**
     * Build (or rebuild) the call_logs row for one call.
     *
     * @param  Collection<int, object>  $rows
     */
    public function record(string $linkedId, Collection $rows): ?CallLog
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $teamId = $this->teamId($rows);
        if ($teamId === null) {
            return null;
        }

        $first = $rows->first();
        $did = $this->firstFilled($rows, 'orbital_did');

        $started = $this->minTime($rows, 'started_at');
        $answered = $this->minTime($rows, 'answered_at');
        $ended = $this->maxTime($rows, 'ended_at');

        $wasAnswered = $rows->contains(fn ($r) => self::disposition($r->disposition) === 'ANSWERED');
        $route = $this->route($rows, $teamId);
        $session = CallSessionState::query()->where('linked_id', $linkedId)->orderBy('id')->first();
        $status = $this->status($rows, $wasAnswered, $route['outcome']);

        $attributes = [
            'team_id' => $teamId,
            'unique_id' => $first->uniqueid ?: null,
            'channel' => $first->channel ?: null,
            'sip_call_id' => $this->firstFilled($rows, 'orbital_sip_call_id'),
            'caller_id_name' => $this->callerName((string) $first->clid),
            'caller_id_num' => $first->src ?: null,
            'from_number' => $first->src ?: null,
            'to_number' => $did ?? ($first->dst ?: null),
            'direction' => $did !== null ? 'inbound' : 'internal',
            'status' => $status,
            'disposition' => $wasAnswered ? 'ANSWERED' : $this->lastDisposition($rows),
            'started_at' => $started,
            'answered_at' => $wasAnswered ? $answered : null,
            'ended_at' => $ended,
            'duration_seconds' => $started && $ended ? max(0, (int) $started->diffInSeconds($ended)) : 0,
            'billable_seconds' => $wasAnswered && $answered && $ended ? max(0, (int) $answered->diffInSeconds($ended)) : 0,
            'extension_id' => $route['extension_id'],
            'call_queue_id' => $route['call_queue_id'],
            'agent_persona_id' => $session?->agent_persona_id ?? $route['agent_persona_id'],
            'transcript' => $session?->transcript,
            'metadata' => [
                'source' => 'asterisk_cdr',
                'outcome' => $route['outcome'],
                'routing_rule_id' => ($ruleId = $this->firstFilled($rows, 'orbital_rule_id')) !== null ? (int) $ruleId : null,
                'ai_session' => $session?->session_key,
                'legs' => $rows->map(fn ($r) => [
                    'channel' => $r->channel,
                    'dstchannel' => $r->dstchannel ?: null,
                    'context' => $r->dcontext,
                    'dst' => $r->dst,
                    'app' => $r->lastapp,
                    'data' => $r->lastdata ?: null,
                    'disposition' => self::disposition($r->disposition),
                    'billsec' => (int) $r->billsec,
                ])->values()->all(),
            ],
        ];

        $log = CallLog::withoutGlobalScopes()->firstOrNew(['linked_id' => $linkedId]);
        $log->fill($attributes)->save();

        return $log;
    }

    /**
     * The client the call belongs to: the dispatcher's tag for inbound
     * DIDs, else the tenant context the call ran in (calls between a
     * client's own extensions).
     */
    protected function teamId(Collection $rows): ?int
    {
        $tagged = $this->firstFilled($rows, 'orbital_team_id');
        $candidate = $tagged !== null && ctype_digit($tagged) ? (int) $tagged : null;

        if ($candidate === null) {
            foreach ($rows as $row) {
                if (preg_match('/^tenant_(\d+)$/', (string) $row->dcontext, $m)) {
                    $candidate = (int) $m[1];
                    break;
                }
            }
        }

        return $candidate !== null && Team::query()->whereKey($candidate)->exists() ? $candidate : null;
    }

    /**
     * Where the call went: an AI agent, a person's extension, a queue,
     * voicemail, or the "AI unavailable" message.
     *
     * @return array{outcome: string, extension_id: ?int, call_queue_id: ?int, agent_persona_id: ?int}
     */
    protected function route(Collection $rows, int $teamId): array
    {
        $result = ['outcome' => 'unanswered', 'extension_id' => null, 'call_queue_id' => null, 'agent_persona_id' => null];

        foreach ($rows as $row) {
            $app = strtolower((string) $row->lastapp);
            $data = (string) $row->lastdata;

            if (preg_match('/^queue-(\d+)$/', (string) $row->dst, $m) && $result['call_queue_id'] === null) {
                $result['call_queue_id'] = CallQueue::withoutGlobalScopes()
                    ->where('team_id', $teamId)->whereKey((int) $m[1])->value('id');
                $result['outcome'] = 'queue';
            }

            if ($app === 'dial' && preg_match('#^PJSIP/([^@,/&]+)@livekit#i', $data, $m)) {
                $target = AiCallTarget::resolve($m[1]);
                if ($target && $target->teamId === $teamId) {
                    $result['agent_persona_id'] ??= $target->persona->id;
                    $result['extension_id'] ??= $target->extension?->id;
                }
                $result['outcome'] = 'ai';
            } elseif ($app === 'dial' && preg_match('#^PJSIP/([^@,/&]+)(?:,|$)#i', $data, $m)) {
                $result['extension_id'] ??= Extension::withoutGlobalScopes()
                    ->where('team_id', $teamId)->where('number', $m[1])->value('id');
                $result['outcome'] = 'extension';
            } elseif ($app === 'voicemail') {
                $result['outcome'] = 'voicemail';
            }

            if ($row->dcontext === 'orbital-ai-unavailable') {
                $result['outcome'] = 'ai_unavailable';
            }
        }

        return $result;
    }

    /**
     * completed / missed / busy / failed, or ai_unavailable when the
     * caller got the fallback message instead of the AI.
     */
    protected function status(Collection $rows, bool $answered, string $outcome): string
    {
        if ($outcome === 'ai_unavailable') {
            return 'ai_unavailable';
        }
        if ($answered) {
            return 'completed';
        }

        return match ($this->lastDisposition($rows)) {
            'BUSY' => 'busy',
            'FAILED', 'CONGESTION' => 'failed',
            default => 'missed',
        };
    }

    /**
     * cdr_adaptive_odbc writes the disposition as Asterisk's number (it
     * formats every non-date field "raw"); the CSV backend and older
     * tables use the name. Either way, the name.
     */
    public static function disposition(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return match ($value) {
            '', '0' => 'NO ANSWER',
            '1' => 'NULL',
            '2' => 'FAILED',
            '4' => 'BUSY',
            '8' => 'ANSWERED',
            '16' => 'CONGESTION',
            default => $value,
        };
    }

    protected function lastDisposition(Collection $rows): string
    {
        foreach ($rows->reverse() as $row) {
            $disposition = self::disposition($row->disposition);
            if ($disposition !== 'NULL') {
                return $disposition;
            }
        }

        return 'NO ANSWER';
    }

    protected function callerName(string $clid): ?string
    {
        if (preg_match('/^"([^"]*)"/', $clid, $m)) {
            $name = trim($m[1]);

            return $name !== '' ? $name : null;
        }

        return null;
    }

    protected function firstFilled(Collection $rows, string $column): ?string
    {
        foreach ($rows as $row) {
            $value = trim((string) ($row->{$column} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function minTime(Collection $rows, string $column): ?CarbonImmutable
    {
        return $this->times($rows, $column)->sort()->first();
    }

    protected function maxTime(Collection $rows, string $column): ?CarbonImmutable
    {
        return $this->times($rows, $column)->sort()->last();
    }

    /**
     * Asterisk writes times in UTC (usegmtime=yes). An unanswered leg's
     * answer time comes through as the epoch (1970-01-01): ignored.
     *
     * @return Collection<int, CarbonImmutable>
     */
    protected function times(Collection $rows, string $column): Collection
    {
        return $rows->map(function ($row) use ($column) {
            $value = $row->{$column} ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            try {
                $time = CarbonImmutable::parse((string) $value, 'UTC');
            } catch (\Throwable) {
                Log::debug('cdr: unparseable time', [$column => $value]);

                return null;
            }

            return $time->year < 2000 ? null : $time;
        })->filter()->values();
    }
}
