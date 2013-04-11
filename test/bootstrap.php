<?php

chdir(dirname(__FILE__));
include_once '../app/Mage.php';
Mage::app();

// Required to delete test products
Mage::register('isSecureArea', true);
