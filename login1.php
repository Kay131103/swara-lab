<?php
include("./conn/conn.php");
if (isset($_SESSION["username"])) redirect("index.php");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login System with Email Verification</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@500&display=swap');

        * {
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: url("image.jpg");
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            height: 100vh;
        }

        .login-form, .registration-form {
            backdrop-filter: blur(100px);
            color: rgb(255, 255, 255);
            padding: 40px;
            width: 500px;
            border: 2px solid;
            border-radius: 10px;
        }
        .switch-form-link {
            text-decoration: underline;
            cursor: pointer;
            color: rgb(100, 100, 200);
        }
        .btn-secondary {
            color: #fff;
            background-color: #D5767C;
            border-color: #D5767C;
        }
        .form-control {
            display: block;
            width: 100%;
            height: calc(1.5em + .75rem + 2px);
            padding: .375rem .75rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            color:#F6BCA6;
            
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #F6BCA6;
            border-radius: .25rem;
            border-color: #F7B2AB;
            transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out;
}
/* Add this CSS to change the hover color */
.btn-secondary:hover {
    background-color: #FFB6C1; /* Peach Pink */
    border-color: #FFB6C1; /* Peach Pink */
}

/* Add this CSS to change the hover color of the form controls */
.form-control:hover {
    border-color: #FFB6C1; /* Peach Pink */
}
/* Add this CSS to change the color of form input when something is typed */
.form-control:focus {
    border-color: #FFB6C1; /* Peach Pink */
    box-shadow: 0 0 0 0.2rem rgba(255, 182, 193, 0.25); /* Peach Pink with opacity */
}
/* Add this CSS to change the hover color of the register button */
.btn-dark:hover {
    background-color: #FFB6C1; /* Peach Pink */
    border-color: #FFB6C1; /* Peach Pink */
}

/* Add this CSS to change the text color when something is typed */
.form-control:valid {
    color: #F4B0A3; /* Peach Pink */
}

/* Add this CSS to change the color of the placeholder text */
.form-control::placeholder {
    color: #F4B0A3; /* Peach Pink */
}



    </style>
</head>
<body>

    <div class="main">

        <?= render_flash() ?>

        <!-- Login Area -->

        <div class="login-container">

            <div class="login-form" id="loginForm">
                <h2 class="text-center">Welcome Back!</h2>
                <p class="text-center">Fill your login details.</p>
                <form action="./endpoint/login.php" method="POST">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <p>No Account? Register <span class="switch-form-link" onclick="showRegistrationForm()">Here.</span></p>
                    <button type="submit" class="btn btn-secondary login-btn form-control">Login</button>
                </form>
            </div>

        </div>

        <!-- Registration Area -->
        <div class="registration-form" id="registrationForm">
            <h2 class="text-center">Registration Form</h2>
            <p class="text-center">Fill in your personal details.</p>
            <form action="./endpoint/add-user.php" method="POST">
                <?= csrf_field() ?>
                <div class="form-group registration row">
                    <div class="col-6">
                        <label for="firstName">First Name:</label>
                        <input type="text" class="form-control" id="firstName" name="first_name" required>
                    </div>
                    <div class="col-6">
                        <label for="lastName">Last Name:</label>
                        <input type="text" class="form-control" id="lastName" name="last_name" required>
                    </div>
                </div>
                <div class="form-group registration row">
                    <div class="col-5">
                        <label for="contactNumber">Contact Number:</label>
                        <input type="tel" class="form-control" id="contactNumber" name="contact_number" maxlength="20">
                    </div>
                    <div class="col-7">
                        <label for="email">Email:</label>
                        <input type="email" class="form-control" id="email" name="email" autocomplete="email" required>
                    </div>
                </div>
                <div class="form-group registration">
                    <label for="registerUsername">Username:</label>
                    <input type="text" class="form-control" id="registerUsername" name="username" pattern="[A-Za-z0-9_.\-]{3,32}" title="3-32 letters, digits, dots, dashes or underscores" autocomplete="username" required>
                </div>
                <div class="form-group registration">
                    <label for="registerPassword">Password:</label>
                    <input type="password" class="form-control" id="registerPassword" name="password" minlength="8" autocomplete="new-password" required>
                </div>
                <p>Already have an account? Login <span class="switch-form-link" onclick="showLoginForm()">Here.</span></p>
                <button type="submit" class="btn btn-dark login-register form-control" name="register">Register</button>
            </form>

        </div>

    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const registrationForm = document.getElementById('registrationForm');

        function showRegistrationForm() {
            registrationForm.style.display = "";
            loginForm.style.display = "none";
        }

        function showLoginForm() {
            registrationForm.style.display = "none";
            loginForm.style.display = "";
        }

        // Reopen the registration form after a failed sign-up.
        <?php if (isset($_GET['register'])) : ?>
        showRegistrationForm();
        <?php else : ?>
        showLoginForm();
        <?php endif ?>
    </script>

</body>
</html>
