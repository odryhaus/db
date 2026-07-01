<?php

require_once __DIR__ . '/bootstrap.php';

logout_user();
redirect_to('/login.php');
