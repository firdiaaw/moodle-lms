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
 * Question behaviour for the adaptive type adding feedback by grader support.
 *
 * @package    qbehaviour_adaptiveexternalgrading
 * @copyright  2019 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     K.Borm <k.borm[at]ostfalia.de>
 */

defined('MOODLE_INTERNAL') || die();
define('PRECHECK', true);

require_once($CFG->dirroot . '/question/behaviour/adaptive/behaviour.php');

/**
 * Question behaviour for adaptiveexternalgrading.
 *
 * @copyright  2019 Ostfalia fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveexternalgrading extends qbehaviour_adaptive {

    public $nopenalty = -1;
    public $showsubmit = true;
    public $showcompile = false;

    protected $preferred = null;

    public function __construct(question_attempt $qa, $preferredbehaviour) {
        if ($preferredbehaviour == 'deferredcbm' || $preferredbehaviour == 'immediatecbm') {
            throw new moodle_exception('proforma questions are not compatible with cbm behaviour');
        }

        parent::__construct($qa, $preferredbehaviour);
        $this->preferred = $preferredbehaviour;
        if (!is_string($preferredbehaviour)) {
            // happens if teacher calls question review => set dummy value
            $this->nopenalty = true;

            if (!is_null($preferredbehaviour)) {
                if (get_class($preferredbehaviour) == "qbehaviour_adaptiveexternalgrading") {
                    $this->nopenalty = $preferredbehaviour->nopenalty;
                    $this->preferred = $preferredbehaviour->preferred;
                } else if (is_a($preferredbehaviour, 'question_behaviour')) {
                    $this->preferred = $preferredbehaviour->get_name();
                } else {
                    throw new coding_exception("preferredbehaviour is not a string, instead: " . get_class($preferredbehaviour));
                }
            } else {
                // happens if teacher calls question review =>
                // do not throw an exception!
                $this->nopenalty = -2;
                // throw new coding_exception("preferredbehaviour is null");
            }
        } else {
            // try and guess what behaviour may use multiple tries
            switch ($preferredbehaviour) {
                case 'interactive':
                case 'adaptive':
                    // these behaviours use penalty for wrong responses
                    // => do not allow too many tries
                    $this->nopenalty = false;
                    break;
                case 'adaptivenopenalty':
                    $this->nopenalty = true;
                    break;
                case 'deferredfeedback':
                case 'deferredcbm':
                    $this->nopenalty = true;
                    $this->showsubmit = false;
                    break;
                default:
                    // ???
                    $this->nopenalty = true;
                    break;
            }
        }

    }

    // TODO??
    public function is_compatible_question(question_definition $question) {
        return $question instanceof qtype_proforma_question;
    }

    private function get_result_from_grader($response, question_attempt_pending_step $pendingstep) {
        // $response = $pendingstep->get_qt_data();

        $gradedata = $this->question->grade_response($response);
        if (count($gradedata) > 2) {
            foreach ($gradedata[2] as $name => $value) {
                $pendingstep->set_qt_var($name, $value);
            }
        }
        return $gradedata;
    }

    // we set fraction to null:
    // scenario to handle:
    // - right answer with fraction 1
    // - new answer => unknown result
    public function process_save(question_attempt_pending_step $pendingstep) {
        if ($this->isdeferred()) {
            $status = question_behaviour_with_save::process_save($pendingstep);

        } else {
            $status = parent::process_save($pendingstep);
        }

        // + set fraction to null because we need new grading
        // + in case of a new response
        $pendingstep->set_fraction(null);
        return $status;
    }

    // submit means pressing 'check' in form (or implicit submit)
    public function process_submit(question_attempt_pending_step $pendingstep, $compile = false) {
        $status = $this->process_save($pendingstep);

        $response = $pendingstep->get_qt_data();
        if (!$this->question->is_complete_response($response)) {
            $pendingstep->set_state(question_state::$invalid);
            if ($this->qa->get_state() != question_state::$invalid) {
                $status = question_attempt::KEEP;
            }
            return $status;
        }

        $prevstep = $this->qa->get_last_step_with_behaviour_var('_try');
        $prevresponse = $prevstep->get_qt_data();
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $pendingstep->get_fraction();
        /*
        ORIGINAL should be removed:
                if (is_null($prevbest)) {
                    $prevbest = 0;
                }
        => do not set grade to 0 if there is no actual grading available!!
        */

        if ($this->question->is_same_response($response, $prevresponse)) {
            return question_attempt::DISCARD;
        }

        // - ORIGINAL: list($fraction, $state) = $this->question->grade_response($response);
        $gradedata = $this->get_result_from_grader($response, $pendingstep); // +
        list($fraction, $state) = $gradedata; // +

        if ($state == question_state::$invalid || $state == question_state::$needsgrading) { // +
            // special handling for invalid state (normally caused by technical
            // problems in grader =>
            // - do not switch to todo state
            // - do not count as try! => no penalty for invalid submissions/technical problems
            $pendingstep->set_state(question_state::$invalid); // +
            if (is_null($fraction)) {// +
                $pendingstep->set_fraction($fraction);
            } // +
            // - keep step
            return question_attempt::KEEP; // +
        } // +

        // do not convert null to 0, keep null
        if (!is_null($prevbest) && !is_null($fraction)) { // +
            $pendingstep->set_fraction(max($prevbest, $this->adjusted_fraction($fraction, $prevtries)));
        } else {// +
            $pendingstep->set_fraction($this->adjusted_fraction($fraction, $prevtries));
        } // +

        if ($prevstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$complete);
        } else if ($state == question_state::$gradedright) {
            $pendingstep->set_state(question_state::$complete);
        } else {
            $pendingstep->set_state(question_state::$todo);
        }
        $pendingstep->set_behaviour_var('_try', $prevtries + 1);
        $pendingstep->set_behaviour_var('_rawfraction', $fraction);
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $this->qa->get_fraction();
        /*
        // ORIGINAL should be removed:
        //        if (is_null($prevbest)) {
        //            $prevbest = 0;
        //        }
        // => do not set grade to 0 if there is no actual grading available!!
        */

        $laststep = $this->qa->get_last_step();
        // ++
        // we want to avoid expensive regrading
        $nograding = false;
        switch ($laststep->get_state()) {
            case question_state::$invalid:
            case question_state::$needsgrading:
                break;
            // case question_state::$todo: // might be converted from gradedwrong
            default:
                $nograding = $laststep->has_behaviour_var('_rawfraction');
                break;
        }

        $response = $laststep->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $state = question_state::$gaveup;
            $fraction = 0;
        } else {

            if ($laststep->has_behaviour_var('_try')) {
                // Last answer was graded, we want to regrade it. Otherwise the answer
                // has changed, and we are grading a new try.
                $prevtries -= 1;
            }

            // ++
            if ($nograding) {
                // generate state and fraction from last step values
                $fraction = $laststep->get_behaviour_var('_rawfraction');
                $state = $laststep->get_state();
                if ($state == question_state::$complete && $fraction == 1.0) {
                    $state = question_state::$gradedright;
                }
                // fall through!
                if ($state == question_state::$todo || $state == question_state::$complete) {
                    if ($fraction == 0.0) {
                        $state = question_state::$gradedwrong;
                    } else if ($fraction != null) {
                        $state = question_state::$gradedpartial;
                    }
                }

            } else {
                // ORIGINAL: list($fraction, $state) = $this->question->grade_response($response);
                list($fraction, $state) = $this->get_result_from_grader($response, $pendingstep); // +
            }

            $pendingstep->set_behaviour_var('_try', $prevtries + 1);
            $pendingstep->set_behaviour_var('_rawfraction', $fraction);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }

        $pendingstep->set_state($state);
        if (!is_null($prevbest) && !is_null($fraction)) { // +
            $pendingstep->set_fraction(max($prevbest, $this->adjusted_fraction($fraction, $prevtries)));
        } else {// +
            $pendingstep->set_fraction($this->adjusted_fraction($fraction, $prevtries));
        } // +

        return question_attempt::KEEP;
    }

    // +
    protected function adjusted_fraction($fraction, $prevtries) {
        if ($this->nopenalty === -1) {
            debugging("nopenalty is not initialised", DEBUG_DEVELOPER);
            $this->nopenalty = false;
        } else {
            if ($this->nopenalty === -2) {
                debugging("nopenalty is undefined", DEBUG_DEVELOPER);
                $this->nopenalty = false;
            }
        }
        // throw new coding_exception("nopenalty is not set");

        if ($this->nopenalty) {
            return $fraction;
        } else {
            if (!is_null($fraction)) {
                // + if adjusted fraction is negative than it is set to 0
                return max(0, parent::adjusted_fraction($fraction, $prevtries));
            } else {
                // ???
                return $fraction;
            }
        }
    }

    // +
    public function get_state_string($showcorrectness) {
        // Modify behaviour for try:
        $laststep = $this->qa->get_last_step();
        if ($laststep->has_behaviour_var('_try')) {
            if (is_null($laststep->get_behaviour_var('_rawfraction'))) {
                // special handling for internal (grading) error <=> fraction = null
                // => keep state, do not convert
                $state = $laststep->get_state();
                return $state->default_string(true);
            }
            return parent::get_state_string($showcorrectness);
        }

        if ($this->isdeferred()) {
            // Special treatment for deferred behaviour scenarios:
            // In case of no input the state string from
            // the deferred behaviour shall be used to be compliant with other question types.
            $state = $this->qa->get_state();
            if ($state == question_state::$todo) {
                return question_behaviour_with_save::get_state_string($showcorrectness);
            }
        }
        return parent::get_state_string($showcorrectness);
    }

    /**
     * returns true if the preferred behaviour is a deferred one so that
     * the grader need not to be executed as soon as possible.
     * @return bool
     */
    protected function isdeferred() : bool {
        if (!isset($this->preferred)) {
            return false;
        }
        switch ($this->preferred) {
            case 'deferredfeedback':
            case 'deferredcbm':
                return true;
        }
        return false;
    }
}
