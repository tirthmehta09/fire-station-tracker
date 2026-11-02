<?php
require __DIR__ . '/inc/bootstrap.php';

start_session();
$_SESSION = [];
session_destroy();
redirect('index.php');
