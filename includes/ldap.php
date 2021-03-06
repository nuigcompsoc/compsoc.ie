<?php

    /* CompSoc Website Redesign - 2020
    * @author Conor Mc Govern & Shane Hastings
    * 
    * This script interfaces with our LDAP directory hosted on mona, this might be changed to interface with KeyCloak at some point
    */

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    require_once($_SERVER["DOCUMENT_ROOT"] . "/webservices.php");
    require_once($_SERVER["DOCUMENT_ROOT"] . "/includes/config.php");
    require_once($_SERVER["DOCUMENT_ROOT"] . "/includes/functions.php");
    require_once($_SERVER["DOCUMENT_ROOT"] . "/vendor/autoload.php");

    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    // Connects to LDAP
    function bind() {
        $ds=ldap_connect(LDAP_URL)
            or die("Could not connect to {" . LDAP_URL . "}");
        $ldapbind=false;
        if(ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3))
            if(ldap_set_option($ds, LDAP_OPT_REFERRALS, 0))
            // Start TLS disabled until we can figure out how to import the ssl cert
                //if(ldap_start_tls($ds))
                    $ldapbind = @ldap_bind($ds, LDAP_ADMIN_USER, LDAP_ADMIN_PASSWORD);
        
        return $ds;
    }

    /*
     *  Returns the entry related to a student/staff ID
     */
    function searchByID($id) {
        $ds = bind();

        $filter = "(|(employeeNumber=$id))";
        $returnValues = array("givenName", 
                            "sn", 
                            "mail", 
                            "employeeNumber",
                            "uid",
                            "userpassword",
                            "dn"
                        );

        $sr = ldap_search($ds, BASE_DN, $filter, $returnValues);
        $values = ldap_get_entries($ds, $sr);
        ldap_close($ds);

        if ($values["count"] == 0) {
            return false;
        } else {
            $info = $values[0];
            $arr = array();

            if (!empty($info["employeenumber"][0])) $arr["ID"] = $info["employeenumber"][0];
            if (!empty($info["givenname"][0])) $arr["firstName"] = $info["givenname"][0];
            if (!empty($info["sn"][0])) $arr["lastName"] = $info["sn"][0];
            if (!empty($info["mail"][0])) $arr["email"] = $info["mail"][0];
            if (!empty($info["uid"][0])) $arr["uid"] = $info["uid"][0];
            if (!empty($info["userpassword"][0])) $arr["userpassword"] = $info["userpassword"][0];
            if (!empty($info["dn"][0])) $arr["dn"] = $info["dn"];
            
            return $arr;
        }
    }

    /*
     *  Returns the entry related to a particular UID
     */
    function searchByUsername($username) {
        $ds = bind();

        $filter = "(|(uid=$username))";
        $returnValues = array("givenName", 
                            "sn", 
                            "mail", 
                            "employeeNumber", 
                            "uid"
                        );

        $sr = ldap_search($ds, BASE_DN, $filter, $returnValues);
        $values = ldap_get_entries($ds, $sr);
        ldap_close($ds);

        if ($values["count"] == 0) {
            return false;
        } else {
            $info = $values[0];
            $arr = array();

            if (!empty($info["employeenumber"][0])) $arr["ID"] = $info["employeenumber"][0];
            if (!empty($info["givenname"][0])) $arr["firstName"] = $info["givenname"][0];
            if (!empty($info["sn"][0])) $arr["lastName"] = $info["sn"][0];
            if (!empty($info["mail"][0])) $arr["email"] = $info["mail"][0];
            if (!empty($info["uid"][0])) $arr["uid"] = $info["uid"][0];
            
            return $arr;
        }
    }

    /*
     *  Returns all entries under BASE_DN
     */
    function searchAll() {
        $ds = bind();

        $filter = "uid=*";
        $returnValues = array("givenName", 
                            "sn", 
                            "mail", 
                            "employeeNumber", 
                            "uid", 
                            "uidNumber"
                        );
        
        $sr = ldap_search($ds, BASE_DN, $filter, $returnValues);
        $values = ldap_get_entries($ds, $sr);
        ldap_close($ds);

        unset($values["count"]);

        return $values;
    }

    /*
     * Takes in all entries in LDAP, inclusive of all groups, servers, everything.
     * Loops over all entries and returns the highest uidNumber + 1
     */
    function getNextUIDNumber() {
        $entries = searchAll();
        $highestUID = 0;

        foreach ($entries as $key => $e) {
            if($highestUID < $e["uidnumber"][0]) {
                $highestUID = $e["uidnumber"][0];
            }
        }
        
        $highestUID += 1;
        return $highestUID;
    }

    /*
     *  Add account says as it does on the tin. The only time this will fallover is if LDAP cannot
     *  be contacted or if socs.nuigalway.ie cannot be contacted. We're just taking the info from
     *  the socs website and popping it into LDAP. We're setting a lot of defaults here like gidNumber: 100
     *  but I don't know how we can get the info that someone is an admin (realistically none without
     *  forfiting some security). We're also emailing the user with all their info, might as well give them
     *  access to the account that they want!
     */
     function addAccount($username, $ID) {
        $ds = bind();
        $socsInfo = getSocietyMember($ID);
        $func = PASSWORD_GEN_FUNC_NAME;
        $password = $func(PASSWORD_GEN_FUNC_PARAM);
        $username = strtolower($username);

        // Password currently in cleartext, encrypt with SSHA
        $salt = substr(str_shuffle(str_repeat(SSHA_SALT_CHARACTERS,4)),0,4);
        $hashedPassword = '{SSHA}' . base64_encode(sha1($password . $salt, TRUE ) . $salt);

        $info["cn"][0] = $socsInfo["FirstName"] . " " . $socsInfo["LastName"];
        $info["givenname"][0] = $socsInfo["FirstName"];
        $info["sn"][0] = $socsInfo["LastName"];
        $info["employeenumber"][0] = $socsInfo["MemberID"];
        $info["mail"][0] = $socsInfo["Email"];
        if (!empty($socsInfo["PhoneNumber"])) $info["mobile"][0] = $socsInfo["PhoneNumber"];
        $info["uid"][0] = $username;
        $info["objectclass"][0] = "inetOrgPerson";
        $info["objectclass"][1] = "posixAccount";
        $info["objectclass"][2] = "top";
        $info["loginshell"][0] = "/bin/bash";
        $info["homedirectory"][0] = "/home/users/" . $username;
        $info["gidnumber"][0] = "100";
        $info["userPassword"][0] = $hashedPassword;
        $info["uidnumber"][0] = getNextUIDNumber();
        $info["gecos"][0] = $info["cn"][0];

        // Taking all info in $info and putting it into LDAP under uid=username,peopleDN
        $a = ldap_add($ds, "uid=" . $username . "," . PEOPLE_DN, $info);
        ldap_close($ds);

        // PHPMailer Object
        $mail = new PHPMailer(true);

        $mail->IsSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASSWORD;

        // From email address and name
        $mail->From = "accounts@compsoc.ie";
        $mail->FromName = "NUI Galway CompSoc Accounts";

        $mail->addAddress($socsInfo["Email"], $socsInfo["FirstName"] . " " . $socsInfo["LastName"]);
        $mail->addReplyTo("accounts@compsoc.ie", "Reply");
        $mail->addBCC("admin@compsoc.ie");

        $mail->isHTML(true);

        $mail->Subject = "Account Creation Request";

        $message = "<html><body><p>Hi " . $socsInfo["FirstName"] . ",<br><br>

        You have requested an account for the NUI Galway's Computer Society's server. If you have not requested this account, please <a href='mailto:support@compsoc.ie'>contact us</a> and we will happily undo this request.<br><br>

        Here are the details for your account and the information that we currently hold:<br>
        Name: " . $socsInfo["FirstName"] . " " . $socsInfo["LastName"] . "<br>
        Username: " . $username . "<br>
        Password: " . $password . " (We highly recommend you reset this password, visit our <a href='https://wiki.compsoc.ie'>wiki</a> to learn how)<br>
        Primary Email: " . $socsInfo["Email"] . "<br>
        CompSoc Email: " . $username . "@compsoc.ie<br>
        Student/Staff ID: " . $socsInfo["MemberID"] . "<br>";
        if (!empty($socsInfo["PhoneNumber"])) $message .= "Mobile: " . $socsInfo["PhoneNumber"] . "<br>";

        $message .= "<br>If you take issue with us holding any of the above information, please do not hesitate to <a href='mailto:support@compsoc.ie'>contact us</a>.<br><br>
        
        Kind Regards,<br>
        CompSoc Admins</body></html>";
        
        $mail->Body = $message;

        try {
            $mail->send();
            if ($a) return true;
        } catch (Exception $e) {
            //echo "Mailer Error: " . $mail->ErrorInfo;
            return false;
        }

        return false;
    }

    function checkUsername($username) {
        $username = strtolower($username);
        $json = json_decode(file_get_contents(UNUSUABLE_USERNAMES_FILENAME, true), true);

        foreach ($json["servers"] as $server) {
            if ($username == $server) return $server;
        }

        foreach ($json["names"] as $name) {
            if ($username == $name) return $name;
        }
        
        foreach ($json["cant-contain"] as $cantcontain) {
            if (strpos($username, $cantcontain) !== false) return $cantcontain;
        }

        return true;
    }
        
?>