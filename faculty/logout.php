<?php
/**
 * Ends the faculty session.
 */
require_once __DIR__ . '/../includes/auth.php';

unset($_SESSION['faculty_id'], $_SESSION['faculty_name']);
session_regenerate_id(true);

set_flash('success', 'You have been logged out of the faculty panel.');
redirect('faculty/login.php');
