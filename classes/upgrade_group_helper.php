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

declare(strict_types=1);

namespace report_upgradelog;

use core_reportbuilder\local\helpers\database;
use html_writer;
use moodle_url;

/**
 * Provides the cross-database query used to group upgrade log records.
 *
 * @package    report_upgradelog
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_group_helper {
    /** Maximum gap between adjacent records in the same group. */
    public const GROUP_GAP_SECONDS = 60;

    /**
     * Return the grouped upgrade log SQL and parameters.
     *
     * The query uses SQL features supported by Moodle 4.5's minimum
     * PostgreSQL and MySQL versions. Conditional aggregation uses CASE rather
     * than PostgreSQL's FILTER clause so the same query works on both.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function get_query(): array {
        $values = [
            'coreinstalled' => 'Core installed',
            'coreupgraded' => 'Core upgraded',
            'coreinstalledcount' => 'Core installed',
            'coreupgradedcount' => 'Core upgraded',
            'plugininstalledcount' => 'Plugin installed',
            'pluginupgradedcount' => 'Plugin upgraded',
            'targetcoreinstalled' => 'Core installed',
            'targetcoreupgraded' => 'Core upgraded',
            'targetplugininstalled' => 'Plugin installed',
            'targetpluginupgraded' => 'Plugin upgraded',
        ];

        $placeholders = [];
        $params = [];
        foreach ($values as $key => $value) {
            $paramname = database::generate_param_name('_upgradelog');
            $placeholders[$key] = ':' . $paramname;
            $params[$paramname] = $value;
        }

        $sql = "
            WITH logwithprevious AS (
                SELECT
                    id,
                    info,
                    timemodified,
                    LAG(timemodified) OVER (
                        ORDER BY timemodified, id
                    ) AS previoustime
                FROM {upgrade_log}
            ),
            logwithgroupstart AS (
                SELECT
                    id,
                    info,
                    timemodified,
                    CASE
                        WHEN previoustime IS NULL
                          OR timemodified - previoustime > " . self::GROUP_GAP_SECONDS . "
                        THEN 1
                        ELSE 0
                    END AS newgroup
                FROM logwithprevious
            ),
            groupedlogs AS (
                SELECT
                    id,
                    info,
                    timemodified,
                    SUM(newgroup) OVER (
                        ORDER BY timemodified, id
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS groupid
                FROM logwithgroupstart
            ),
            groupedlogswithbounds AS (
                SELECT
                    id,
                    info,
                    timemodified,
                    groupid,
                    MIN(timemodified) OVER (
                        PARTITION BY groupid
                    ) AS groupstarttime,
                    MAX(timemodified) OVER (
                        PARTITION BY groupid
                    ) AS groupendtime
                FROM groupedlogs
            )
            SELECT
                COALESCE(
                    MIN(CASE
                        WHEN info IN (
                            {$placeholders['coreinstalled']},
                            {$placeholders['coreupgraded']}
                        ) THEN id
                        ELSE NULL
                    END),
                    MIN(id)
                ) AS selectedid,
                groupid,
                COUNT(*) AS logcount,
                MIN(groupstarttime) AS starttime,
                MAX(groupendtime) AS endtime,
                MAX(groupendtime) - MIN(groupstarttime) AS durationseconds,
                SUM(CASE
                    WHEN info = {$placeholders['coreinstalledcount']} THEN 1 ELSE 0
                END) AS coreinstalledcount,
                SUM(CASE
                    WHEN info = {$placeholders['coreupgradedcount']} THEN 1 ELSE 0
                END) AS coreupgradedcount,
                SUM(CASE
                    WHEN info = {$placeholders['plugininstalledcount']} THEN 1 ELSE 0
                END) AS plugininstalledcount,
                SUM(CASE
                    WHEN info = {$placeholders['pluginupgradedcount']} THEN 1 ELSE 0
                END) AS pluginupgradedcount
            FROM groupedlogswithbounds
            WHERE info IN (
                {$placeholders['targetcoreinstalled']},
                {$placeholders['targetcoreupgraded']},
                {$placeholders['targetplugininstalled']},
                {$placeholders['targetpluginupgraded']}
            )
            GROUP BY groupid";

        return [$sql, $params];
    }

    /**
     * Format the Information column for an upgrade group.
     *
     * @param mixed $value Unused column value.
     * @param \stdClass $row Report row.
     * @return string
     */
    public static function format_information($value, \stdClass $row): string {
        if ((int)($row->coreinstalledcount ?? 0) > 0) {
            $stringkey = 'upgradegroupcoreinstalled';
        } else if ((int)($row->coreupgradedcount ?? 0) > 0) {
            $stringkey = 'upgradegroupcoreupgraded';
        } else {
            $stringkey = 'upgradegroupnocore';
        }

        $summary = get_string('upgradepluginsummary', 'report_upgradelog', (object)[
            'installed' => (int)($row->plugininstalledcount ?? 0),
            'updated' => (int)($row->pluginupgradedcount ?? 0),
        ]);

        $url = new moodle_url('/report/upgradelog/details.php', [
            'start' => (int)($row->starttime ?? 0),
            'end' => (int)($row->endtime ?? 0),
        ]);

        return get_string($stringkey, 'report_upgradelog') . ': ' . html_writer::link($url, $summary);
    }

    /**
     * Format the Moodle version column.
     *
     * @param mixed $value Selected upgrade log version.
     * @param \stdClass $row Report row.
     * @return string
     */
    public static function format_version($value, \stdClass $row): string {
        if (!self::has_core_change($row) || $value === null || $value === '') {
            return '';
        }

        return version_helper::get_version_string((string)$value);
    }

    /**
     * Format the Moodle release column.
     *
     * @param mixed $value Selected upgrade log version.
     * @param \stdClass $row Report row.
     * @return string
     */
    public static function format_release($value, \stdClass $row): string {
        if (!self::has_core_change($row) || $value === null || $value === '') {
            return '';
        }

        return version_helper::get_release_name((string)$value);
    }

    /**
     * Whether a group contains a core install or upgrade.
     *
     * @param \stdClass $row Report row.
     * @return bool
     */
    private static function has_core_change(\stdClass $row): bool {
        return (int)($row->coreinstalledcount ?? 0) + (int)($row->coreupgradedcount ?? 0) > 0;
    }
}
