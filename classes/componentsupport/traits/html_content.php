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
 * Trait for supporting html content.
 * @author    Guy Thomas <citricity@gmail.com>
 * @copyright Copyright (c) 2018 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ally\componentsupport\traits;

use tool_ally\local_content;
use tool_ally\models\component;
use tool_ally\models\component_content;

use stdClass;

defined ('MOODLE_INTERNAL') || die();

trait html_content {

    /**
     * Standard method for getting course html content items.
     *
     * @param $courseid
     * @return array
     * @throws \dml_exception
     */
    protected function std_get_course_html_content_items($courseid) {
        global $DB;

        $array = [];
        if (!$this->module_installed()) {
            return $array;
        }

        $component = $this->get_component_name();
        $select = "course = ? AND introformat = ? AND intro !=''";
        $rs = $DB->get_recordset_select($component, $select, [$courseid, FORMAT_HTML]);
        foreach ($rs as $row) {
            $array[] = new component(
                $row->id, $component, $component, 'intro', $courseid, $row->timemodified,
                $row->introformat, $row->name);
        }
        $rs->close();

        return $array;
    }

    /**
     * Standard method for getting html content.
     *
     * @param int $id
     * @param string $table
     * @param string $field
     * @param array $tablefields
     * @param null|int $courseid
     * @param string $titlefield
     * @param string $modifiedfield
     * @param callable $recordlambda - lambda to run on record once recovered.
     * @param stdClass|null $record
     * @return component_content | null;
     * @throws \coding_exception
     */
    protected function std_get_html_content($id, $table, $field, $courseid = null, $titlefield = 'name',
                                            $modifiedfield = 'timemodified', $recordlambda = null,
                                            stdClass $record = null) {
        global $DB;

        if (!$this->module_installed()) {
            return null;
        }

        $component = $this->get_component_name();

        $this->validate_component_table_field($table, $field);

        if ($record === null) {
            $record = $DB->get_record($table, ['id' => $id]);
        }
        if ($recordlambda) {
            $recordlambda($record);
            if ($courseid === null) {
                if (!empty($record->course)) {
                    $courseid = $record->course;
                } else if (!empty($record->courseid)) {
                    $courseid = $record->courseid;
                }
            }
        }

        if (!$record) {
            $ident = 'component='.$component.'&table='.$table.'&field='.$field.'&id='.$id;
            throw new \moodle_exception('error:invalidcomponentident', 'tool_ally', null, $ident);
        }

        $timemodified = $record->$modifiedfield;
        $content = $record->$field;
        $formatfield = $field.'format';
        $contentformat = $record->$formatfield;
        $title = !empty($record->$titlefield) ? $record->$titlefield : null;
        $url = null;
        if (method_exists($this, 'make_url')) {
            $url = $this->make_url($id, $table, $field, $courseid);
        }

        $contentmodel = new component_content($id, $component, $table, $field, $courseid, $timemodified, $contentformat,
            $content, $title, $url);

        return $contentmodel;
    }

    /**
     * Return a content model for a deleted content item.
     * @param int $id
     * @param string $table
     * @param string $field
     * @param int $courseid // This is mandatory because you should be able to get it from the event.
     * @param null|int $timemodified
     * @return component_content
     */
    public function get_html_content_deleted($id, $table, $field, $courseid, $timemodified = null) {
        if (!$this->module_installed()) {
            return null;
        }

        $timemodified = $timemodified ? $timemodified : time();
        $component = $this->get_component_name();
        $contentmodel = new component_content($id, $component, $table, $field, $courseid, $timemodified,
            FORMAT_HTML, '', '');
        return $contentmodel;
    }

    /**
     * Standard method for replacing html content.
     * @param int $id
     * @param string $table
     * @param string $field
     * @param string $content
     * @return mixed
     * @throws \coding_exception
     */
    protected function std_replace_html_content($id, $table, $field, $content) {
        global $DB;

        if (!$this->module_installed()) {
            return null;
        }

        $this->validate_component_table_field($table, $field);

        $dobj = (object) [
            'id' => $id,
            $field => $content
        ];
        if (!$DB->update_record($table, $dobj)) {
            return false;
        }

        if ($this->component_type() === self::TYPE_MOD && $table === $this->get_component_name()) {
            list ($course, $cm) = get_course_and_cm_from_instance($id, $table);
            \core\event\course_module_updated::create_from_cm($cm, $cm->context)->trigger();
            // Course cache needs updating to show new module text.
            rebuild_course_cache($course->id, true);
        }

        return true;
    }

    /**
     * @param int $courseid
     * @param string $contentfield
     * @param null|string $table
     * @param null|string $selectfield
     * @param null|string $selectval
     * @param null|string $titlefield
     * @param null| \callable $compmetacallback
     * @return component[]
     * @throws \dml_exception
     */
    protected function get_selected_html_content_items($courseid, $contentfield,
                                                       $table = null, $selectfield = null,
                                                       $selectval = null, $titlefield = null,
                                                       $compmetacallback = null) {
        global $DB;

        if (!$this->module_installed()) {
            return [];
        }

        $array = [];

        $compname = $this->get_component_name();
        $table = $table === null ? $compname : $table;
        $selectfield = $selectfield === null ? 'course' : $selectfield;
        $selectval = $selectval === null ? $courseid : $selectval;
        $titlefield = $titlefield === null ? 'name' : $titlefield;

        $formatfld = $contentfield.'format';

        $select = "$selectfield = ? AND $formatfld = ? AND $contentfield !=''";
        $params = [$selectval, FORMAT_HTML];
        $rs = $DB->get_recordset_select($table, $select, $params);
        foreach ($rs as $row) {
            $comp = new component(
                $row->id, $compname, $table, $contentfield, $courseid, $row->timemodified,
                $row->$formatfld, $row->$titlefield);
            if (is_callable($compmetacallback)) {
                $comp->meta = $compmetacallback($row);
            }
            $array[] = $comp;
        }
        $rs->close();

        return $array;
    }

    /**
     * Get introduction html content items.
     * @param int $courseid
     * @return array
     * @throws \dml_exception
     */
    protected function get_intro_html_content_items($courseid) {
        return $this->get_selected_html_content_items($courseid, 'intro');
    }


    /**
     * @param string $module
     * @param int $id
     * @return string
     * @throws \moodle_exception
     */
    protected function make_module_instance_url($module, $id) {
        list($course, $cm) = get_course_and_cm_from_instance($id, $module);
        return new \moodle_url('/course/view.php?id=' . $course->id . '#module-' . $cm->id) . '';
    }

    /**
     * @param component[] $contents
     */
    protected function bulk_queue_delete_content(array $contents) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        foreach ($contents as $content) {
            local_content::queue_delete($content->courseid,
                $content->id, $content->component, $content->table, $content->field);
        }

        $transaction->allow_commit();
    }

    public function get_annotation($id) {
        return '';
    }
}