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

/**
 * Tests for the upgrade log grouping query.
 *
 * The test executes using Moodle's configured database driver, so it can be
 * run in both the PostgreSQL and MySQL CI jobs.
 *
 * @package    report_upgradelog
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_upgradelog\upgrade_group_helper
 */
final class upgrade_group_helper_test extends \advanced_testcase {
    /** @var int User ID used by test log records. */
    private int $userid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;
        $DB->delete_records('upgrade_log');
        $this->userid = (int)$this->getDataGenerator()->create_user()->id;
    }

    /**
     * Test grouping, target filtering, counts and selected IDs.
     */
    public function test_get_sql_groups_upgrade_logs(): void {
        $this->insert_log('Unrelated log', 1000);
        $plugininstalledid = $this->insert_log('Plugin installed', 1010);
        $coreupgradedid = $this->insert_log('Core upgraded', 1070);

        // A 61-second gap starts a new group. The unrelated entry is part of
        // that group but must not be included in its aggregate values.
        $this->insert_log('Unrelated log', 1131);
        $pluginupgradedid = $this->insert_log('Plugin upgraded', 1140);
        $this->insert_log('Plugin installed', 1200);

        global $DB;
        [$sql, $params] = upgrade_group_helper::get_query();

        // Report Builder rejects parameters not created by its database
        // helper. This assertion protects the system report integration as
        // well as the query itself.
        $this->assertTrue(database::validate_params($params));

        $records = $DB->get_records_sql($sql, $params);

        $this->assertCount(2, $records);

        $first = $records[$coreupgradedid];
        $this->assertSame($coreupgradedid, (int)$first->selectedid);
        $this->assertNotSame($plugininstalledid, (int)$first->selectedid);
        $this->assertSame(2, (int)$first->logcount);
        $this->assertSame(1000, (int)$first->starttime);
        $this->assertSame(1070, (int)$first->endtime);
        $this->assertSame(70, (int)$first->durationseconds);
        $this->assertSame(0, (int)$first->coreinstalledcount);
        $this->assertSame(1, (int)$first->coreupgradedcount);
        $this->assertSame(1, (int)$first->plugininstalledcount);
        $this->assertSame(0, (int)$first->pluginupgradedcount);

        $second = $records[$pluginupgradedid];
        $this->assertSame($pluginupgradedid, (int)$second->selectedid);
        $this->assertSame(2, (int)$second->logcount);
        $this->assertSame(1131, (int)$second->starttime);
        $this->assertSame(1200, (int)$second->endtime);
        $this->assertSame(69, (int)$second->durationseconds);
        $this->assertSame(0, (int)$second->coreinstalledcount);
        $this->assertSame(0, (int)$second->coreupgradedcount);
        $this->assertSame(1, (int)$second->plugininstalledcount);
        $this->assertSame(1, (int)$second->pluginupgradedcount);
    }

    /**
     * Test formatting of the original report columns.
     */
    public function test_format_original_columns(): void {
        $coregroup = (object)[
            'coreinstalledcount' => 0,
            'coreupgradedcount' => 1,
            'plugininstalledcount' => 2,
            'pluginupgradedcount' => 3,
            'starttime' => 1000,
            'endtime' => 1070,
        ];
        $coreinformation = upgrade_group_helper::format_information(null, $coregroup);
        $this->assertStringStartsWith('Core upgraded: ', $coreinformation);
        $this->assertStringContainsString('>2 installed, 3 updated</a>', $coreinformation);
        $this->assertStringContainsString('start=1000', $coreinformation);
        $this->assertStringContainsString('end=1070', $coreinformation);
        $this->assertSame('2024100700', upgrade_group_helper::format_version('2024100700', $coregroup));
        $this->assertSame('4.5', upgrade_group_helper::format_release('2024100700', $coregroup));

        $installedgroup = clone $coregroup;
        $installedgroup->coreinstalledcount = 1;
        $installedgroup->coreupgradedcount = 0;
        $this->assertStringStartsWith(
            'Core installed: ',
            upgrade_group_helper::format_information(null, $installedgroup)
        );

        $plugingroup = (object)[
            'coreinstalledcount' => 0,
            'coreupgradedcount' => 0,
            'plugininstalledcount' => 1,
            'pluginupgradedcount' => 4,
            'starttime' => 1131,
            'endtime' => 1200,
        ];
        $plugininformation = upgrade_group_helper::format_information(null, $plugingroup);
        $this->assertStringStartsWith('No core upgrade: ', $plugininformation);
        $this->assertStringContainsString('>1 installed, 4 updated</a>', $plugininformation);
        $this->assertStringContainsString('start=1131', $plugininformation);
        $this->assertStringContainsString('end=1200', $plugininformation);
        $this->assertSame('', upgrade_group_helper::format_version('2024100700', $plugingroup));
        $this->assertSame('', upgrade_group_helper::format_release('2024100700', $plugingroup));
    }

    /**
     * Insert an upgrade log test record.
     *
     * @param string $info Info value.
     * @param int $timemodified Unix timestamp.
     * @return int Inserted ID.
     */
    private function insert_log(string $info, int $timemodified): int {
        global $DB;

        return (int)$DB->insert_record('upgrade_log', (object)[
            'type' => 0,
            'plugin' => 'core',
            'version' => null,
            'targetversion' => null,
            'info' => $info,
            'details' => null,
            'backtrace' => null,
            'userid' => $this->userid,
            'timemodified' => $timemodified,
        ]);
    }
}
