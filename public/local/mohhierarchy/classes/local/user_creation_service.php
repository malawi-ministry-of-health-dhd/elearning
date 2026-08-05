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

namespace local_mohhierarchy\local;

use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\permission_service;

/**
 * Strict application service for delegated Moodle account creation.
 *
 * Only an explicit allow-list of account properties reaches core's user API. The submitted zone
 * and district are treated as consistency assertions; the canonical assignment still derives them
 * from the selected facility. Core account insertion and the plugin assignment share one delegated
 * database transaction, and the standard user-created event is emitted only after commit.
 *
 * External authentication plugins are allowed as account identifiers, but this service never calls
 * an external password operation. Such operations cannot participate in the database transaction;
 * external-auth accounts therefore receive AUTH_PASSWORD_NOT_CACHED and must already be provisioned
 * according to that authentication system's own process.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_creation_service {
    /** @var string Lock namespace. */
    protected const LOCK_TYPE = 'local_mohhierarchy';

    /** @var string Serialises strict creation so username and email checks cannot race. */
    protected const LOCK_RESOURCE = 'createuser';

    /** @var permission_service Hierarchy authorisation. */
    protected permission_service $permissions;

    /** @var assignment_service Canonical assignment writer. */
    protected assignment_service $assignments;

    /** @var facility_repository Resolves submitted hierarchy ids. */
    protected facility_repository $facilities;

    /** @var \core\lock\lock_factory Serialises creation of the same username. */
    protected \core\lock\lock_factory $lockfactory;

    /**
     * Constructor.
     *
     * @param permission_service|null $permissions Injectable for tests.
     * @param assignment_service|null $assignments Injectable for tests.
     * @param facility_repository|null $facilities Injectable for tests.
     * @param \core\lock\lock_factory|null $lockfactory Injectable for tests.
     */
    public function __construct(
        ?permission_service $permissions = null,
        ?assignment_service $assignments = null,
        ?facility_repository $facilities = null,
        ?\core\lock\lock_factory $lockfactory = null,
    ) {
        $this->permissions = $permissions ?? new permission_service();
        $this->assignments = $assignments ?? new assignment_service();
        $this->facilities = $facilities ?? new facility_repository();
        $this->lockfactory = $lockfactory ?? \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
    }

    /**
     * Enabled authentication methods that may identify a delegated account.
     *
     * @return string[] Plugin name => formatted display name.
     */
    public function authentication_options(): array {
        $options = [];
        foreach (get_enabled_auth_plugins() as $auth) {
            if (!exists_auth_plugin($auth)) {
                continue;
            }
            $options[$auth] = get_string('pluginname', 'auth_' . $auth);
        }

        return $options;
    }

    /**
     * Authentication methods for which this page may set a local password.
     *
     * @return string[]
     */
    public function authentication_methods_without_local_password(): array {
        return array_values(array_filter(
            array_keys($this->authentication_options()),
            fn(string $auth): bool => !$this->supports_local_password($auth),
        ));
    }

    /**
     * Validate account, authentication and hierarchy data with Moodle APIs.
     *
     * This method is shared by the Moodle form and create_user(), which repeats it after acquiring
     * the username lock. Keys match form element names.
     *
     * @param array|\stdClass $submitted Submitted form values.
     * @param int $actorid The delegated creator.
     * @return string[] Validation errors keyed by element name.
     */
    public function validate_account_data(array|\stdClass $submitted, int $actorid): array {
        global $CFG, $DB;

        $data = (object) $submitted;
        $errors = [];
        $systemcontext = \context_system::instance();
        if (
            $actorid <= 0
            || !has_capability(permission_service::CAP_CREATE_USER, $systemcontext, $actorid)
        ) {
            $errors['facilityid'] = get_string('error:createusernotallowed', 'local_mohhierarchy');
        }

        $username = trim((string) ($data->username ?? ''));
        if ($username === '') {
            $errors['username'] = get_string('required');
        } else if ($username !== \core_text::strtolower($username)) {
            $errors['username'] = get_string('usernamelowercase');
        } else if ($username !== \core_user::clean_field($username, 'username')) {
            $errors['username'] = get_string('invalidusername');
        } else if (
            $DB->record_exists('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            ])
        ) {
            $errors['username'] = get_string('usernameexists');
        }

        foreach (['firstname', 'lastname'] as $namefield) {
            $value = trim((string) ($data->{$namefield} ?? ''));
            if ($value === '') {
                $errors[$namefield] = get_string('required');
            }
        }

        $email = trim((string) ($data->email ?? ''));
        if (!validate_email($email)) {
            $errors['email'] = get_string('invalidemail');
        } else if (empty($CFG->allowaccountssameemail)) {
            $select = $DB->sql_equal('email', ':email', false) . ' AND mnethostid = :mnethostid';
            if (
                $DB->record_exists_select('user', $select, [
                'email' => $email,
                'mnethostid' => $CFG->mnet_localhost_id,
                ])
            ) {
                $errors['email'] = get_string('emailexists');
            }
        }

        $auth = (string) ($data->auth ?? '');
        if (!array_key_exists($auth, $this->authentication_options())) {
            $errors['auth'] = get_string('error:authnotallowed', 'local_mohhierarchy');
        } else {
            $createpassword = !empty($data->createpassword);
            $password = (string) ($data->newpassword ?? '');
            if ($this->supports_local_password($auth)) {
                if ($createpassword && $password !== '') {
                    $errors['newpassword'] = get_string('error:choosepasswordmode', 'local_mohhierarchy');
                } else if (!$createpassword && $password === '') {
                    $errors['newpassword'] = get_string('required');
                } else if ($password !== '') {
                    $passworderror = '';
                    $passworduser = (object) [
                        'username' => $username,
                        'firstname' => (string) ($data->firstname ?? ''),
                        'lastname' => (string) ($data->lastname ?? ''),
                        'email' => $email,
                    ];
                    if (!check_password_policy($password, $passworderror, $passworduser)) {
                        $errors['newpassword'] = $passworderror;
                    }
                }
            } else if ($createpassword || $password !== '') {
                $errors['newpassword'] = get_string('error:externalauthpassword', 'local_mohhierarchy');
            }
        }

        $facilityid = (int) ($data->facilityid ?? 0);
        $facility = $facilityid > 0 ? $this->facilities->get_with_ancestors($facilityid) : null;
        if ($facility === null) {
            $errors['facilityid'] = get_string('error:facilityrequired', 'local_mohhierarchy');
        } else {
            if (
                (int) ($data->zoneid ?? 0) !== (int) $facility->zoneid
                || (int) ($data->districtid ?? 0) !== (int) $facility->districtid
            ) {
                $errors['facilityid'] = get_string('error:hierarchymismatch', 'local_mohhierarchy');
            } else if (!$this->permissions->can_create_user_in_facility($actorid, $facilityid)) {
                $errors['facilityid'] = get_string('error:facilitynotallowed', 'local_mohhierarchy');
            }
        }

        if (
            property_exists($data, 'scopelevel')
            && (string) $data->scopelevel !== scope_level::NONE->value
        ) {
            $errors['facilityid'] = get_string('error:createscopeforbidden', 'local_mohhierarchy');
        }

        return $errors;
    }

    /**
     * Create a Moodle account and its scope-none hierarchy assignment.
     *
     * @param array|\stdClass $submitted Validated form values.
     * @param int $actorid The delegated creator.
     * @return \stdClass Result with userid, username, fullname and passwordemailed.
     */
    public function create_user(array|\stdClass $submitted, int $actorid): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');

        $data = (object) $submitted;
        $username = trim((string) ($data->username ?? ''));
        $lock = $this->lockfactory->get_lock(self::LOCK_RESOURCE, 5);
        if (!$lock) {
            throw new \moodle_exception('error:usercreationlocked', 'local_mohhierarchy');
        }

        try {
            $errors = $this->validate_account_data($data, $actorid);
            if ($errors !== []) {
                throw new \moodle_exception(
                    'error:usercreationinvalid',
                    'local_mohhierarchy',
                    '',
                    reset($errors),
                );
            }

            $auth = (string) $data->auth;
            $createpassword = !empty($data->createpassword);
            $user = (object) [
                'username' => $username,
                'firstname' => \core_user::clean_field(trim((string) $data->firstname), 'firstname'),
                'lastname' => \core_user::clean_field(trim((string) $data->lastname), 'lastname'),
                'email' => \core_user::clean_field(trim((string) $data->email), 'email'),
                'auth' => $auth,
                'confirmed' => 1,
                'deleted' => 0,
                'suspended' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
                'timezone' => '99',
            ];

            $updatelocalpassword = false;
            if ($this->supports_local_password($auth)) {
                if ($createpassword) {
                    $user->password = '';
                } else {
                    // Moodle's user_create_user() delegates this plaintext value only to an explicitly
                    // verified local-password auth plugin inside the database transaction.
                    $user->password = (string) $data->newpassword;
                    $updatelocalpassword = true;
                }
            } else {
                // Never call an external auth system from this transactional workflow.
                $user->password = AUTH_PASSWORD_NOT_CACHED;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                // Recheck under the creation lock immediately before either write.
                if (!$this->permissions->can_create_user_in_facility($actorid, (int) $data->facilityid)) {
                    throw new \moodle_exception('error:facilitynotallowed', 'local_mohhierarchy');
                }
                $userid = user_create_user($user, $updatelocalpassword, false);
                $this->assignments->assign_user(
                    $userid,
                    (int) $data->facilityid,
                    scope_level::NONE,
                    $actorid,
                    assign_source::USERFORM,
                    get_string('delegatedcreationaudit', 'local_mohhierarchy'),
                );
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            $created = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            \core\event\user_created::create_from_userid($userid)->trigger();

            $passwordemailed = null;
            if ($createpassword) {
                // Password generation and email delivery happen after commit. Email is inherently
                // non-transactional; a false result is returned to the page as a clear warning.
                $passwordemailed = (bool) setnew_password_and_mail($created);
                set_user_preference('auth_forcepasswordchange', 1, $created);
            }

            return (object) [
                'userid' => $userid,
                'username' => $created->username,
                'fullname' => fullname($created),
                'passwordemailed' => $passwordemailed,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether an auth plugin can safely update a local password inside the DB transaction.
     *
     * @param string $auth Authentication plugin name.
     * @return bool
     */
    protected function supports_local_password(string $auth): bool {
        if (!array_key_exists($auth, $this->authentication_options())) {
            return false;
        }
        $plugin = get_auth_plugin($auth);

        return $plugin->is_internal()
            && $plugin->can_change_password()
            && empty($plugin->change_password_url())
            && !$plugin->prevent_local_passwords();
    }
}
