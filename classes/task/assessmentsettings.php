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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_assessmentsettings - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assessmentsettings\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessmentsettings extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentsettings');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$this->get_config('dbtype')) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $this->get_config('dbtype') . '<br>';
        }
        if (!$this->get_config('remotetable')) {
            echo 'Table not defined.<br>';
            return 0;
        } else {
            echo 'Table: ' . $this->get_config('remotetable') . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $this->db_init()) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Get duedate and gradingduedate from assign table where assignment has link code.
        /********************************************************
         * ARRAY (LINK CODE-> StdClass Object)                  *
         *     idnumber                                         *
         *     id                                               *
         *     name                                             *
         *     duedate (UNIX timestamp)                         *
         *     gradingduedate (UNIX timestamp)                  *
         ********************************************************/
        $sqldates = $DB->get_records_sql('SELECT a.id as id,m.id as cm, m.idnumber as linkcode,a.name,a.duedate,a.gradingduedate FROM {course_modules} m
            JOIN {assign} a ON m.instance = a.id
            JOIN {modules} mo ON m.module = mo.id
            WHERE m.idnumber IS NOT null AND m.idnumber != "" AND mo.name = "assign"');

        // Get external assessments table name.
        $tableassm = $this->get_config('remotetable');
        $assessments = array();
        // Read assessment data from external table.
        /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     mav_idnumber                                     *
         *     assessment_number                                *
         *     assessment_name                                  *
         *     assessment_type                                  *
         *     assessment_weight                                *
         *     assessment_idcode - THIS IS THE MAIN LINK ID     *
         *     assessment_markscheme_name                       *
         *     assessment_markscheme_code                       *
         *     assessment_duedate                               *
         *     assessment_feedbackdate                          *
         ********************************************************/
        $sql = $this->db_get_sql($tableassm, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $this->db_decode($fields);
                    $assessments[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Get external table for grades and extensions - individual users->assessments.
        $tablegrades = $this->get_config('remotegradestable');
        $extensions = array();
        // Read grades and extensions data from external table.
        /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     student_code                                     *
         *     assessment_idcode                                *
         *     student_ext_duedate                               *
         *     student_ext_duetime                              *
         *     student_fbdue_date                               *
         *     student_fbdue_time                               *
         ********************************************************/
        $sql = $this->db_get_sql($tablegrades, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $this->db_decode($fields);
                    $extensions[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Create reference array of assignment id and link code from mdl.
        $assign_mdl = array();
        foreach ($sqldates as $sd) {
            $assign_mdl[$sd->linkcode]['id'] = $sd->id;
            $assign_mdl[$sd->linkcode]['cm'] = $sd->cm;
            $assign_mdl[$sd->linkcode]['lc'] = $sd->linkcode;
            $assign_mdl[$sd->linkcode]['name'] = $sd->name;
        }
        // Create reference array of assignment id and link code from data warehouse.
        $assess_ext = array();
        foreach ($assessments as $am) {
            $assess_ext[$am[assessment_idcode]]['id'] = $am[id];
            $assess_ext[$am[assessment_idcode]]['lc'] = $am[assessment_idcode];
            $assess_ext[$am[assessment_idcode]]['name'] = $am[assessment_name];
            $assess_ext[$am[assessment_idcode]]['dd'] = $am[assessment_duedate];
            $assess_ext[$am[assessment_idcode]]['fb'] = $am[assessment_feedbackdate];
            $assess_ext[$am[assessment_idcode]]['ms'] = $am[assessment_markscheme_code];
        }
        // Create reference array of students - if has a linked assessement AND an extension date/time.
        $student = array();
        foreach ($extensions as $e) {
            $key = $e[student_code].$e[assessment_idcode];
            if ($e[assessment_idcode] && ($e[student_ext_duedate] || $e[student_ext_duetime])) {
                $student[$key]['stucode'] = $e[student_code];
                $student[$key]['lc'] = $e[assessment_idcode];
                $student[$key]['extdate'] = $e[student_ext_duedate];
                $student[$key]['exttime'] = $e[student_ext_duetime];
                $student[$key]['fbdate'] = $e[student_fbdue_date];
                $student[$key]['fbtime'] = $e[student_fbdue_time];
            }
        }

        /* Set assignment settings *
         * ----------------------- */
        foreach ($assign_mdl as $k=>$v) {
            // Error trap - ensure we have an assessment link id.
            if (!empty($assign_mdl[$k]['id'])) {
                echo '<br>'.$assign_mdl[$k]['id'].': '.$assign_mdl[$k]['lc'].' - Assignment Settings<br>';
                // Set Assignment name
//                $mdl_name = $assign_mdl[$k]['name'];
//                $link = $assign_mdl[$k]['lc'];
//                $sits_name = $assess_ext[$link]['name'];
//                echo $mdl_name.':'.$sits_name.'<br>';
//                if (strlen($sits_name) > 0) {
//                    if ($mdl_name == $sits_name) {
//                        continue;
//                    } else {
//                        $DB->set_field('assign', 'name', $sits_name, array('id'=>$assign_mdl[$k]['id']));
//                        echo 'Assignment name changed.<br>';
//                    }
//                }

                // Set MarkingWorkflow. ON.
                if ($DB->get_field('assign', 'markingworkflow', array('id'=>$assign_mdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'markingworkflow', 1, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Marking Workflow set <strong>ON</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                // Set BlindMarking. OFF. Commented out, so not currently set by default.
//                if ($DB->get_field('assign', 'blindmarking', array('id'=>$assign_mdl[$k]['id'])) == 1) {
//                    $DB->set_field('assign', 'blindmarking', 0, array('id'=>$assign_mdl[$k]['id']));
//                    echo 'Blind Marking set <strong>OFF</strong> for '.$assign_mdl[$k]['id'].'<br>';
//                }
                // Require submit button. ON.
                if ($DB->get_field('assign', 'submissiondrafts', array('id'=>$assign_mdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'submissiondrafts', 1, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Submit Button set <strong>ON</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                // Require submission statment. ON.
                if ($DB->get_field('assign', 'requiresubmissionstatement', array('id'=>$assign_mdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'requiresubmissionstatement', 1, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Require Submission Statement set <strong>ON</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                // Notify graders - standard. OFF.
                if ($DB->get_field('assign', 'sendnotifications', array('id'=>$assign_mdl[$k]['id'])) == 1) {
                    $DB->set_field('assign', 'sendnotifications', 0, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Notify Graders - Standard set <strong>OFF</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                // Notify graders - late. OFF.
                if ($DB->get_field('assign', 'sendlatenotifications', array('id'=>$assign_mdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'sendlatenotifications', 0, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Notify Graders - Late set <strong>OFF</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                // Notify students. ON.
                // This is controlled by workflow - notification is not released until Workflow is marked as 'Released'.
                if ($DB->get_field('assign', 'sendstudentnotifications', array('id'=>$assign_mdl[$k]['id'])) == 1) {
                    $DB->set_field('assign', 'sendstudentnotifications', 0, array('id'=>$assign_mdl[$k]['id']));
                    echo 'Notify students set <strong>ON</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }

                // TurnItIn Settings.
                echo 'TurnItIn not set<br>';
//                $assign_mdl[$k]['cm']
                // Use_TurnItIn. ON.
                if ($DB->get_field('plagiarism_turnitin_config', 'name', array('cm'=>$assign_mdl[$k]['cm'], 'name'=>'use_turnitin')) == 0) {
                    $DB->set_field('plagiarism_turnitin_config', 'value', 1, array('cm'=>$assign_mdl[$k]['cm'], 'name'=>'use_turnitin'));
                    echo 'Use TurnItIn <strong>ON</strong> for '.$assign_mdl[$k]['id'].'<br>';
                }
                /* TII Site default settings - no need for code
                 * --------------------------------------------
                 * Display reports to students. plagiarism_show_student_report NO.
                 * When submitted to TII. SUBMIT WHEN FIRST UPLOADED.
                 * Any file type. plagiarism_allow_non_or_submissions YES.
                 * Store papers. plagiarism_submitpapersto STANDARD REPOSITORY.
                 * Check against stored papers. plagiarism_compare_student_papers YES (locked).
                 * Check against internet. plagiarism_compare_internet YES (locked).
                 * Check against publications. plagiarism_compare_journals YES (locked).
                 * Report generation speed. plagiarism_report_gen GENERATE REPORTS IMMEDIATELY (RESUBMISSION ALLOWED).
                 * Exclude bibliography. plagiarism_exclude_biblio NO.
                 * Exclude quoted. plagiarism_exclude_quoted NO.
                 * Exclude small matches. plagiarism_exclude_matches NO.
                 */


                // Set grading scale.
                $gradeitem = $DB->get_record('grade_items', array('idnumber'=>$assign_mdl[$k]['lc']));
                $gradetype_mdl = $gradeitem->scaleid;
                $gradetype_dw =  $assess_ext[$assign_mdl[$k]['lc']]['ms'];
                $gradetypeid = $DB->get_field('scale', 'id', array('name'=>$gradetype_dw));
                if ($gradetype_mdl != $gradetypeid) {
                    if ($gradetypeid > 0 ) {
                        $DB->set_field('grade_items', 'scaleid', $gradetypeid, array('idnumber'=>$assign_mdl[$k]['lc']));
                        $DB->set_field('assign', 'grade', -$gradetypeid, array('id'=>$assign_mdl[$k]['id']));
                        $DB->set_field('grade_items', 'gradetype', 2, array('idnumber'=>$assign_mdl[$k]['lc']));
                    } else {
                        $DB->set_field('grade_items', 'scaleid', null, array('idnumber'=>$assign_mdl[$k]['lc']));
                        $DB->set_field('assign', 'grade', 100, array('id'=>$assign_mdl[$k]['id']));
                        $DB->set_field('grade_items', 'gradetype', 1, array('idnumber'=>$assign_mdl[$k]['lc']));
                    }
                    echo 'Grade scale set as '.$gradetypeid.' = '.$gradetype_dw.'<br>';
                }
            }
        }



    // Free memory.
    $extdb->Close();
    }

    /* Db functions cloned from enrol/db plugin.
     * ========================================= */

    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    public function db_init() {
        global $CFG;

        require_once($CFG->libdir.'/adodb/adodb.inc.php');

        // Connect to the external database (forcing new connection).
        $extdb = ADONewConnection($this->get_config('dbtype'));
        if ($this->get_config('debugdb')) {
            $extdb->debug = true;
            ob_start(); // Start output buffer to allow later use of the page headers.
        }

        // The dbtype my contain the new connection URL, so make sure we are not connected yet.
        if (!$extdb->IsConnected()) {
            $result = $extdb->Connect($this->get_config('dbhost'),
                $this->get_config('dbuser'),
                $this->get_config('dbpass'),
                $this->get_config('dbname'), true);
            if (!$result) {
                return null;
            }
        }

        $extdb->SetFetchMode(ADODB_FETCH_ASSOC);
        if ($this->get_config('dbsetupsql')) {
            $extdb->Execute($this->get_config('dbsetupsql'));
        }
        return $extdb;
    }

    public function db_addslashes($text) {
        // Use custom made function for now - it is better to not rely on adodb or php defaults.
        if ($this->get_config('dbsybasequoting')) {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
        } else {
            $text = str_replace("'", "''", $text);
        }
        return $text;
    }

    public function db_encode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_encode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, 'utf-8', $dbenc);
        }
    }

    public function db_decode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_decode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, $dbenc, 'utf-8');
        }
    }

    public function db_get_sql($table, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key = '$value'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql = "SELECT $distinct $fields
                  FROM $table
                 $where
                  $sort";
        return $sql;
    }

    public function db_get_sql_like($table2, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key LIKE '%$value%'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql2 = "SELECT $distinct $fields
                  FROM $table2
                 $where
                  $sort";
        return $sql2;
    }


    /**
     * Returns plugin config value
     * @param  string $name
     * @param  string $default value if config does not exist yet
     * @return string value or default
     */
    public function get_config($name, $default = null) {
        $this->load_config();
        return isset($this->config->$name) ? $this->config->$name : $default;
    }

    /**
     * Sets plugin config value
     * @param  string $name name of config
     * @param  string $value string config value, null means delete
     * @return string value
     */
    public function set_config($name, $value) {
        $pluginname = $this->get_name();
        $this->load_config();
        if ($value === null) {
            unset($this->config->$name);
        } else {
            $this->config->$name = $value;
        }
        set_config($name, $value, "local_$pluginname");
    }

    /**
     * Makes sure config is loaded and cached.
     * @return void
     */
    public function load_config() {
        if (!isset($this->config)) {
            $name = $this->get_name();
            $this->config = get_config("local_$name");
        }
    }
}
