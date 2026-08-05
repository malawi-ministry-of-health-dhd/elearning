<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_mohhierarchy\local\hierarchy;

/**
 * Shared database access for the three synchronised reference tables.
 *
 * All reads and writes of hierarchy reference data go through a repository. Callers,
 * including forms, external functions, tasks and CLI scripts, never touch $DB directly.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class reference_repository {
    /**
     * The table this repository owns.
     *
     * @return string Table name without the Moodle prefix.
     */
    abstract public function get_table(): string;

    /**
     * The fields refreshed from the remote source, excluding externalid and the housekeeping columns.
     *
     * @return string[]
     */
    abstract public function get_syncable_fields(): array;

    /**
     * Fetch one row by its Moodle primary key.
     *
     * @param int $id The local id.
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record($this->get_table(), ['id' => $id]) ?: null;
    }

    /**
     * Fetch one row by its remote identifier.
     *
     * @param int $externalid The remote id.
     * @return \stdClass|null
     */
    public function get_by_externalid(int $externalid): ?\stdClass {
        global $DB;

        return $DB->get_record($this->get_table(), ['externalid' => $externalid]) ?: null;
    }

    /**
     * Map a set of remote identifiers onto local ids.
     *
     * @param int[] $externalids Remote ids.
     * @return array Remote id => local id.
     */
    public function get_id_map(array $externalids): array {
        global $DB;

        if (!$externalids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($externalids, SQL_PARAMS_NAMED, 'ext');
        $sql = "SELECT externalid, id FROM {{$this->get_table()}} WHERE externalid {$insql}";

        return array_map('intval', $DB->get_records_sql_menu($sql, $params));
    }

    /**
     * Insert or refresh one row, matched on its remote identifier.
     *
     * The remote identifier is stored in its own unique column; it is never used as a primary key.
     * A row that reappears remotely is reactivated rather than duplicated.
     *
     * @param \stdClass $record Must carry externalid plus the syncable fields.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return array{id: int, created: bool, updated: bool}
     */
    public function upsert(\stdClass $record, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $table = $this->get_table();
        $externalid = (int) $record->externalid;
        $existing = $this->get_by_externalid($externalid);

        if ($existing === null) {
            $insert = (object) ['externalid' => $externalid];
            foreach ($this->get_syncable_fields() as $field) {
                $insert->{$field} = $record->{$field} ?? null;
            }
            $insert->active = 1;
            $insert->lastseen = $now;
            $insert->timecreated = $now;
            $insert->timemodified = $now;

            return ['id' => (int) $DB->insert_record($table, $insert), 'created' => true, 'updated' => false];
        }

        $update = (object) ['id' => $existing->id, 'lastseen' => $now];
        $changed = false;
        foreach ($this->get_syncable_fields() as $field) {
            $value = $record->{$field} ?? null;
            if (self::differs($existing->{$field}, $value)) {
                $update->{$field} = $value;
                $changed = true;
            }
        }
        if ((int) $existing->active !== 1) {
            $update->active = 1;
            $changed = true;
        }
        if ($changed) {
            $update->timemodified = $now;
        }
        $DB->update_record($table, $update);

        return ['id' => (int) $existing->id, 'created' => false, 'updated' => $changed];
    }

    /**
     * Flag rows the remote source has stopped publishing.
     *
     * Rows are deactivated and never deleted, so existing assignments never point at a missing row.
     *
     * @param int $before Deactivate rows whose lastseen is older than this timestamp.
     * @param int|null $now Timestamp to record, defaults to now.
     * @param int[] $excludeids Local ids to leave alone, used to protect a parent that a child
     *      from the same successful payload still references.
     * @return int Number of rows deactivated.
     */
    public function deactivate_not_seen_since(int $before, ?int $now = null, array $excludeids = []): int {
        global $DB;

        $now = $now ?? time();
        $table = $this->get_table();
        $select = 'active = :active AND lastseen < :before';
        $params = ['active' => 1, 'before' => $before];
        if ($excludeids) {
            [$notinsql, $notinparams] = $DB->get_in_or_equal(
                array_map('intval', array_values($excludeids)),
                SQL_PARAMS_NAMED,
                'keep',
                false,
            );
            $select .= " AND id {$notinsql}";
            $params += $notinparams;
        }
        $count = $DB->count_records_select($table, $select, $params);
        if ($count > 0) {
            $sql = "UPDATE {{$table}} SET active = 0, timemodified = :now WHERE {$select}";
            $DB->execute($sql, $params + ['now' => $now]);
        }

        return $count;
    }

    /**
     * Count rows, optionally only the active ones.
     *
     * @param bool $activeonly Whether to exclude deactivated rows.
     * @return int
     */
    public function count(bool $activeonly = true): int {
        global $DB;

        return $DB->count_records($this->get_table(), $activeonly ? ['active' => 1] : []);
    }

    /**
     * Compare a stored value with an incoming one.
     *
     * Database drivers return most column types as strings, so values are compared as strings
     * while still distinguishing null from an empty string.
     *
     * @param mixed $stored The value currently in the database.
     * @param mixed $incoming The value from the remote source.
     * @return bool Whether the value needs writing.
     */
    protected static function differs(mixed $stored, mixed $incoming): bool {
        if ($stored === null || $incoming === null) {
            return ($stored === null) !== ($incoming === null);
        }

        return (string) $stored !== (string) $incoming;
    }
}
