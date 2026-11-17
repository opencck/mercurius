<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Repository\ExperienceRepository;
use UnexpectedValueException;

/**
 * ref: references/hermes-plugin-holographic/experience.py@4a794f8:L31-L61 — a journal of outcomes kept apart from the
 *      facts; ours records delegated parts and is written by the code, not reported by the agent
 */
final class PostgresExperienceRepository implements ExperienceRepository {
    private const COLUMNS = 'e.id, e.conversation_id, e.principal_id, e.run_id, e.goal, e.role, e.profile::text AS profile, e.model, '
        . 'e.first_model, e.status, e.verdict, e.reason, e.turns, e.tokens, e.duration_ms, extract(epoch FROM e.created_at)::bigint AS at';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(Experience $experience): int {
        $row = $this->db->execute(
            'INSERT INTO experiences (conversation_id, principal_id, run_id, goal, role, profile, model, first_model, status, verdict, reason, turns, tokens, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $experience->conversationId,
                $experience->principalId,
                $experience->runId,
                $experience->goal,
                $experience->role,
                $experience->profile === [] ? null : json_encode($experience->profile, JSON_THROW_ON_ERROR),
                $experience->model,
                $experience->firstModel,
                $experience->status,
                $experience->verdict,
                $experience->reason,
                $experience->turns,
                $experience->tokens,
                $experience->durationMs,
            ],
        )->fetchRow();
        $id = $row['id'] ?? null;
        if (!is_int($id)) {
            throw new UnexpectedValueException('Experience was not recorded');
        }
        return $id;
    }

    public function search(array $words, HistoryScope $scope, int $limit, ?string $model = null): array {
        [$where, $params] = PostgresEpisodeRepository::scope($scope);
        if ($model !== null) {
            $where .= ' AND (e.model = ? OR e.first_model = ?)';
            $params = [...$params, $model, $model];
        }
        $query = PostgresEpisodeRepository::tsquery($words);
        if ($query === '') {
            $sql = 'SELECT ' . self::COLUMNS . ' FROM experiences e JOIN conversations c ON c.id = e.conversation_id WHERE true'
                . $where . ' ORDER BY e.id DESC LIMIT ?';
            $args = [...$params, $limit];
        } else {
            $sql = 'SELECT ' . self::COLUMNS . " FROM experiences e JOIN conversations c ON c.id = e.conversation_id,
                    to_tsquery('russian'::regconfig, ?) q WHERE e.search @@ q" . $where . ' ORDER BY ts_rank_cd(e.search, q) DESC, e.id DESC LIMIT ?';
            $args = [$query, ...$params, $limit];
        }
        $found = [];
        foreach ($this->db->execute($sql, $args) as $row) {
            $found[] = self::experience($row);
        }
        return $found;
    }

    public function redact(string $text, string $replacement): int {
        if ($text === '') {
            return 0;
        }
        $changed = 0;
        foreach ($this->db->execute('UPDATE experiences SET goal = replace(goal, ?, ?) WHERE strpos(goal, ?) > 0 RETURNING id', [$text, $replacement, $text]) as $row) {
            $changed++;
        }
        return $changed;
    }

    /** @param array<string, mixed> $row */
    private static function experience(array $row): Experience {
        $profile = [];
        if (isset($row['profile']) && is_string($row['profile'])) {
            /** @var mixed $decoded */
            $decoded = json_decode($row['profile'], true, 4, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $axis) {
                    if (is_string($axis) && isset($decoded[$axis]) && is_int($decoded[$axis])) {
                        $profile[$axis] = $decoded[$axis];
                    }
                }
            }
        }
        return new Experience(
            self::int($row, 'conversation_id'),
            self::optInt($row, 'principal_id'),
            self::optInt($row, 'run_id'),
            self::optStr($row, 'goal') ?? '',
            self::optStr($row, 'role') ?? '',
            $profile,
            self::optStr($row, 'model') ?? '',
            self::optStr($row, 'first_model'),
            self::optStr($row, 'status') ?? '',
            self::optStr($row, 'verdict'),
            self::optStr($row, 'reason'),
            self::int($row, 'turns'),
            self::int($row, 'tokens'),
            self::int($row, 'duration_ms'),
            self::int($row, 'id'),
            new DateTimeImmutable('@' . self::int($row, 'at')),
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed experience row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function optInt(array $row, string $key): ?int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function optStr(array $row, string $key): ?string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
    }
}
