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
 * Question behaviour for the adaptive external grading mode.
 *
 * @package    qbehaviour_adaptiveexternalgrading
 * @copyright  2019 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     K.Borm <k.borm[at]ostfalia.de>
 */
defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/question/behaviour/adaptive/renderer.php');

/**
 * Renderer for outputting parts of a question belonging to the
 * adaptive external grading behaviour.
 *
 * @copyright  2019 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveexternalgrading_renderer extends qbehaviour_adaptive_renderer {
    private $nopenalty = false;

    public function controls(question_attempt $qa, question_display_options $options) {
        $behaviour = $qa->get_behaviour();
        if (get_class($behaviour) != "qbehaviour_adaptiveexternalgrading") {
            throw new coding_exception("unexpected behaviour: " . $behaviour);
        }

        if ($behaviour->showsubmit) {
            return $this->submit_button($qa, $options);
        } else {
            return '';
        }
    }

    public function feedback(question_attempt $qa, question_display_options $options) {

        // remember use of penalty from attempt behaviour
        $behaviour = $qa->get_behaviour();
        if (get_class($behaviour) != "qbehaviour_adaptiveexternalgrading") {
            throw new coding_exception("unexpected behaviour: " . $behaviour);
        }

        $this->nopenalty = $behaviour->nopenalty;

        // If the latest answer was invalid, display an informative message.
        switch ($qa->get_state()) {
            case question_state::$invalid:
                return html_writer::nonempty_tag('div', $this->disregarded_info(),
                        array('class' => 'gradingdetails'));

            case question_state::$needsgrading:
                return html_writer::nonempty_tag('div',
                        get_string('gradeinternalerror', 'qbehaviour_adaptiveexternalgrading'),
                        array('class' => 'gradingdetails'));
        }

        // Otherwise get the details.
        return $this->render_adaptive_marks(
                $qa->get_behaviour()->get_adaptive_marks(), $options);
    }

    protected function grading_details(qbehaviour_adaptive_mark_details $details, question_display_options $options) {
        if ($this->nopenalty) {
            $mark = $details->get_formatted_marks($options->markdp);
            return get_string('gradingdetails', 'qbehaviour_adaptive', $mark);
        } else {
            return parent::grading_details($details, $options);
        }
    }

    protected function disregarded_info() {
        if ($this->nopenalty) {
            return '';
        } else {
            return parent::disregarded_info();
        }
    }
}
