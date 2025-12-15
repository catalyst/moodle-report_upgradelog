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

namespace report_upgradelog;

use core_text;

/**
 * Helper class for plugin updates and installation details.
 *
 * @package    report_upgradelog
 * @copyright  2025 Alex Damsted <alexdamsted@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class details_helper {

    /**
     * Generates the collapsible region HTML containing plugin updates
     * and installations related to a core Moodle upgrade.
     *
     * @param mixed $value Unused (default column value)
     * @param \stdClass $row Row data from reportbuilder
     * @return string
     */
    public static function build_details($value, \stdClass $row): string {
        global $DB;

        $pluginupdatedcount   = 0;
        $plugininstalledcount = 0;

        // Core upgrade time window (60s).
        $coretime = (int)($row->timemodified ?? 0);
        $start    = $coretime;
        $end      = $coretime + 60;

        // Find plugin upgrade/install events.
        list($infosql, $infoparams) = $DB->get_in_or_equal(
            ['Starting plugin upgrade', 'Starting plugin installation'],
            SQL_PARAMS_NAMED,
            'info_'
        );

        $pluginupdates = $DB->get_records_select(
            'upgrade_log',
            "info {$infosql} AND timemodified >= :start AND timemodified < :end",
            array_merge($infoparams, [
                'start' => $start,
                'end'   => $end,
            ]),
            'timemodified ASC',
            'plugin, version, targetversion, timemodified'
        );

        // Build Moodle html_table.
        $table = new \html_table();
        $table->head = [
            get_string('plugin'),
            get_string('versionold', 'report_upgradelog'),
            get_string('versionnew', 'report_upgradelog'),
            get_string('time'),
        ];

        // Bootstrap classes.
        $table->attributes['class'] = 'table table-sm w-auto mb-0';

        foreach ($pluginupdates as $p) {

            $isinstall = empty($p->version);

            if ($isinstall) {
                $plugininstalledcount++;
                $rowclass = 'table-success'; // light green
            } else {
                $pluginupdatedcount++;
                $rowclass = 'table-warning'; // light yellow
            }

            $row = new \html_table_row([
                s($p->plugin ?? ''),
                s($p->version ?? ''),
                s($p->targetversion ?? ''),
                \core_date::strftime(get_string('strftimetime', 'langconfig'), $p->timemodified),
            ]);

            $row->attributes['class'] = $rowclass;
            $table->data[] = $row;
        }

        $innerhtml = \html_writer::table($table);

        // Unique region ID for collapsible element.
        $regionid = 'upgrade_details_' . ($row->upgradeid ?? uniqid());

        return \print_collapsible_region(
            $innerhtml,
            'upgrade-details-region collapsed',
            $regionid,
            $summary = get_string('upgradepluginsummary', 'report_upgradelog', (object)[
                'installed' => $plugininstalledcount,
                'updated'   => $pluginupdatedcount,
            ]),
            '',
            false,
            true
        );
    }
}
