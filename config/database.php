<?php

// Global variable to store the database connection
$GLOBALS['db_connection'] = null;

// Function to set up the database connection
function setupConnection() {
    // If connection doesn't exist or connection failed (is null)
    if (!isset($GLOBALS['db_connection']) || $GLOBALS['db_connection'] === null) {
        // Create the connection
        $GLOBALS['db_connection'] = new mysqli("localhost", "root", " ", "FMS", "3306");
        
        // Check if connection was successful
        if ($GLOBALS['db_connection']->connect_error) {
            // Log the error for debugging
            error_log("Database connection failed: " . $GLOBALS['db_connection']->connect_error);
            
            // Try once more with explicit error handling
            $GLOBALS['db_connection'] = null;
            $GLOBALS['db_connection'] = new mysqli("localhost", "root", " ", "FMS", "3306");
            
            // If still failing, throw exception
            if ($GLOBALS['db_connection']->connect_error) {
                throw new Exception("Failed to connect to database: " . $GLOBALS['db_connection']->connect_error);
            }
        }
    } else {
        // Check if the existing connection is still valid
        if (!$GLOBALS['db_connection']->ping()) {
            // Connection lost, reconnect
            $GLOBALS['db_connection']->close();
            $GLOBALS['db_connection'] = new mysqli("localhost", "root", " ", "FMS", "3306");
            
            // Check new connection
            if ($GLOBALS['db_connection']->connect_error) {
                throw new Exception("Failed to reconnect to database: " . $GLOBALS['db_connection']->connect_error);
            }
        }
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

// Call setupConnection once at the start to ensure it's available
try {
    setupConnection();
} catch (Exception $e) {
    // Log the error but don't display it to users
    error_log($e->getMessage());
}

?>