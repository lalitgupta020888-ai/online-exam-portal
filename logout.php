<?php
/**
 * Ends the student session (the admin session, if any, is left untouched).
 */
require_once __DIR__ . '/includes/auth.php';

unset($_SESSION['student_id'], $_SESSION['student_name']);
session_regenerate_id(true);

set_flash('success', 'You have been logged out.');
redirect('login.php');
