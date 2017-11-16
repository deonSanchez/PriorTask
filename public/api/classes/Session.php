<?php

/**
 * Session control
 *
 * @category   Class
 * @package    com.Prioritask.classes.Session
 * @author     Mitchell M. <mm11096@georgiasouthern.edu>
 * @version    Release: 1.0.0
 * @since      Class available since Release 1.0.0
 */
require_once __DIR__ . '/../config/global.php';

class Session {

    private static $self_instance;
    public $last_error;
    private $mysqli, $qb;

    /**
     * Constructs the class, setting the mysqli variable to the active connection
     * @author Mitchell M.
     * @version 1.1.0
     */
    public function __construct($dbc) {
        $this->mysqli = $dbc;
        $this->qb = QueryBuilder::getInstance();
        $this->last_error = "No recorded error..."; //Determines if the user has a session id set

        $this->sid = isset($_SESSION['sid']) ? $_SESSION['sid'] : null;
        if ($this->sid != null) {
            //Sets the current loggedIn status and validates any session in the browser
            $this->validate($this->sid, time());
        }
    }

    /**
     * Destructs the class
     * @author Mitchell M.
     * @version 0.7
     */
    public function __destruct() {
        
    }

    public static function getInstance($dbc) {
        if (!self::$self_instance) {
            self::$self_instance = new Session($dbc);
        }
        return self::$self_instance;
    }

    /**
     * Validates if a session is valid, and clears it if not
     * @param type $sid
     * @param type $currentTime
     * @return boolean
     */
    function validate($sid, $currentTime) {
        $sid = htmlentities(mysqli_real_escape_string($this->mysqli, $sid));
        $stmt = $this->mysqli->prepare("SELECT timestamp, userid FROM `sessions` WHERE `sid` = ?");
        $stmt->bind_param("s", $sid);
        $stmt->bind_result($timestamp, $uid);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows >= 1) {
            while ($stmt->fetch()) {
                //stored timestamp is the logintime + allowed session length
                //checking to see if we have passed that time
                if ($currentTime > $timestamp) {
                    $this->clear($sid);
                    return false;
                } else {
                    return true;
                }
            }
        } else {
            if (isset($_SESSION['sid'])) {
                $this->clear($sid);
            }
        }
        $stmt->close();
    }

    /**
     * Registers the user into the database
     * @author Mitchell M.
     * @version 1.0
     */
    public function register($email, $password, $passwordconf) {
        $pass = md5($password);
        $passconf = md5($passwordconf);
        if (!$email)
            $errors[] = "Email is not defined!";
        if (!$pass)
            $errors[] = "Password is not defined!";
        if (!$passconf)
            $errors[] = "Password confirmation is not defined!";
        if (filter_var($email, FILTER_VALIDATE_EMAIL) == false)
            $errors[] = "Email address is invalid!";
        if ($passconf != $pass)
            $errors[] = "The two passwords you entered do not match!";
        if ($email) {
            $stmt = $this->mysqli->prepare("SELECT * FROM `users` WHERE `email`= ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = "The e-mail address you supplied is already in use of another user!";
            }
            $stmt->close();
        }
        if (!isset($errors)) {
            //register the account
            $mysqli = $this->mysqli->prepare("INSERT INTO `users` (`email`, `password`) VALUES (?,?)");
            $mysqli->bind_param("ss", $email, $pass);
            $mysqli->execute();
            $mysqli->close();
            return true;
        } else {
            return json_encode($errors);
        }
    }

    /**
     * Sets a users session in the database and sets their client side session
     * @author Mitchell M. 
     * @version 1.0
     */
    function login($email, $pass) {
        $response = "Initial login state";
        //Does the user exist?
        if ($this->userExists($email, $pass)) {
            //User exists, get their userID for session creation
            $userid = $this->getUID($email);
            if ($this->handleSID($userid)) {
                return true;
            }
        } else {
            $response = false;
        }
        return json_encode($response);
    }

    /*
     * Validates that the login details are valid
     */

    function userExists($email, $password) {
        $email = htmlspecialchars(mysqli_real_escape_string($this->mysqli, $email));
        $pass = md5($password);
        $stmt = $this->mysqli->prepare("SELECT * FROM `users` WHERE `email` = ? AND `password` = ?");
        $stmt->bind_param("ss", $email, $pass);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            return true;
        }
        return false;
    }

    /*
     * Manages sessions and prevents more than one session per user
     */

    public function handleSID($userid) {
        //Does a session already exist for this userID?
        if ($this->exists($userid)) {
            //Session exists, clear it...
            if (!$this->clearByUID($userid)) {
                //Couldnt clear the session, return a json element containing the error
                return json_encode("Couldn't clear SID when creating new session.");
            }
        }
        //Creates the session with the specific userid
        if ($this->buildSID($userid)) {
            return true;
        }
        return false;
    }

    /**
     * Does a session exist for the UserID passed
     * @param type $userid
     * @return boolean
     */
    function exists($userid) {
        $qry = $this->qb->start();
        $qry->select("*")->from("sessions")->where("userid", "=", $userid);
        if ($qry->recordsExist()) {
            return true;
        }
        return false;
    }

    /**
     * Creates a session entry into the database and on the client machine
     * @param type $userid
     * @return int
     */
    function buildSID($userid) {
        $sid = $this->generateRandID(16);
        $timestamp = $this->buildExpireTime();

        $qry = $this->qb->start();
        $qry->insert_into("sessions", array('userid' => $userid, 'sid' => $sid, 'timestamp' => $timestamp));
        if ($qry->exec()) {
            $_SESSION['sid'] = $sid;
            return 1;
        }
        return 0;
    }

    function buildExpireTime() {
        return time() + 60 * SESSION_LENGTH;
    }

    /**
     * Is a user logged in?
     * @return type
     */
    function isLoggedIn() {
        return isset($_SESSION['sid']);
    }

    /**
     * Returns the UID based on email/sid input
     * Determines input type no specification required
     * @param type $input
     * @return type
     */
    function getUID($input) {
        $qry = $this->qb->start();
        $qry->select("userid");
        if (filter_var($input, FILTER_VALIDATE_EMAIL) == true) {
            $qry->from("users")
                    ->where("email", "=", $input);
            $result = $qry->get();
        } else {
            $qry->from("sessions")
                    ->where("sid", "=", $input);
            $result = $qry->get();
        }
        return isset($result[0]['userid']) ? $result[0]['userid'] : -1;
    }

    /**
     * Clear session based on UserID
     * @param type $userid
     * @return boolean
     */
    function clearByUID($userid) {
        if ($this->mysqli->query("DELETE FROM sessions WHERE userid='{$userid}'")) {
            return true;
        } else {
            return $this->mysqli->error;
        }
        unset($_SESSION['sid']);
    }

    /**
     * Clear session based on SID
     * @param type $sid
     */
    function clear($sid) {
        $sid = mysqli_real_escape_string($this->mysqli, $sid);
        $this->mysqli->query("DELETE FROM sessions WHERE sid='{$sid}'");
        unset($_SESSION['sid']);
    }

    /**
     * Validates and adds a new task to the database
     * @author Brett M.
     * @version 1.0
     */
    public function addTask($title, $due, $datetc, $hourtc, $minutetc, $location, $notes) {
        if ($this->isLoggedIn()) {
            //puts the est. completion time together
            $tc = $datetc . ":" . $hourtc . ":" . $minutetc;
            $uid = $this->getUid($this->sid);
            $stmt = $this->mysqli->prepare("INSERT INTO `task` "
                    . "(`userid`, `task_name`, `when_due`, `time_to_complete`, `notes`, `location`) "
                    . "VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $uid, $title, $due, $tc, $notes, $location);
            if ($stmt->execute()) {
                $stmt->close();
                return true;
            }
            return false;
        }
    }

    /**
     * Redirects the the specified location
     * @param string $location to redirect to
     */
    function redirect($location) {
        if (!headers_sent())
            header('Location: ' . $location);
        else {
            echo '<script type="text/javascript">';
            echo 'window.location.href="' . $location . '";';
            echo '</script>';
            echo '<noscript>';
            echo '<meta http-equiv="refresh" content="0;url=' . $location . '" />';
            echo '</noscript>';
        }
        die();
    }

    /**
     * Generates a random string based on the length provided
     * @param int $length to use
     */
    function generateRandID($length) {
        $randstr = "";
        for ($i = 0; $i < $length; $i++) {
            $randnum = mt_rand(0, 61);
            if ($randnum < 10) {
                $randstr .= chr($randnum + 48);
            } elseif ($randnum < 36) {
                $randstr .= chr($randnum + 55);
            } else {
                $randstr .= chr($randnum + 61);
            }
        }
        return $randstr;
    }

}

?>
