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

/**
 * Provides {@link tool_policy\event\acceptance_created} class.
 *
 * @package     tool_policy
 * @copyright   2018 Marina Glancy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_policy\event;

use core\event\base;

defined('MOODLE_INTERNAL') || die();

/**
 * Event acceptance_created
 *
 * @package     tool_policy
 * @copyright   2018 Marina Glancy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acceptance_created extends base {

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['objecttable'] = 'tool_policy_acceptances';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Create event from record.
     *
     * @param stdClass $record
     * @return tool_policy\event\acceptance_created
     */
    public static function create_from_record($record) {
        $event = static::create([
            'objectid' => $record->id,
            'relateduserid' => $record->userid,
            'context' => \context_user::instance($record->userid), // TODO or system?
            'other' => [
                'policyversionid' => $record->policyversionid,
                'note' => $record->note,
                'status' => $record->status,
            ],
        ]);
        $event->add_record_snapshot($event->objecttable, $record);
        return $event;
    }

    /**
     * Returns event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_acceptance_created', 'tool_policy');
    }

    /**
     * Get the event description.
     *
     * @return string
     */
    public function get_description() {
        if ($this->other['status'] == 1) {
            $action = 'added consent to';
        } else if ($this->other['status'] == -1) {
            $action = 'revoked consent to';
        } else {
            $action = 'created an empty consent record for';
        }
        return "The user with id '{$this->userid}' $action the policy with revision {$this->other['policyversionid']} ".
            "for the user with id '{$this->relateduserid}'";
    }

    /**
     * Get URL related to the action
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/policy/acceptance.php', array('userid' => $this->relateduserid,
            'versionid' => $this->other['policyversionid']));
    }

    /**
     * Get the object ID mapping.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return array('db' => 'tool_policy', 'restore' => \core\event\base::NOT_MAPPED);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (empty($this->other['policyversionid'])) {
            throw new \coding_exception('The \'policyversionid\' value must be set');
        }

        if (!isset($this->other['status'])) {
            throw new \coding_exception('The \'status\' value must be set');
        }

        if (empty($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    /**
     * No mapping required for this event because this event is not backed up.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}