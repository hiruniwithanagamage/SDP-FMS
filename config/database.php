<?php

// Global variable to store the database connection
$GLOBALS['db_connection'] = null;

// Function to set up the database connection
function setupConnection() {
    if (!isset($GLOBALS['db_connection'])) {
        $GLOBALS['db_connection'] = new mysqli("localhost", "root", " ", "FMS", "3306");
    }
}

// Function for insert, update, delete operations
function iud($q) {
    setupConnection();
    $GLOBALS['db_connection']->query($q);
}

// Function for search operations
function search($q) {
    setupConnection();
    $resultset = $GLOBALS['db_connection']->query($q);
    return $resultset;
}

// Function for prepared statements
function prepare($q) {
    setupConnection();
    return $GLOBALS['db_connection']->prepare($q);
}

// Function to get the connection
function getConnection() {
    setupConnection();
    return $GLOBALS['db_connection'];
}

?>