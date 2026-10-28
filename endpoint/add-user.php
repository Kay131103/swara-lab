<?php
include('../conn/conn.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require 'phpmailer/src/Exception.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';

const MAX_VERIFY_ATTEMPTS = 5;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../login1.php');
}
require_csrf();

function send_verification_email($config, $email, $name, $code)
{
    $smtp = $config['smtp'];
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtp['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['user'];
    $mail->Password   = $smtp['pass'];
    $mail->SMTPSecure = $smtp['secure'];
    $mail->Port       = $smtp['port'];

    $mail->setFrom($smtp['from_email'], $smtp['from_name']);
    $mail->addAddress($email, $name);

    $mail->isHTML(true);
    $mail->Subject = 'Your MeloMaster verification code';
    $mail->Body    = 'Hi ' . e($name) . ',<br><br>Your verification code is: <b>' . $code . '</b>';
    $mail->AltBody = "Your verification code is: $code";
    $mail->send();
}

// REGISTER
if (isset($_POST['register'])) {
    $firstName     = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
    $lastName      = trim(isset($_POST['last_name']) ? $_POST['last_name'] : '');
    $contactNumber = trim(isset($_POST['contact_number']) ? $_POST['contact_number'] : '');
    $email         = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $username      = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password      = isset($_POST['password']) ? $_POST['password'] : '';

    $errors = [];
    if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) $errors[] = 'Username must be 3-32 letters, digits, dots, dashes or underscores.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($contactNumber !== '' && !preg_match('/^\+?[0-9 ]{6,20}$/', $contactNumber)) $errors[] = 'Contact number is not valid.';

    if ($errors) {
        flash(implode(' ', $errors));
        redirect('../login1.php?register=1');
    }

    try {
        $stmt = $conn->prepare("SELECT `username`, `email`, `verified` FROM `tbl_user` WHERE `username` = :username OR `email` = :email");
        $stmt->execute(['username' => $username, 'email' => $email]);
        foreach ($stmt->fetchAll() as $existing) {
            if ($existing['verified']) {
                flash('That username or email is already registered.');
                redirect('../login1.php?register=1');
            }
        }
        // Clear out abandoned, never-verified sign-ups using the same name/email.
        $conn->prepare("DELETE FROM `tbl_user` WHERE (`username` = :username OR `email` = :email) AND `verified` = 0")
            ->execute(['username' => $username, 'email' => $email]);

        $verificationCode = random_int(100000, 999999);

        $conn->beginTransaction();
        $insert = $conn->prepare(
            "INSERT INTO `tbl_user` (`first_name`, `last_name`, `contact_number`, `email`, `username`, `password`, `verification_code`, `verified`)
             VALUES (:first_name, :last_name, :contact_number, :email, :username, :password, :verification_code, 0)"
        );
        $insert->execute([
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'contact_number'    => $contactNumber,
            'email'             => $email,
            'username'          => $username,
            'password'          => password_hash($password, PASSWORD_DEFAULT),
            'verification_code' => $verificationCode,
        ]);
        $userId = $conn->lastInsertId();

        if ($config['smtp']['enabled']) {
            send_verification_email($config, $email, $firstName, $verificationCode);
            $sentMessage = 'We sent a 6-digit code to ' . $email . '.';
        } else {
            $sentMessage = "Email sending is disabled in config.php (dev mode). Your code is $verificationCode.";
        }
        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Registration failed: ' . $e->getMessage());
        flash('Registration failed. Please try again.');
        redirect('../login1.php?register=1');
    } catch (MailException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Verification email failed: ' . $e->getMessage());
        flash('We could not send the verification email. Please check the address and try again.');
        redirect('../login1.php?register=1');
    }

    $_SESSION['user_verification_id'] = $userId;
    $_SESSION['verify_attempts'] = 0;
    flash($sentMessage, 'info');
    redirect('../verification.php');
}

// VERIFY
if (isset($_POST['verify'])) {
    // The user ID comes from the session only, never from the form, so nobody
    // can verify (or delete) someone else's account.
    if (empty($_SESSION['user_verification_id'])) {
        flash('Your verification session expired. Please register again.', 'warning');
        redirect('../login1.php?register=1');
    }
    $userId = $_SESSION['user_verification_id'];
    $code = trim(isset($_POST['verification_code']) ? $_POST['verification_code'] : '');

    $stmt = $conn->prepare("SELECT `verification_code` FROM `tbl_user` WHERE `tbl_user_id` = :id AND `verified` = 0");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if ($row && hash_equals((string) $row['verification_code'], $code)) {
        $conn->prepare("UPDATE `tbl_user` SET `verified` = 1 WHERE `tbl_user_id` = :id")->execute(['id' => $userId]);
        unset($_SESSION['user_verification_id'], $_SESSION['verify_attempts']);
        flash('Email verified - you can now log in.', 'success');
        redirect('../login1.php');
    }

    $_SESSION['verify_attempts']++;
    if (!$row || $_SESSION['verify_attempts'] >= MAX_VERIFY_ATTEMPTS) {
        $conn->prepare("DELETE FROM `tbl_user` WHERE `tbl_user_id` = :id AND `verified` = 0")->execute(['id' => $userId]);
        unset($_SESSION['user_verification_id'], $_SESSION['verify_attempts']);
        flash('Too many incorrect codes. Please register again.');
        redirect('../login1.php?register=1');
    }

    $left = MAX_VERIFY_ATTEMPTS - $_SESSION['verify_attempts'];
    flash("Incorrect code. $left attempt(s) left.");
    redirect('../verification.php');
}

redirect('../login1.php');
