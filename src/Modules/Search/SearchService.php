<?php

declare(strict_types=1);

namespace App\Modules\Search;

use App\Core\Database;
use App\Core\ForbiddenException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/**
 * Volltext- und Filtersuche. JEDE Abfrage (Treffer, Zähler, Autovervollständigung, Export) wird durch
 * AccessControl::visibleContractsSql() eingeschränkt – es gibt keinen Pfad ohne diese Bedingung.
 * Durchsuchte Inhalte (Teilstring-Suche; für sehr grosse Bestände >50'000 Verträge wäre ein Suchindex nötig): Metadaten, Vertragspartner und bei internen Benutzern der aktuelle Text,
 * bei Externen ausschliesslich die freigegebene (gesperrte) Version.
 */
final class SearchService
{
    public const PAGE_SIZE = 25;
    private const SORTS = ['updated' => 'c.updated_at DESC, c.id DESC', 'title' => 'c.title ASC, c.id ASC', 'end' => 'c.end_date IS NULL, c.end_date ASC, c.id ASC', 'number' => 'c.contract_number ASC'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $f Filter: q, status, contract_type_id, department_id, owner_user_id, tag, counterparty_id,
     *                               end_from, end_to, value_min, value_max, sort
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function search(Principal $p, array $f, int $page = 1): array
    {
        [$where, $params, $join] = $this->build($p, $f);
        $total = (int) $this->db->value("SELECT COUNT(DISTINCT c.id) FROM contracts c {$join} WHERE {$where}", $params);
        $page = max(1, $page);
        $sort = self::SORTS[(string) ($f['sort'] ?? 'updated')] ?? self::SORTS['updated'];
        $off = ($page - 1) * self::PAGE_SIZE;
        $rows = $this->db->all(
            "SELECT DISTINCT c.id, c.contract_number, c.title, c.status, c.end_date, c.value_amount, c.value_currency, c.department_id, c.updated_at, ct.name AS type_name
               FROM contracts c JOIN contract_types ct ON ct.id = c.contract_type_id {$join}
              WHERE {$where} ORDER BY {$sort} LIMIT " . self::PAGE_SIZE . " OFFSET {$off}",
            $params,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE))];
    }

    /** Autovervollständigung: nur Titel/Nummern sichtbarer Verträge. @return list<array{id:int,label:string}> */
    public function suggest(Principal $p, string $prefix): array
    {
        $prefix = trim($prefix);
        if (mb_strlen($prefix) < 2 || mb_strlen($prefix) > 60) {
            return [];
        }
        [$vis, $vp] = $this->ac->visibleContractsSql($p, 'c');
        $like = self::like($prefix) . '%';
        $rows = $this->db->all(
            "SELECT c.id, c.contract_number, c.title FROM contracts c
              WHERE ({$vis}) AND (c.title LIKE ? OR c.contract_number LIKE ?) ORDER BY c.title LIMIT 8",
            [...$vp, '%' . self::like($prefix) . '%', $like],
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['contract_number'] . ' – ' . $r['title']], $rows);
    }

    /** CSV-Export der Treffer (nur mit Exportrecht, nur sichtbare Verträge). */
    public function exportCsv(Principal $p, array $f): string
    {
        if (!$p->isExternal() && !$p->can('contract.export')) {
            $this->audit->log('access.denied', 'denied', $p->tenantId, $p->userId, 'export', null, ['reason' => 'missing_permission:contract.export']);
            throw new ForbiddenException();
        }
        if ($p->isExternal()) {
            throw new ForbiddenException();
        }
        [$where, $params, $join] = $this->build($p, $f);
        $rows = $this->db->all(
            "SELECT DISTINCT c.contract_number, c.title, ct.name AS type_name, c.status, c.start_date, c.end_date, c.notice_period_days, c.value_amount, c.value_currency
               FROM contracts c JOIN contract_types ct ON ct.id = c.contract_type_id {$join} WHERE {$where} ORDER BY c.contract_number LIMIT 10000",
            $params,
        );
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Vertragsnummer', 'Titel', 'Typ', 'Status', 'Beginn', 'Ende', 'Kündigungsfrist (Tage)', 'Wert', 'Währung'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'csvSafe'], array_values($r)), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->log('export.contracts_csv', 'success', $p->tenantId, $p->userId, 'export', null, ['rows' => count($rows)]);
        return $csv;
    }

    /** Schutz vor CSV-/Formel-Injektion in Tabellenkalkulationen. */
    public static function csvSafe(mixed $v): string
    {
        $s = (string) $v;
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $f @return array{0:string,1:list<mixed>,2:string} */
    private function build(Principal $p, array $f): array
    {
        [$vis, $params] = $this->ac->visibleContractsSql($p, 'c');
        $where = ["({$vis})"];
        $join = '';

        $eq = static function (string $key, string $col) use ($f, &$where, &$params): void {
            if (isset($f[$key]) && $f[$key] !== '' && $f[$key] !== null) {
                $where[] = "{$col} = ?";
                $params[] = $f[$key];
            }
        };
        if (!empty($f['status']) && in_array($f['status'], ['draft', 'review', 'approved', 'signature', 'active', 'archived', 'rejected', 'withdrawn', 'expired', 'cancelled'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $f['status'];
        }
        $eq('contract_type_id', 'c.contract_type_id');
        $eq('department_id', 'c.department_id');
        $eq('owner_user_id', 'c.owner_user_id');
        foreach (['end_from' => 'c.end_date >= ?', 'end_to' => 'c.end_date <= ?'] as $k => $cond) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k]) === 1) {
                $where[] = $cond;
                $params[] = $f[$k];
            }
        }
        foreach (['value_min' => 'c.value_amount >= ?', 'value_max' => 'c.value_amount <= ?'] as $k => $cond) {
            if (isset($f[$k]) && $f[$k] !== '' && is_numeric($f[$k])) {
                $where[] = $cond;
                $params[] = (string) $f[$k];
            }
        }
        if (!empty($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM contract_tags xct JOIN tags xt ON xt.id = xct.tag_id WHERE xct.contract_id = c.id AND xt.tenant_id = c.tenant_id AND xt.name = ?)';
            $params[] = (string) $f['tag'];
        }
        if (!empty($f['counterparty_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM contract_parties xcp WHERE xcp.contract_id = c.id AND xcp.tenant_id = c.tenant_id AND xcp.counterparty_id = ?)';
            $params[] = (int) $f['counterparty_id'];
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $textVersion = $p->isExternal() ? 'c.approved_version_id' : 'c.current_version_id';
            $join = "LEFT JOIN contract_versions v ON v.id = {$textVersion} AND v.tenant_id = c.tenant_id";
            $words = preg_split('/[\s,;]+/u', mb_substr($q, 0, 200), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($words, 0, 6) as $w) {
                $like = '%' . self::like($w) . '%';
                $parts = [
                    'c.title LIKE ?', 'c.contract_number LIKE ?', 'c.description LIKE ?',
                    'EXISTS (SELECT 1 FROM contract_parties sp JOIN counterparties scp ON scp.id = sp.counterparty_id AND scp.tenant_id = sp.tenant_id WHERE sp.contract_id = c.id AND scp.name LIKE ?)',
                ];
                $pp = [$like, $like, $like, $like];
                if (mb_strlen($w) >= 3) {
                    // Teilstring-Suche im Text: exakt und ohne Abhängigkeit vom (erst nach Commit aktuellen) InnoDB-Volltextindex.
                    $parts[] = 'v.body_text LIKE ?';
                    $pp[] = $like;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
                array_push($params, ...$pp);
            }
        }
        return [implode(' AND ', $where), $params, $join];
    }

    private static function like(string $s): string
    {
        return addcslashes($s, '\\%_');
    }
}
