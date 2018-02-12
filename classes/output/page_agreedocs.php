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
 * Provides {@link tool_policy\output\renderer} class.
 *
 * @package     tool_policy
 * @category    output
 * @copyright   2018 Sara Arjona <sara@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_policy\output;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_user;
use html_writer;
use moodle_url;
use renderable;
use renderer_base;
use single_button;
use templatable;
use tool_policy\api;

/**
 * Represents a page for showing all the policy documents which an user has to agree to.
 *
 * @copyright 2018 Sara Arjona <sara@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_agreedocs implements renderable, templatable {

    /** @var array $policies List of public policies objects with information about the user acceptance. */
    protected $policies = null;

    /** @var array $agreedocs List of policy identifiers which the user has agreed using the form. */
    protected $agreedocs = null;

    /** @var int User id who wants to accept this page. */
    protected $behalfid = null;

    /** @var object User who wants to accept this page. */
    protected $behalfuser = null;

    /**
     * Prepare the page for rendering.
     *
     * @param array $agreedocs Array with the policy identifiers which the user has agreed using the form.
     * @param int $behalfid The userid to accept the policy versions as (such as child's id).
     */
    public function __construct($agreedocs = null, $behalfid = 0) {
        global $USER;

        $this->agreedocs = $agreedocs;
        if (empty($this->agreedocs)) {
            $this->agreedocs = [];
        }

        $this->behalfid = $behalfid;
        if (empty($this->behalfid)) {
            $this->behalfid = $USER->id;
        }

        if ($USER->id != $this->behalfid) {
            $this->behalfuser = core_user::get_user($this->behalfid, '*');
            // If behalf user doesn't exist, behalfid parameter will be ignored.
            if ($this->behalfuser === false) {
                $this->behalfid = 0;
            }
        }

        $this->policies = api::list_policies(null, true, api::AUDIENCE_LOGGEDIN);

        $this->accept_and_revoke_policies();
        $this->prepare_global_page_access();
        $this->prepare_user_acceptances();
    }

    /**
     * Accept and revoke the policy versions.
     *
     */
    protected function accept_and_revoke_policies() {
        global $USER, $SESSION;

        // TODO: Make sure the user has the right capabilities for accepting/revoking these policies.

        // TODO: Decide what to do if there are no policies to agree but the user has policyagreed = 0.
        if (!empty($this->agreedocs) && confirm_sesskey()) {
            if (!empty($USER->id)) {
                // Existing user.
                $lang = current_language();
                // Accept / revoke policies.
                $acceptversionids = array();
                foreach ($this->policies as $policy) {
                    if (in_array($policy->currentversionid, $this->agreedocs)) {
                        // Save policy version doc to accept it.
                        $acceptversionids[] = $policy->currentversionid;
                    } else {
                        // TODO: Revoke policy doc.
                        //api::revoke_acceptance($policy->currentversionid, $userid);
                    }
                }
                // Accept all policy docs saved in $acceptversionids.
                api::accept_policies($acceptversionids, $this->behalfid, null, $lang);
            } else {
                // New user.
                // If the user has accepted all the policies, add this to the SESSION to let continue with the signup process.
                $currentpolicyversionids = [];
                foreach ($this->policies as $policy) {
                    $currentpolicyversionids[] = $policy->currentversionid;
                }
                $SESSION->tool_policy->userpolicyagreed = empty(array_diff($currentpolicyversionids, $this->agreedocs));

                // TODO: Show a message to let know the user he/she must agree all the policies if he/she wants to create an user.
            }
        }
    }

    /**
     * Before display the consent page, the user has to view all the still-non-accepted policy docs.
     * This function checks if the non-accepted policy docs have been shown and redirect to them.
     *
     * @param array $userid User identifier who wants to access to the consent page.
     * @param array $policies List of policies. If it's null, all the policies with a current version will be used.
     * @param url $returnurl URL to return after shown the policy docs.
     */
    protected function redirect_to_policies($userid, $policies = null, $returnurl = null) {
        global $SESSION;

        if (empty($policies)) {
            $policies = \tool_policy\api::list_policies(null, true, \tool_policy\api::AUDIENCE_LOGGEDIN);
        }
        $lang = current_language();
        $acceptances = \tool_policy\api::get_user_acceptances($userid);
        if (!empty($userid)) {
            foreach($policies as $policy) {
                if (\tool_policy\api::is_user_version_accepted($userid, $policy->currentversionid, $acceptances)) {
                    // If this version is accepted by the user, remove from the pending policies list.
                    unset($policies[$policy->id]);
                }
            }
        }

        if (!empty($policies)) {
            $currentpolicyversionids = [];
            foreach ($policies as $policy) {
                $currentpolicyversionids[] = $policy->currentversionid;
            }
            if (!empty($SESSION->tool_policy->viewedpolicies)) {
                // Get the list of the policies docs which the user haven't viewed during this session.
                $pendingpolicies = array_diff($currentpolicyversionids, $SESSION->tool_policy->viewedpolicies);
            } else {
                $pendingpolicies = $currentpolicyversionids;
            }
            if (sizeof($pendingpolicies) > 0) {
                // Still is needed to show some policies docs. Save in the session and redirect.
                $policyversionid = array_pop($pendingpolicies);
                $SESSION->tool_policy->viewedpolicies[] = $policyversionid;
                if (empty($returnurl)) {
                    $returnurl = new moodle_url('/admin/tool/policy/index.php');
                }
                $urlparams = ['versionid' => $policyversionid,
                              'returnurl' => $returnurl,
                              'numpolicy' => sizeof($currentpolicyversionids) - sizeof($pendingpolicies),
                              'totalpolicies' => sizeof($currentpolicyversionids),
                ];
                redirect(new moodle_url('/admin/tool/policy/view.php', $urlparams));
            }
        }
    }

    /**
     * Redirect to $SESSION->wantsurl if defined or to $CFG->wwwroot if not.
     */
    protected function redirect_to_previous_url() {
        global $SESSION, $CFG;

        if (!empty($SESSION->wantsurl)) {
            $returnurl = $SESSION->wantsurl;
            unset($SESSION->wantsurl);
        } else {
            $returnurl = $CFG->wwwroot.'/';
        }

        redirect($returnurl);
    }

    /**
     * Sets up the global $PAGE and performs the access checks.
     */
    protected function prepare_global_page_access() {
        global $CFG, $PAGE, $SESSION, $SITE, $USER;

        // Guest users or not logged users (but the users during the signup process) are not allowed to access to this page.
        $newsignupuser = !empty($SESSION->wantsurl) && $SESSION->wantsurl->compare(new moodle_url('/login/signup.php'), URL_MATCH_BASE);
        if (isguestuser() || (empty($USER->id) && !$newsignupuser)) {
            $this->redirect_to_previous_url();
        }

        // Check for correct user capabilities.
        if (!empty($USER->id)) {
            // For existing users, it's needed to check if they have the capability for accepting policies.
            if (empty($this->behalfid) || $this->behalfid == $USER->id) {
                require_capability('tool/policy:accept', context_system::instance());
            } else {
                $usercontext = \context_user::instance($this->behalfid);
                require_capability('tool/policy:acceptbehalf', $usercontext);
            }
        } else {
            // For new users, the behalfid parameter is ignored.
            if ($this->behalfid != $USER->id) {
                redirect(new moodle_url('/admin/tool/policy/index.php'));
            }
        }

        // If the current user has the $USER->policyagreed = 1 or $SESSION->tool_policy->userpolicyagreed = 1, redirect to the return page.
        $hasagreedsignupuser = empty($USER->id) && !empty($SESSION->tool_policy->userpolicyagreed);
        $hasagreedloggeduser = $USER->id == $this->behalfid && !empty($USER->policyagreed);
        // TODO: Redirect only if $SESSION->wantsurl is set (to let users to access to this page after from his/her profile) ?
        if (!is_siteadmin() && ($hasagreedsignupuser || $hasagreedloggeduser)) {
            $this->redirect_to_previous_url();
        }

        $myparams = [];
        if (!empty($USER->id) && !empty($this->behalfid) && $this->behalfid != $USER->id) {
            $myparams['userid'] = $this->behalfid;
        }
        $myurl = new moodle_url('/admin/tool/policy/index.php', $myparams);

        // Redirect to policy docs before the consent page.
        $this->redirect_to_policies($this->behalfid, $this->policies, $myurl);

        // Page setup.
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_url($myurl);
        $PAGE->set_heading($SITE->fullname);
        $PAGE->set_title(get_string('policiesagreements', 'tool_policy'));
        $PAGE->navbar->add(get_string('policiesagreements', 'tool_policy'), new moodle_url('/admin/tool/policy/index.php'));
    }

    /**
     * Prepare user acceptances.
     */
    protected function prepare_user_acceptances() {
        // Get all the policy version acceptances for this user.
        $acceptances = api::get_user_acceptances($this->behalfid);
        $lang = current_language();
        foreach ($this->policies as $policy) {
            // Get only current version object. Remove the other versions from the object because at this point they aren't needed.
            $this->policies[$policy->id]->currentversion = api::get_policy_version($policy->id, $policy->currentversionid);
            unset($this->policies[$policy->id]->versions);

            // Get a link to display the full policy document.
            $policy->url = new moodle_url('/admin/tool/policy/view.php', array('policyid' => $policy->id, 'returnurl' => qualified_me()));
            $policyattributes = array('data-action' => 'view',
                                      'data-versionid' => $policy->currentversionid,
                                      'data-behalfid' => $this->behalfid);
            $policymodal = html_writer::link($policy->url, $policy->name, $policyattributes);

            // Check if this policy version has been agreed or not.
            if (!empty($this->behalfid)) {
                // Existing user.
                $versionagreed = false;
                $this->policies[$policy->id]->versionacceptance = api::get_user_version_acceptance($this->behalfid, $policy->currentversionid, $acceptances);
                if (!empty($this->policies[$policy->id]->versionacceptance)) {
                    // The policy version has ever been agreed. Check if status = 1 to know if still is accepted.
                    $versionagreed = $this->policies[$policy->id]->versionacceptance->status;
                    if ($this->policies[$policy->id]->versionacceptance->lang != $lang) {
                        // Add a message because this version has been accepted in a different language than the current one.
                        $this->policies[$policy->id]->versionlangsagreed = get_string('policyversionacceptedinotherlang', 'tool_policy');
                    }
                    if ($this->policies[$policy->id]->versionacceptance->usermodified != $this->behalfid) {
                        // Add a message because this version has been accepted in behalf of current user.
                        $this->policies[$policy->id]->versionbehalfsagreed = get_string('policyversionacceptedinbehalf', 'tool_policy');
                    }
                }
            } else {
                // New user.
                $versionagreed = in_array($policy->id, $this->agreedocs);
            }
            $this->policies[$policy->id]->versionagreed = $versionagreed;
            $this->policies[$policy->id]->policylink = html_writer::link($policy->url, $policy->name);
            $this->policies[$policy->id]->policymodal = $policymodal;
        }
    }

    /**
     * Export the page data for the mustache template.
     *
     * @param renderer_base $output renderer to be used to render the page elements.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $USER;

        $myparams = [];
        if (!empty($USER->id) && !empty($this->behalfid) && $this->behalfid != $USER->id) {
            $myparams['userid'] = $this->behalfid;
        }
        $data = (object) [
            'pluginbaseurl' => (new moodle_url('/admin/tool/policy'))->out(false),
            'myurl' => (new moodle_url('/admin/tool/policy/index.php', $myparams))->out(false),
            'sesskey' => sesskey(),
        ];

        $data->policies = array_values($this->policies);
        $data->privacyofficer = get_config('tool_policy', 'privacyofficer');

        // If viewing docs in behalf of other user, get his/her full name and profile link.
        if (!empty($this->behalfuser)) {
            $userfullname = fullname($this->behalfuser, has_capability('moodle/site:viewfullnames', \context_system::instance()) ||
                        has_capability('moodle/site:viewfullnames', \context_user::instance($this->behalfid)));
            $data->behalfuser = html_writer::link(\context_user::instance($this->behalfid)->get_url(), $userfullname);
        }

        return $data;
    }

}