<?php
/**
 * Ends the administrator session.
 */
require_once __DIR__ . '/../includes/auth.php';

unset($_SESSION['admin_id'], $_SESSION['admin_name']);
session_regenerate_id(true);

set_flash('success', 'You have been logged out of the admin panel.');
redirect('admin/login.php');
