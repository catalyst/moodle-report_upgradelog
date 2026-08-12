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

namespace report_upgradelog\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use report_upgradelog\upgrade_group_helper;

/**
 * Upgrade log group entity.
 *
 * The system report supplies an aggregated derived table with the alias "ug".
 *
 * @package    report_upgradelog
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_group extends base {
    /**
     * Returns default tables used by this entity.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['upgrade_log'];
    }

    /**
     * Returns default table aliases.
     *
     * @return string[]
     */
    protected function get_default_table_aliases(): array {
        return ['upgrade_log' => 'ul'];
    }

    /**
     * Returns the default entity title.
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('upgradegroups', 'report_upgradelog');
    }

    /**
     * Initialise the entity.
     */
    public function initialise(): self {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }

        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns all report columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $upgradetable = $this->get_table_alias('upgrade_log');
        $coreversionfield = "CASE
            WHEN ug.coreinstalledcount + ug.coreupgradedcount > 0
            THEN {$upgradetable}.version
            ELSE NULL
        END";

        $columns = [];

        // Information.
        $columns[] = (new column(
            'information',
            new lang_string('info'),
            $this->get_entity_name()
        ))
            ->set_type(column::TYPE_TEXT)
            ->add_fields(
                'ug.plugininstalledcount, ug.pluginupgradedcount, ' .
                'ug.coreinstalledcount, ug.coreupgradedcount, ' .
                'ug.starttime, ug.endtime'
            )
            ->set_is_sortable(false)
            ->add_callback([upgrade_group_helper::class, 'format_information']);

        // Moodle version. This is NULL for groups without a core change.
        $columns[] = (new column(
            'version',
            new lang_string('moodleversion'),
            $this->get_entity_name()
        ))
            ->set_type(column::TYPE_TEXT)
            ->add_field($coreversionfield, 'version')
            ->add_fields('ug.coreinstalledcount, ug.coreupgradedcount')
            ->set_is_sortable(true)
            ->add_callback([upgrade_group_helper::class, 'format_version']);

        // Moodle release. This is NULL for groups without a core change.
        $columns[] = (new column(
            'release',
            new lang_string('moodlerelease'),
            $this->get_entity_name()
        ))
            ->set_type(column::TYPE_TEXT)
            ->add_field($coreversionfield, 'version')
            ->add_fields('ug.coreinstalledcount, ug.coreupgradedcount')
            ->set_is_sortable(false)
            ->add_callback([upgrade_group_helper::class, 'format_release']);

        // Time uses the first target log timestamp from the group.
        $columns[] = (new column(
            'timemodified',
            new lang_string('time'),
            $this->get_entity_name()
        ))
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field('ug.starttime', 'timemodified')
            ->set_is_sortable(true)
            ->add_callback(function ($timestamp) {
                return \core_date::strftime(get_string('strftimerecentfull', 'langconfig'), $timestamp);
            });

        return $columns;
    }

    /**
     * Returns all report filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $upgradetable = $this->get_table_alias('upgrade_log');
        $coreversionfield = "CASE
            WHEN ug.coreinstalledcount + ug.coreupgradedcount > 0
            THEN {$upgradetable}.version
            ELSE NULL
        END";

        return [
            (new filter(
                number::class,
                'version',
                new lang_string('moodleversion'),
                $this->get_entity_name(),
                $coreversionfield
            )),
            (new filter(
                date::class,
                'timemodified',
                new lang_string('time'),
                $this->get_entity_name(),
                'ug.starttime'
            ))->set_limited_operators([
                date::DATE_ANY,
                date::DATE_RANGE,
                date::DATE_PREVIOUS,
                date::DATE_CURRENT,
            ]),
        ];
    }
}
