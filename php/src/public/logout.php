<?php
require __DIR__ . '/../app/bootstrap.php';

logout();
redirect(base_url('login.php'));
