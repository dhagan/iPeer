<?php
/**
 * V1Controller
 *
 * @uses Controller
 * @package   CTLT.iPeer
 * @author    Pan Luo <pan.luo@ubc.ca>
 * @copyright 2012 All rights reserved.
 * @license   MIT {@link http://www.opensource.org/licenses/MIT}
 */
class V1Controller extends Controller {

    public $name = 'V1';
    public $uses = array('User', 'RolesUser',
        'Group', 'Course', 'Event', 'EvaluationSimple', 'EvaluationRubric',
        'EvaluationMixeval', 'OauthClient', 'OauthNonce', 'OauthToken',
        'GroupsMembers', 'GroupEvent', 'Department', 'Role', 'CourseDepartment',
        'UserCourse', 'UserTutor', 'UserEnrol', 'Penalty'
    );
    public $helpers = array('Session');
    public $components = array('RequestHandler', 'Session');
    public $layout = "blank_layout";

    /**
     * oauth function for use in test?
     */
    public function oauth() {
    }

    /**
     * Checks to see if required parameters are present?
     *
     * @return bool - false if something missing
     */
    private function _checkRequiredParams() {
        if (!isset($_REQUEST['oauth_consumer_key'])) {
            $this->set('oauthError', "Parameter Absent: oauth_consumer_key");
            $this->render('oauth_error');
            return false;
        }
        if (!isset($_REQUEST['oauth_token'])) {
            $this->set('oauthError', "Parameter Absent: oauth_token");
            $this->render('oauth_error');
            return false;
        }
        if (!isset($_REQUEST['oauth_signature_method'])) {
            $this->set('oauthError', "Parameter Absent: oauth_signature_method");
            $this->render('oauth_error');
            return false;
        }
        if (!isset($_REQUEST['oauth_timestamp'])) {
            $this->set('oauthError', "Parameter Absent: oauth_timestamp");
            $this->render('oauth_error');
            return false;
        }
        if (!isset($_REQUEST['oauth_nonce'])) {
            $this->set('oauthError', "Parameter Absent: oauth_nonce");
            $this->render('oauth_error');
            return false;
        }
        // oauth_version is optional, but must be set to 1.0
        if (isset($_REQUEST['oauth_version']) &&
            $_REQUEST['oauth_version'] != "1.0"
        ) {
            $this->set('oauthError',
                "Parameter Rejected: oauth_version 1.0 only");
            $this->render('oauth_error');
            return false;
        }
        if ($_REQUEST['oauth_signature_method'] != "HMAC-SHA1") {
            $this->set('oauthError',
                "Parameter Rejected: Only HMAC-SHA1 signatures supported.");
            $this->render('oauth_error');
            return false;
        }
        return true;
    }

    /**
     * Recalculate the oauth signature and check it against the given signature
     * to make sure that they match.
     *
     * @return bool - true if signatures match
     */
    private function _checkSignature() {
        // Calculate the signature, note, going to assume that all incoming
        // parameters are already UTF-8 encoded since it'll be impossible
        // to convert encodings blindly
        $tmp = $_REQUEST;
        unset($tmp['oauth_signature']);
        unset($tmp['url']); // can ignore, mod_rewrite added, not sent by client
        // percent-encode the keys and values
        foreach ($tmp as $key => $val) {
            // change the value
            $val = rawurlencode($val);
            $tmp[$key] = $val;
            // change the key if needed
            $encodedKey = rawurlencode($key);
            if ($encodedKey != $key) {
                $tmp[$encodedKey] = $val;
                unset($tmp[$key]);
            }
        }
        // sort by keys into byte order, technically should have another
        // layer that sorts by value if keys are equal, but that shouldn't
        // happen with our api
        ksort($tmp);
        // construct the data string used in hmac calculation
        $params = "";
        foreach ($tmp as $key => $val) {
           $params .= $key . "=" . $val . "&";
        }
        $params = substr($params, 0, -1);
        $reqType = "GET";
        if ($this->RequestHandler->isPost()) {
            $reqType = "POST";
        } else if ($this->RequestHandler->isPut()) {
            $reqType = "PUT";
        } else if ($this->RequestHandler->isDelete()) {
            $reqType = "DELETE";
        }
        $params = "$reqType&" . rawurlencode(Router::url($this->here, true))
            . "&" . rawurlencode($params);
        // construct the key used for hmac calculation
        $clientSecret = $this->_getClientSecret($_REQUEST['oauth_consumer_key']);
        if (is_null($clientSecret)) {
            $this->set('oauthError', "Invalid Client");
            $this->render('oauth_error');
            return false;
        }
        $clientSecret = rawurlencode($clientSecret);
        $tokenSecret = $this->OauthToken->getTokenSecret($_REQUEST['oauth_token']);
        if (is_null($tokenSecret)) {
            $this->set('oauthError', "Invalid Token");
            $this->render('oauth_error');
            return false;
        }
        $tokenSecret = rawurlencode($tokenSecret);
        $secrets = $clientSecret . "&" . $tokenSecret;

        // get the binary result of the hmac calculation
        $hmac = hash_hmac('sha1', $params, $secrets, true);
        // need to encode it in base64
        $expected = base64_encode($hmac);
        // check to see if we got the signature we expected
        $actual = $_REQUEST['oauth_signature'];
        if ($expected != $actual) {
            $this->set('oauthError', "Invalid Signature");
            $this->render('oauth_error');
            return false;
        }
        return true;
    }

    /**
     * Confirm that the nonce is valid. The nonce is valid if we have never
     * seen that nonce used before. Since we can't store every single nonce
     * ever used in a request, we limit the nonce storage to only 15 minutes.
     * This necessitates checking that the timestamp given by the client is
     * relatively similar to the server's. If a request comes in that is beyond
     * our 15 minute time frame for nonce storage, we can't be sure that the
     * nonce hasn't been used before.
     *
     * @return bool - true/false depending on nonce validity
     */
    private function _checkNonce() {
        // timestamp must be this many seconds within server time
        $validTimeWindow = 15 * 60; // 15 minutes
        $now = time();
        $then = $_REQUEST['oauth_timestamp'];
        $diff = abs($now - $then);
        // we should reject timestamps that are 15 minutes off from ours
        if ($diff > $validTimeWindow) {
            // more than 15 minutes of difference between the two times
            $this->set('oauthError', "Timestamp Refused");
            $this->render('oauth_error');
            return false;
        }

        // delete nonces that we don't need to keep anymore.
        // Note that we do this before checking for nonce uniqueness since
        // we assume that all stored nonces are not expired. There is an edge
        // case where if a request reuses a nonce immediately after it expires,
        // we would reject the nonce since it hasn't been removed from the db.
        $this->OauthNonce->deleteAll(
            array('expires <' => date("Y-m-d H:i:s", $now - $validTimeWindow)));

        // check nonce uniqueness
        $nonce = $_REQUEST['oauth_nonce'];
        $ret = $this->OauthNonce->findByNonce($nonce);
        if ($ret) {
            // we've seen this nonce already
            $this->set('oauthError', "Nonce Used");
            $this->render('oauth_error');
            return false;
        } else {
            // store nonce we haven't encountered before
            $this->OauthNonce->save(
                array(
                    'nonce' => $nonce,
                    'expires' => date("Y-m-d H:i:s", $now + $validTimeWindow)
                )
            );
        }

        return true;
    }

    /**
     * Retrieve the client credential secret based on the key. The client
     * credential identifies the client program acting on behalf of the
            iebug($input);
     * resource owner.
     *
     * @param mixed $key - identifier for the secret.
     *
     * @return The secret if found, null if not.
     */
    private function _getClientSecret($key) {
        $ret = $this->OauthClient->findByKey($key);
        if (!empty($ret)) {
            if ($ret['OauthClient']['enabled']) {
                return $ret['OauthClient']['secret'];
            }
        }
        return null;
    }


    /**
     * beforeFilter
     *
     * @access public
     * @return void
     */
    public function beforeFilter() {
        // return true;
        return $this->_checkRequiredParams() && $this->_checkSignature() &&
            $this->_checkNonce();
    }

    /**
     * Empty controller action for displaying the oauth error page.
     */
    public function oauth_error() {
    }

    /**
     * Get a list of users in iPeer.
     *
     * @param mixed $id
     */
    public function users($id = null) {
        // view
        if ($this->RequestHandler->isGet()) {
            $data = array();
            // all users
            if (null == $id) {
                $users = $this->User->find('all');
                if (!empty($users)) {
                    foreach ($users as $user) {
                        $tmp = array();
                        $tmp['id'] = $user['User']['id'];
                        $tmp['role_id'] = $user['Role']['0']['id'];
                        $tmp['username'] = $user['User']['username'];
                        $tmp['last_name'] = $user['User']['last_name'];
                        $tmp['first_name'] = $user['User']['first_name'];
                        $data[] = $tmp;
                    }
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $statusCode = 'HTTP/1.1 404 Not Found';
                    $data = null;
                }
            // specific user
            } else {
                $user = $this->User->find(
                    'first',
                    array('conditions' => array('User.id' => $id))
                );
                if (!empty($user)) {
                    $data = array(
                        'id' => $user['User']['id'],
                        'role_id' => $user['Role']['0']['id'],
                        'username' => $user['User']['username'],
                        'last_name' => $user['User']['last_name'],
                        'first_name' => $user['User']['first_name']
                    );
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $statusCode = 'HTTP/1.1 404 Not Found';
                    $data = null;
                }
            }
            $this->set('user', $data);
            $this->set('statusCode', $statusCode);
        // add
        } else if ($this->RequestHandler->isPost()) {
            $input = trim(file_get_contents('php://input'), true);
            $decode = json_decode($input, true);
            // adding one user
            if (isset($decode['username'])) {
                $role = array('Role' => array('RolesUser' => array('role_id' => $decode['role_id'])));
                unset($decode['role_id']);
                $user = array('User' => $decode);
                $user = $user + $role;

                // does not save role in RolesUser - need to fix
                if ($this->User->save($user)) {
                    $user = $this->User->read(array('id','username','last_name','first_name'));
                    $role = $this->RolesUser->read('role_id');
                    $combine = $user['User'] + array('role_id' => $role['RolesUser']['role_id']);
                    $statusCode = 'HTTP/1.1 201 Created';
                    $body = $combine;
                } else {
                    $statusCode = 'HTTP/1.1 500 Internal Server Error';
                    $body = null;
                }
            // adding multiple users from import (expected input: array)
            } else if (isset($decode['0'])) {
                $data = array();
                // rearrange the data
                foreach ($decode as $person) {
                    $pRole = array('Role' => array('RolesUser' => array('role_id' => $person['role_id'])));
                    unset($person['role_id']);
                    $pUser = array('User' => $person);
                    $data[] = $pUser + $pRole;
                }
                $sUser = array();
                $uUser = array();
                $result = $this->User->saveAll($data, array('atomic' => false));
                $statusCode = 'HTTP/1.1 500 Internal Server Error';
                foreach ($result as $key => $ret) {
                    if ($ret) {
                        $statusCode = 'HTTP/1.1 201 Created';
                        $sUser[] = $decode[$key]['username'];
                    } else {
                        $temp = array();
                        $temp['username'] = $decode[$key]['username'];
                        $temp['first_name'] = $decode[$key]['first_name'];
                        $temp['last_name'] = $decode[$key]['last_name'];
                        $uUser[] = $temp;
                    }
                }
                $sbody = $this->User->find('all', array(
                        'conditions' => array('username' => $sUser),
                        'fields' => array('User.id', 'username', 'last_name', 'first_name')
                    ));
                foreach ($sbody as $sb) {
                    // at the moment assuming one role per user
                    $body[] = $sb['User'] + array('role_id' => $sb['Role']['0']['id']);
                }

                foreach ($uUser as $check) {
                    $verify = $this->User->find('first', array(
                        'conditions' => array('username' => $check['username'], 'last_name' => $check['last_name'], 'first_name' => $check['first_name']),
                        'fields' => array('User.id', 'username', 'first_name', 'last_name')
                    ));
                    if (!empty($verify)) {
                        $statusCode = 'HTTP/1.1 201 Created';
                        $body[] = $verify['User'] + array('role_id' => $verify['Role']['0']['id']);
                    }
                }
            // incorrect format
            } else {
                $statusCode = 'HTTP/1.1 400 Bad Request';
                $body = null;
            }
            $this->set('statusCode', $statusCode);
            $this->set('user', $body);
        // delete
        } else if ($this->RequestHandler->isDelete()) {
            if ($this->User->delete($id)) {
                $this->set('statusCode', 'HTTP/1.1 204 No Content');
                $this->set('user', null);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('user', null);
            }
        // update
        } else if ($this->RequestHandler->isPut()) {
            $edit = trim(file_get_contents('php://input'), true);
            $decode = json_decode($edit, true);
            // at the moment each user only has one role
            $role = array('Role' => array('RolesUser' => array('role_id' => $decode['role_id'])));
            unset($decode['role_id']);
            $user = array('User' => $decode);
            $user = $user + $role;
            // does not save role in RolesUser - need to fix
            if ($this->User->save($user)) {
                $user = $this->User->read(array('id','username','last_name','first_name'));
                $role = $this->RolesUser->find('first', array('conditions' => array('user_id' => $user['User']['id']), 'fields' => 'role_id'));
                $combine = $user['User'] + array('role_id' => $role['RolesUser']['role_id']);
                $this->set('statusCode', 'HTTP/1.1 200 OK');
                $this->set('user', $combine);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('user', null);
            }
        } else {
            $this->set('statusCode', 'HTTP/1.1 400 Bad Request');
            $this->set('user', null);
        }
    }

    /**
     * Get a list of courses in iPeer.
     *
     * @param mixed $id
     */
    public function courses($id = null) {
        $classes = array();
        if ($this->RequestHandler->isGet()) {
            if (null == $id) {
                $courses = $this->Course->find('all',
                    array('fields' => array('id', 'course', 'title', 'student_count'))
                );
                if (!empty($courses)) {
                    foreach ($courses as $course) {
                        $classes[] = $course['Course'];
                    }
                }
                $statusCode = 'HTTP/1.1 200 OK';
            } else {
                // specific course
                $course = $this->Course->find('first',
                    array(
                        'conditions' => array('id' => $id),
                        'fields' => array('id', 'course', 'title', 'student_count'),
                    )
                );
                if (!empty($course)) {
                    $classes = $course['Course'];
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $classes = array('code' => 2, 'message' => 'Course does not exists.');
                    $statusCode = 'HTTP/1.1 404 Not Found';
                }
            }
            $this->set('courses', $classes);
            $this->set('statusCode', $statusCode);
        } else if ($this->RequestHandler->isPost()) {
            $create = trim(file_get_contents('php://input'), true);
            if (!$this->Course->save(json_decode($create, true))) {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('courses', array('code' => 1, 'message' => 'course already exists.'));
            } else {
                $temp = $this->Course->read(array('id','course','title'));
                $course = $temp['Course'];
                $this->set('statusCode', 'HTTP/1.1 201 Created');
                $this->set('courses', $course);
            }
        } else if ($this->RequestHandler->isPut()) {
            $update = trim(file_get_contents('php://input'), true);
            if (!$this->Course->save(json_decode($update, true))) {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('courses', null);
            } else {
                $temp = $this->Course->read(array('id','course','title'));
                $course = $temp['Course'];
                $this->set('statusCode', 'HTTP/1.1 200 OK');
                $this->set('courses', $course);
            }
        } else if ($this->RequestHandler->isDelete()) {
            if (!$this->Course->delete($id)) {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('courses', null);
            } else {
                $this->set('statusCode', 'HTTP/1.1 204 No Content');
                $this->set('courses', null);
            }
        } else {
            $this->set('statusCode', 'HTTP/1.1 400 Bad Request');
            $this->set('courses', null);
        }
    }

    /**
     * Get a list of groups in iPeer.
     **/
    public function groups() {
        $fields = array('id', 'group_num', 'group_name', 'course_id', 'member_count');
        // view
        if ($this->RequestHandler->isGet()) {
            $data = array();
            if (!isset($this->params['group_id']) || null == $this->params['group_id']) {
                $groups = $this->Group->find(
                    'all',
                    array(
                        'conditions' => array('course_id' => $this->params['course_id']),
                        'fields' => $fields,
                        'recursive' => 0
                    )
                );

                if (!empty($groups)) {
                    foreach ($groups as $group) {
                        $data[] = $group['Group'];
                    }
                } else {
                    $data = array();
                }
                $statusCode = 'HTTP/1.1 200 OK';
            } else {
                $group = $this->Group->find(
                    'first',
                    array(
                        'conditions' => array(
                            'Group.id' => $this->params['group_id'],
                            'course_id' => $this->params['course_id']
                        ),
                        'fields' => $fields,
                        'recursive' => 0
                    )
                );
                if (!empty($group)) {
                    $data = $group['Group'];
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $data = array();
                    $statusCode = 'HTTP/1.1 404 Not Found';
                }
            }
            $this->set('group', $data);
            $this->set('statusCode', $statusCode);
        // add
        } else if ($this->RequestHandler->isPost()) {
            $add = trim(file_get_contents('php://input'), true);
            $decode = array('Group' => json_decode($add, true));
            $decode['Group']['course_id'] = $this->params['course_id'];

            if ($this->Group->save($decode)) {
                $tempGroup = $this->Group->read($fields);
                $group = $tempGroup['Group'];
                $this->set('statusCode', 'HTTP/1.1 201 Created');
                $this->set('group', $group);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('group', null);
            }
        // delete
        } else if ($this->RequestHandler->isDelete()) {
            if ($this->Group->delete($this->params['group_id'])) {
                $this->set('statusCode', 'HTTP/1.1 204 No Content');
                $this->set('group', null);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('group', null);
            }
        // update
        } else if ($this->RequestHandler->isPut()) {
            $edit = trim(file_get_contents('php://input'), true);
            $decode = array('Group' => json_decode($edit, true));
            if ($this->Group->save($decode)) {
                $temp = $this->Group->read($fields);
                $group = $temp['Group'];
                $this->set('statusCode', 'HTTP/1.1 200 OK');
                $this->set('group', $group);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('group', null);
            }
        } else {
            $this->set('statusCode', 'HTTP/1.1 400 Bad Request');
            $this->set('group', null);
        }

    }

    /**
     * get, add, and delete group members from a group
    **/
    public function groupMembers() {
        $status = 'HTTP/1.1 400 Bad Request';
        $groupMembers = array();

        $groupId = $this->params['group_id'];
        $username = $this->params['username'];

        if ($this->RequestHandler->isGet()) {
            // retrieve a list of users in the given group
            $userIds = $this->GroupsMembers->find('list', 
                array(
                    'conditions' => array('group_id' => $groupId),
                    'fields' => array('user_id')
                )
            );
            $users = $this->User->find('all', 
                array('conditions' => array('User.id' => $userIds)));

            foreach ($users as $user) {
                $tmp = array();
                $tmp['id'] = $user['User']['id'];
                $tmp['role_id'] = $user['Role']['0']['id'];
                $tmp['username'] = $user['User']['username'];
                $tmp['last_name'] = $user['User']['last_name'];
                $tmp['first_name'] = $user['User']['first_name'];
                $groupMembers[] = $tmp;
            }

            $status = 'HTTP/1.1 200 OK';
        } else if ($this->RequestHandler->isPost()) {
            // add the list of users to the given group
            $ret = trim(file_get_contents('php://input'), true);
            $users = json_decode($ret, true);
            $status = 'HTTP/1.1 200 OK';
            foreach ($users as $user) {
                $userId = $this->User->field('id', 
                    array('username' => $user['username']));
                $tmp = array('group_id' => $groupId, 'user_id' => $userId);
                // try to add this user to group
                $this->GroupsMembers->create();
                if ($this->GroupsMembers->save($tmp)) {
                    $userId = $this->GroupsMembers->read('user_id');
                    $this->GroupsMembers->id = null;
                    $groupMembers[] = $user;
                } else {
                    $status = 'HTTP/1.1 500 Internal Server Error';
                    break;
                }
            }
        } else if ($this->RequestHandler->isDelete()) {
            // delete a user from the given group
            $userId = $this->User->field('id', array('username' => $username));
            $gmId = $this->GroupsMembers->field('id', 
                array('user_id' => $userId, 'group_id' => $groupId));
            if ($this->GroupsMembers->delete($gmId)) {
                $status = 'HTTP/1.1 204 No Content';
            } else {
                $status = 'HTTP/1.1 500 Internal Server Error';
            }
        } 

        $this->set('statusCode', $status);
        $this->set('groupMembers', $groupMembers);
    }

    /**
     * Get a list of events in iPeer.
     **/
    public function events() {
        $course_id = $this->params['course_id'];
        $results = array();
        $fields = array('title', 'course_id', 'event_template_type_id', 'due_date');

        if ($this->RequestHandler->isGet()) {
            if (!isset($this->params['event_id']) || empty($this->params['event_id'])) {
                $list = $this->Event->find('all', array(
                    'conditions' => array('course_id' => $course_id),
                    'fields' => $fields));

                if (!empty($list)) {
                    foreach ($list as $data) {
                        $results[] = $data['Event'];
                    }
                }
                $statusCode = 'HTTP/1.1 200 OK';
            } else {
                $list = $this->Event->find('first',
                    array('fields' => $fields,
                        'conditions' => array('Event.id' => $this->params['event_id']))
                );

                if (!empty($list)) {
                    $results = $list['Event'];
                }
                $statusCode = 'HTTP/1.1 200 OK';
            }
            $this->set('statusCode', $statusCode);
            $this->set('events', $results);
        } else {
            $this->set('statusCode', 'HTTP/1.1 400 Bad Request');
            $this->set('events', null);
        }
    }

    /**
     * Get a list of grades in iPeer.
     **/
    public function grades() {
        $event_id = $this->params['event_id'];
        $username = $this->params['username']; // if set, only want 1 user
        $user_id = $this->User->field('id',
                array('username' => $this->params['username']));
        $type = $this->Event->getEventTemplateTypeId($event_id);

        // assume failure initially
        $results = array();
        $statusCode = 'HTTP/1.1 400 Bad Request'; // unrecognized request type

        // initialize find parameters
        $fields = array('id', 'evaluatee', 'score');
        $conditions = array('event_id' => $event_id);
        // add additional conditions if they only want 1 user
        if ($user_id) {
            $conditions['evaluatee'] = $user_id;
        }
        $params = array('fields' => $fields, 'conditions' => $conditions);

        if ($this->RequestHandler->isGet()) {
            $res = array();
            $key = ""; // name of the table we're querying
            if ($type == 1) {
                $res = $this->EvaluationSimple->simpleEvalScore($event_id);
                $key = "EvaluationSimple";
            }
            else if ($type == 2) {
                $res = $this->EvaluationRubric->rubricEvalScore($event_id);
                $key = "EvaluationRubric";
            }
            else if ($type == 4) {
                $res = $this->EvaluationMixeval->mixedEvalScore($event_id);
                $key = "EvaluationMixeval";
            }
            foreach ($res as $val) {
                unset($val[$key]['id']);
                $results[] = $val[$key];
            }
            $statusCode = 'HTTP/1.1 200 OK';
        }

        // add in username
        if ($user_id && !empty($results)) {
            // remove from array if they wanted only 1 user
            $results = $results[0];
            $results['username'] = $username;
        } else {
            foreach ($results as &$result) {
                $username = $this->User->field('username',
                    array('id' => $result['evaluatee']));
                $result['username'] = $username;
            }
        }

        $this->set('grades', $results);
        $this->set('statusCode', $statusCode);
    }

    /**
     * Get a list of departments in iPeer
    **/
    public function departments($departmentId = null) {
        if ($this->RequestHandler->isGet()) {
            if (is_null($departmentId)) {
                $departments = array();
                $dps = $this->Department->find('all',
                    array('fields' => array('id', 'name'))
                );
                if (!empty($dps)) {
                    foreach ($dps as $dp) {
                        $departments[] = $dp['Department'];
                    }
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $departments = null;
                    $statusCode = 'HTTP/1.1 404 Not Found';
                }
            } else {
                $courseDepts = $this->CourseDepartment->find('list',
                    array('conditions' => array('department_id' => $departmentId),
                        'fields' => array('course_id')));
                $courses = $this->Course->find('all',
                    array('conditions' => array('Course.id' => $courseDepts),
                        'fields' => array('Course.id', 'course', 'title')));
                if (!empty($courses)) {
                    $departments = array();
                    foreach ($courses as $course) {
                        $departments[] = $course['Course'];
                    }
                    $statusCode = 'HTTP/1.1 200 OK';
                } else {
                    $departments = null;
                    $statusCode = 'HTTP/1.1 404 Not Found';
                }
            }
            $this->set('departments', $departments);
            $this->set('statusCode', $statusCode);
        } else {
            $this->set('departments', null);
            $this->set('statusCode', 'HTTP/1.0 400 Bad Request');
        }
    }

    /**
     * Add or Delete departments in iPeer
    **/
    public function courseDepartments() {
        $department_id = $this->params['department_id'];
        $course_id = $this->params['course_id'];
        //POST: array{'course_id', 'faculty_id'} ; assume 1 for now
        if ($this->RequestHandler->isPost()) {
            if ($this->Course->habtmAdd('Department', $course_id, $department_id)) {
                $this->set('statusCode', 'HTTP/1.1 201 Created');
                $departments = $this->CourseDepartment->find('first',
                    array('conditions' => array('course_id' => $course_id, 'department_id' => $department_id)));
                $departments = $departments['CourseDepartment'];
                unset($departments['id']);
                $this->set('departments', json_encode($departments));
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('departments', null);
            }
        } else if ($this->RequestHandler->isDelete()) {
            if ($this->Course->habtmDelete('Department', $course_id, $department_id)) {
                $this->set('statusCode', 'HTTP:/1.1 204 No Content');
                $this->set('departments', null);
            } else {
                $this->set('statusCode', 'HTTP/1.1 500 Internal Server Error');
                $this->set('departments', null);
            }
        } else {
            $this->set('statusCode', 'HTTP/1.0 400 Bad Request');
            $this->set('departments', null);
        }
    }

    /**
     * retrieving events a user has access to (eg. student)
    **/
    public function userEvents() {
        $username = $this->params['username'];

        if ($this->RequestHandler->isGet()) {
            $user = $this->User->find('first', array('conditions' => array('User.username' => $username)));
            $user_id = $user['User']['id'];

            // find all groups the user is associated with - groupMembers
            $groups = $this->GroupsMembers->find('list', array(
                'conditions' => array('user_id' => $user_id),
                'fields' => array('group_id')
            ));

            // find all groupEvents relating to the above groups - groupEvents
            $eventIds = $this->GroupEvent->find('list', array(
                'conditions' => array('group_id' => $groups),
                'fields' => array('event_id')
            ));

            $eventConditions = array(
                        'Event.id' => $eventIds,
                        'release_date_begin <=' => date('Y-m-d H-i-s', time()),
                        'release_date_end >=' => date('Y-m-d H-i-s', time()));

            if (isset($this->params['course_id'])) {
                $eventConditions = $eventConditions + array('course_id' => $this->params['course_id']);
            }

            // from groupEvents - pickout all events and generate list of valid events - Events
                // after release begin date and before end date
            $evts = $this->Event->find('all', array(
                'conditions' => $eventConditions,
                'fields' => array('title', 'course_id', 'event_template_type_id', 'due_date', 'id')
            ));

            $events = array();
            foreach ($evts as $evt) {
                $events[] = $evt['Event'];
            }
            $this->set('statusCode', 'HTTP/1.1 200 OK');
            $this->set('events', $events);
        } else {
            $this->set('statusCode', 'HTTP/1.1 400 Bad Request');
            $this->set('events', null);
        }

        $this->render('events');
    }

    /**
     * Enrol users in courses
     */
    public function enrolment() {
        $this->set('statusCode', 'HTTP/1.1 400 Unrecognizable Request');
        $this->set('enrolment', null);
        $courseId = $this->params['course_id'];

        // Get request, just return a list of users
        if ($this->RequestHandler->isGet()) {
            $students = $this->User->getEnrolledStudents($courseId);
            $instructors = $this->User->getInstructorsByCourse($courseId);
            $tutors = $this->User->getTutorsByCourse($courseId);

            $ret = array_merge($students, $instructors, $tutors);
            $users = array();
            foreach ($ret as $entry) {
                $user = array(
                    'id' => $entry['User']['id'],
                    'role_id' => $entry['Role']['0']['id'],
                    'username' => $entry['User']['username']
                );
                $users[] = $user;
            }
            $this->set('statusCode', 'HTTP/1.1 200 OK');
            $this->set('enrolment', $users);
            return;
        }
        // Post request, add user to course
        // if user already in course, count as successful
        // if user failed save, stop execution and return an error
        //
        else if ($this->RequestHandler->isPost()) {
            $this->set('statusCode', 'HTTP/1.1 200 OK');
            $input = trim(file_get_contents('php://input'), true);
            $users = json_decode($input, true);

            $students = $this->UserEnrol->find('list', array('conditions' => array('course_id' => $courseId), 'fields' => array('user_id')));
            $tutors = $this->UserTutor->find('list', array('conditions' => array('course_id' => $courseId), 'fields' => array('user_id')));
            $instructors = $this->UserCourse->find('list', array('conditions' => array('course_id' => $courseId), 'fields' => array('user_id')));
            $members = $students + $tutors + $instructors;
            $inClass = $this->User->find('list', array('conditions' => array('User.id' => $members), 'fields' => array('User.username')));

            foreach ($users as $user) {
                if(!in_array($user['username'], $inClass)) {
                    $userId = $this->User->field('id',
                        array('username' => $user['username']));
                    $role = $this->Role->getRoleName($user['role_id']);
                    $table = null;
                    if ($role == 'student') {
                        $ret = $this->User->addStudent($userId, $courseId);
                    }
                    else if ($role == 'instructor') {
                        $ret = $this->User->addInstructor($userId, $courseId);
                    }
                    else if ($role == 'tutor') {
                        $ret = $this->User->addTutor($userId, $courseId);
                    }
                    else {
                        $this->set('statusCode',
                            'HTTP/1.1 501 Unsupported role for '.$user['username']);
                        break;
                    }
                    if (!$ret) {
                        $this->set('statusCode',
                            'HTTP/1.1 501 Fail to enrol ' . $user['username']);
                        break;
                    }
                }
            }
            $this->set('enrolment', $users);
            return;
        }
        else if ($this->RequestHandler->isDelete()) {
            $this->set('statusCode', 'HTTP/1.1 200 OK');
            $input = trim(file_get_contents('php://input'), true);
            $users = json_decode($input, true);
            foreach ($users as $user) {
                $userId = $this->User->field('id',
                    array('username' => $user['username']));
                $role = $this->Role->getRoleName($user['role_id']);
                $table = null;
                if ($role == 'student') {
                    $ret = $this->User->removeStudent($userId, $courseId);
                }
                else if ($role == 'instructor') {
                    $ret = $this->User->removeInstructor($userId, $courseId);
                }
                else if ($role == 'tutor') {
                    $ret = $this->User->removeTutor($userId, $courseId);
                }
                else {
                    $this->set('statusCode',
                        'HTTP/1.1 501 Unsupported role for '.$user['username']);
                    break;
                }
                if (!$ret) {
                    $this->set('statusCode',
                        'HTTP/1.1 501 Fail to drop ' . $user['username']);
                    break;
                }
            }
            $this->set('enrolment', $users);
            return;
        }
    }
}
