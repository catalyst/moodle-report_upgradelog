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

namespace report_upgradelog\local\systemreports;

use context_system;
use core_reportbuilder\system_report;
use core_reportbuilder\local\entities\user;
use report_upgradelog\local\entities\upgrade_group;
use report_upgradelog\upgrade_group_helper;

/**
 * Upgrade log system report class implementation
 *
 * @package    report_upgradelog
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrades extends system_report {
    /**
     * Initialise report
     */
    protected function initialise(): void {
        // Use the selected upgrade_log row as the main row for each group.
        $upgradeentity = new upgrade_group();
        $upgradetable = $upgradeentity->get_table_alias('upgrade_log');

        $this->set_main_table('upgrade_log', $upgradetable);
        $this->add_entity($upgradeentity);

        // Divide the complete log into sessions whenever the gap between two
        // adjacent entries exceeds 60 seconds. Aggregate only the requested
        // four final status messages from each session.
        [$groupquery, $groupparams] = upgrade_group_helper::get_query();
        $groupsql = 'JOIN (' . $groupquery . ") ug
                          ON ug.selectedid = {$upgradetable}.id";
        $this->add_join($groupsql, $groupparams);

        // Join the user entity.
        $userentity = new user();
        $usertable = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join("LEFT JOIN {user} {$usertable} ON {$usertable}.id = {$upgradetable}.userid"));

        $this->add_columns();
        $this->add_filters();

        $this->set_downloadable(true, get_string('pluginname', 'report_upgradelog'));
    }

    /**
     * Validates access to view this report
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('report/upgradelog:view', context_system::instance());
    }

    /**
     * Add report columns
     */
    protected function add_columns(): void {
        $this->add_columns_from_entities([
            'user:fullnamewithlink',
            'upgrade_group:information',
            'upgrade_group:version',
            'upgrade_group:release',
            'upgrade_group:timemodified',
        ]);

        // Default sorting.
        $this->set_initial_sort_column('upgrade_group:timemodified', SORT_DESC);
    }

    /**
     * Add report filters
     */
    protected function add_filters(): void {
        $this->add_filters_from_entities([
            'user:fullname',
            'upgrade_group:version',
            'upgrade_group:timemodified',
        ]);
    }
}
